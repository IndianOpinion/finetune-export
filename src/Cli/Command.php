<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Cli;

use IndianOpinion\FinetuneExport\Admin\AdminScreen;
use IndianOpinion\FinetuneExport\Estimate\Stats;
use IndianOpinion\FinetuneExport\FinetuneExportException;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Rest\RestController;
use IndianOpinion\FinetuneExport\Storage\Canary;
use IndianOpinion\FinetuneExport\UnsafeDirectory;

/**
 * Export published posts as fine-tuning JSONL (PRD section 5.7).
 *
 * Commands act as the user given with WP-CLI's global --user flag. Without
 * one, the shell is trusted like the file system it already reads. No
 * command prints the contents of an export file.
 */
final class Command
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * Lists, shows, imports or exports profiles.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, show, import or export.
     *
     * [<arg>]
     * : Profile id (show, export) or JSON file (import).
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function profiles(array $args, array $assoc): void
    {
        $action = $args[0] ?? 'list';
        $profiles = $this->plugin->profiles();
        try {
            switch ($action) {
                case 'list':
                    $rows = [];
                    foreach ($profiles->all() as $id => $profile) {
                        $rows[] = [
                            'id' => $id,
                            'name' => $profile->name(),
                            'shape' => $profile->shape(),
                            'default' => $id === $profiles->defaultId() ? 'yes' : '',
                        ];
                    }
                    \WP_CLI\Utils\format_items('table', $rows, ['id', 'name', 'shape', 'default']);
                    return;
                case 'show':
                case 'export':
                    \WP_CLI::line((string) wp_json_encode($profiles->get($args[1] ?? null)->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    return;
                case 'import':
                    $file = $args[1] ?? '';
                    if ($file === '' || !is_readable($file)) {
                        \WP_CLI::error('Give a readable JSON file.');
                    }
                    $json = file_get_contents($file);
                    $data = json_decode((string) $json, true);
                    if (!is_array($data)) {
                        \WP_CLI::error('The file is not a JSON object.');
                    }
                    $profile = Profile::fromArray($data);
                    (new \IndianOpinion\FinetuneExport\Content\WpContentSource())->validate($profile);
                    $profiles->save($profile, $this->canSensitive());
                    \WP_CLI::success('Imported profile ' . $profile->id() . '.');
                    return;
            }
        } catch (FinetuneExportException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::error('Unknown action. Use list, show, import or export.');
    }

    /**
     * Shows the first records of a profile. Writes no file.
     *
     * ## OPTIONS
     *
     * [--profile=<id>]
     * : Profile id. Defaults to the default profile.
     *
     * [--limit=<n>]
     * : Records to show, 1 to 50.
     * ---
     * default: 5
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function preview(array $args, array $assoc): void
    {
        $this->assertCan();
        try {
            $profile = $this->plugin->profiles()->get($assoc['profile'] ?? null);
            \IndianOpinion\FinetuneExport\Profiles::assertAllowed($profile, $this->canSensitive());
            $result = $this->plugin->exporter()->preview($profile, $this->plugin->settings()->previewSize((int) ($assoc['limit'] ?? 5)));
        } catch (FinetuneExportException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::line(AdminScreen::RESPONSIBILITY);
        foreach ($result->records as $record) {
            \WP_CLI::line('');
            \WP_CLI::line('Post ' . $record['post_id'] . ', estimated tokens (an estimate): ' . $record['tokens']);
            \WP_CLI::line($record['pretty']);
        }
        foreach ($result->skipped as $skip) {
            \WP_CLI::line('Skipped post ' . $skip['post_id'] . ': ' . $skip['reason']);
        }
        foreach ($result->notes as $note) {
            \WP_CLI::warning($note);
        }
    }

    /**
     * Counts records and estimated tokens over the whole selection. Writes no file.
     *
     * ## OPTIONS
     *
     * [--profile=<id>]
     * : Profile id.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function count(array $args, array $assoc): void
    {
        $this->assertCan();
        try {
            $profile = $this->plugin->profiles()->get($assoc['profile'] ?? null);
            \IndianOpinion\FinetuneExport\Profiles::assertAllowed($profile, $this->canSensitive());
            $job = $this->plugin->exporter()->start($profile, get_current_user_id(), Job::KIND_COUNT);
            $job = $this->plugin->exporter()->runForeground($job->id);
        } catch (FinetuneExportException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        $this->printStats($job);
    }

    /**
     * Starts an export job.
     *
     * ## OPTIONS
     *
     * [--profile=<id>]
     * : Profile id.
     *
     * [--foreground]
     * : Run every batch in this process instead of WP-Cron.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $this->assertCan();
        try {
            $profile = $this->plugin->profiles()->get(isset($assoc['profile']) ? (string) $assoc['profile'] : null);
            \IndianOpinion\FinetuneExport\Profiles::assertAllowed($profile, $this->canSensitive());
            \WP_CLI::line(AdminScreen::RESPONSIBILITY);
            if (!empty($assoc['foreground'])) {
                $job = $this->plugin->exporter()->start($profile, get_current_user_id(), Job::KIND_EXPORT);
                $job = $this->plugin->exporter()->runForeground($job->id, static function (Job $job): void {
                    \WP_CLI::log(sprintf('Job %d: %d posts processed.', $job->id, $job->counter('processed')));
                });
                if ($job->state !== Job::COMPLETE) {
                    \WP_CLI::error(sprintf('Job %d ended as %s: %s', $job->id, $job->state, (string) $job->error));
                }
                $this->printStats($job);
                \WP_CLI::success(sprintf('Job %d complete. Create a download link with: wp finetune-export link %d', $job->id, $job->id));
                return;
            }
            $job = $this->plugin->startJob($profile, get_current_user_id(), Job::KIND_EXPORT, $this->canSensitive());
        } catch (FinetuneExportException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::success(sprintf('Job %d queued. WP-Cron runs it in batches.', $job->id));
    }

    /**
     * Lists, shows, cancels or resumes jobs.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, show, cancel or resume.
     *
     * [<id>]
     * : Job id.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function jobs(array $args, array $assoc): void
    {
        $this->assertCan();
        $action = $args[0] ?? 'list';
        $id = (int) ($args[1] ?? 0);
        $exporter = $this->plugin->exporter();
        try {
            switch ($action) {
                case 'list':
                    $exporter->detectStalled();
                    $rows = [];
                    foreach ($this->plugin->jobs()->all(100) as $job) {
                        $rows[] = [
                            'id' => $job->id,
                            'kind' => $job->kind,
                            'state' => $job->state,
                            'profile' => (string) ($job->profile['id'] ?? ''),
                            'processed' => $job->counter('processed'),
                            'written' => $job->counter('written') + $job->counter('written_validation'),
                            'degraded' => $job->degraded() ? 'yes' : '',
                        ];
                    }
                    \WP_CLI\Utils\format_items('table', $rows, ['id', 'kind', 'state', 'profile', 'processed', 'written', 'degraded']);
                    return;
                case 'show':
                    $job = $this->plugin->jobs()->find($id);
                    if ($job === null) {
                        \WP_CLI::error('No such job.');
                        return;
                    }
                    $job = $exporter->checkMissing($job);
                    $data = RestController::describe($job);
                    $days = $this->plugin->settings()->retentionDays();
                    $data['retention'] = $days > 0 && $job->endedAt !== null
                        ? 'removed on ' . gmdate('Y-m-d', $job->endedAt + $days * 86400)
                        : 'kept until deleted';
                    $data['estimate_note'] = AdminScreen::ESTIMATE_LABEL;
                    \WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    return;
                case 'cancel':
                    $job = $exporter->cancel($id, get_current_user_id());
                    \WP_CLI::success('Job ' . $id . ' is ' . ($job->cancelRequested ? 'stopping after its current batch' : $job->state) . '.');
                    return;
                case 'resume':
                    $exporter->resume($id, get_current_user_id());
                    $this->plugin->scheduleStep($id);
                    \WP_CLI::success('Job ' . $id . ' queued again.');
                    return;
            }
        } catch (FinetuneExportException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::error('Unknown action. Use list, show, cancel or resume.');
    }

    /**
     * Creates a signed, expiring download link for a finished job.
     *
     * ## OPTIONS
     *
     * <job>
     * : Job id.
     *
     * [--user=<id>]
     * : User the link is for. Must hold finetune_export_run. Defaults to the acting user.
     *
     * [--ttl=<minutes>]
     * : Lifetime in minutes, 5 to the maximum (7 days by default).
     *
     * [--validation]
     * : Link the validation file instead of the training file.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function link(array $args, array $assoc): void
    {
        $userId = isset($assoc['user']) ? (int) $assoc['user'] : get_current_user_id();
        if ($userId <= 0) {
            \WP_CLI::error('Give --user=<id>: a link is bound to one signed-in user.');
        }
        if (!user_can($userId, Plugin::CAP_RUN)) {
            \WP_CLI::error('User ' . $userId . ' does not have the finetune_export_run capability.');
        }
        $ttl = $this->plugin->settings()->linkTtl(isset($assoc['ttl']) ? (int) $assoc['ttl'] : null);
        try {
            $link = $this->plugin->links()->issue(
                (int) ($args[0] ?? 0),
                $userId,
                $ttl,
                !empty($assoc['validation']) ? Job::FILE_VALIDATION : Job::FILE_TRAIN
            );
        } catch (FinetuneExportException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::line($link['url']);
        \WP_CLI::success('Expires ' . gmdate('Y-m-d H:i', $link['expires']) . ' UTC. Works only for user ' . $userId . ' while signed in.');
    }

    /**
     * Deletes a job's export files. The job record keeps the history.
     *
     * ## OPTIONS
     *
     * <job>
     * : Job id.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $assoc
     */
    public function delete(array $args, array $assoc): void
    {
        $this->assertCan();
        $id = (int) ($args[0] ?? 0);
        \WP_CLI::confirm('Delete the export files of job ' . $id . '?', $assoc);
        try {
            $job = $this->plugin->exporter()->removeFiles($id, 'operator', get_current_user_id());
        } catch (FinetuneExportException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        foreach ($job->files as $kind => $file) {
            \WP_CLI::line($kind . ': ' . (string) ($file['state'] ?? ''));
        }
        \WP_CLI::success('Done. The removal is recorded in the job history.');
    }

    /**
     * Shows the export directory, whether it is safe, and runs the self-check.
     *
     * @subcommand check-directory
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function checkDirectory(array $args, array $assoc): void
    {
        try {
            $dir = $this->plugin->storage()->ensure();
        } catch (UnsafeDirectory $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::line('Directory: ' . $dir->path . ' (from the ' . $dir->source . ')');
        \WP_CLI::line($dir->insideWebRoot ? 'Inside the web root (acknowledged).' : 'Outside the web root.');
        $result = $this->plugin->canary()->run();
        \WP_CLI::line('Self-check: ' . $result['result'] . ', ' . $result['detail']);
        if ($result['result'] === Canary::SERVED) {
            \WP_CLI::error('The directory is served over HTTP. Jobs and downloads are stopped.');
        }
        \WP_CLI::success('The directory is usable.');
    }

    private function printStats(Job $job): void
    {
        $stats = Stats::fromArray($job->stats)->summary();
        \WP_CLI::line(AdminScreen::ESTIMATE_LABEL);
        \WP_CLI::line(sprintf(
            'Records %d, estimated tokens total %d, mean %s, median %s, p95 %d, max %d.',
            $stats['records'],
            $stats['total'],
            (string) $stats['mean'],
            (string) $stats['median'],
            $stats['p95'],
            $stats['max']
        ));
        foreach ($job->skipped as $reason => $n) {
            \WP_CLI::line('Skipped ' . $reason . ': ' . $n);
        }
        foreach ($job->redaction as $type => $n) {
            \WP_CLI::line('Redacted ' . $type . ': ' . $n);
        }
        foreach ($job->notes as $note) {
            \WP_CLI::warning($note);
        }
    }

    private function assertCan(): void
    {
        $user = get_current_user_id();
        if ($user > 0 && !current_user_can(Plugin::CAP_RUN)) {
            \WP_CLI::error('The acting user lacks the finetune_export_run capability.');
        }
    }

    private function canSensitive(): bool
    {
        return get_current_user_id() === 0 || current_user_can(Plugin::CAP_SENSITIVE);
    }
}

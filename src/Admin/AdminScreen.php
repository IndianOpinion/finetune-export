<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Admin;

use IndianOpinion\FinetuneExport\Content\WpContentSource;
use IndianOpinion\FinetuneExport\Estimate\Stats;
use IndianOpinion\FinetuneExport\FinetuneExportException;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\PreviewResult;
use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Profiles;
use IndianOpinion\FinetuneExport\Storage\Canary;
use IndianOpinion\FinetuneExport\Storage\Storage;
use IndianOpinion\FinetuneExport\UnsafeDirectory;

/**
 * Tools > Fine-tune export (PRD section 5.2). Every form posts to
 * admin-post.php with its own nonce and a capability check; every value is
 * escaped on output.
 */
final class AdminScreen
{
    public const SLUG = 'finetune-export';
    public const RESPONSIBILITY = 'Redaction is best-effort. It will miss some personal data. The export, and what you do with it, is your responsibility under the data-protection law that applies to you.';
    public const ESTIMATE_LABEL = 'Estimated tokens. This is an estimate and can be off by 25 percent or more, most for non-Latin scripts.';
    public const AUTHOR_WARNING = 'The author field will read the name placeholder, because name redaction is on.';

    private const ACTIONS = [
        'save_profile', 'delete_profile', 'set_default', 'run', 'count', 'cancel', 'resume',
        'delete_files', 'delete_record', 'link', 'revoke', 'save_settings', 'canary',
    ];
    private const TABS = ['profiles' => 'Profiles', 'preview' => 'Preview', 'jobs' => 'Jobs', 'settings' => 'Settings'];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_notices', [$this, 'notices']);
        foreach (self::ACTIONS as $action) {
            add_action('admin_post_finetune_export_' . $action, function () use ($action): void {
                $this->handle($action);
            });
        }
    }

    public function menu(): void
    {
        add_management_page(
            __('Fine-tune export', 'finetune-export'),
            __('Fine-tune export', 'finetune-export'),
            Plugin::CAP_RUN,
            self::SLUG,
            [$this, 'render']
        );
    }

    /**
     * Error notice for capability holders while the directory is unsafe or
     * the canary was served (cases 64 and 68).
     */
    public function notices(): void
    {
        if (!current_user_can(Plugin::CAP_RUN)) {
            return;
        }
        $last = Canary::last();
        if ($last['result'] === Canary::SERVED && $this->plugin->blocked()) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__('Fine-tune export: the export directory is served over HTTP. Jobs and downloads are stopped until the self-check passes.', 'finetune-export')
            );
        }
    }

    /**
     * @param array<string, scalar> $args
     */
    public function url(string $tab = 'profiles', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $args), admin_url('tools.php'));
    }

    public function render(): void
    {
        if (!current_user_can(Plugin::CAP_RUN)) {
            wp_die(esc_html__('You are not allowed to use the fine-tune export.', 'finetune-export'), 403);
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'profiles';
        $tab = isset(self::TABS[$tab]) ? $tab : 'profiles';
        echo '<div class="wrap"><h1>' . esc_html__('Fine-tune export', 'finetune-export') . '</h1>';
        $this->flash();
        $this->banner();
        echo '<nav class="nav-tab-wrapper">';
        foreach (self::TABS as $key => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($this->url($key)),
                $key === $tab ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
        match ($tab) {
            'preview' => $this->previewTab(),
            'jobs' => $this->jobsTab(),
            'settings' => $this->settingsTab(),
            default => $this->profilesTab(),
        };
        echo '</div>';
    }

    /**
     * Directory state banner (section 11).
     */
    private function banner(): void
    {
        $last = Canary::last();
        try {
            $dir = $this->plugin->storage()->resolve();
            $writable = is_dir($dir->path) ? is_writable($dir->path) : is_writable(dirname($dir->base));
            $state = $dir->insideWebRoot
                ? __('inside the web root (acknowledged)', 'finetune-export')
                : __('outside the web root', 'finetune-export');
            printf(
                '<div class="notice notice-%s inline"><p><strong>%s</strong> <code>%s</code>, %s, %s. %s %s</p></div>',
                $dir->insideWebRoot ? 'warning' : 'info',
                esc_html__('Export directory:', 'finetune-export'),
                esc_html($dir->path),
                esc_html($state),
                $writable ? esc_html__('writable', 'finetune-export') : esc_html__('not writable', 'finetune-export'),
                esc_html__('Last self-check:', 'finetune-export'),
                esc_html($last['result'] . ($last['at'] > 0 ? ' (' . wp_date('Y-m-d H:i', $last['at']) . ')' : ''))
            );
        } catch (UnsafeDirectory $e) {
            printf(
                '<div class="notice notice-error inline"><p><strong>%s</strong> %s</p></div>',
                esc_html__('No export can run.', 'finetune-export'),
                esc_html($e->getMessage())
            );
        }
    }

    private function profilesTab(): void
    {
        $profiles = $this->plugin->profiles();
        $all = $profiles->all();
        $selected = isset($_GET['profile']) ? sanitize_key(wp_unslash((string) $_GET['profile'])) : $profiles->defaultId();
        $new = $selected === '__new';
        $profile = $all[$selected] ?? $all[$profiles->defaultId()];
        echo '<h2>' . esc_html__('Profiles', 'finetune-export') . '</h2><ul>';
        foreach ($all as $id => $item) {
            printf(
                '<li><a href="%s">%s</a> <code>%s</code>%s</li>',
                esc_url($this->url('profiles', ['profile' => $id])),
                esc_html($item->name()),
                esc_html($id),
                $id === $profiles->defaultId() ? ' ' . esc_html__('(default)', 'finetune-export') : ''
            );
        }
        printf('<li><a href="%s">%s</a></li></ul>', esc_url($this->url('profiles', ['profile' => '__new'])), esc_html__('Add a profile', 'finetune-export'));
        if ($new) {
            $data = Profile::defaults();
            $data['id'] = '';
            $data['name'] = '';
        } else {
            $data = $profile->toArray();
        }
        (new ProfileForm())->render($data, current_user_can(Plugin::CAP_SENSITIVE), $this->url('preview'));
        if (!$new) {
            echo '<hr><h2>' . esc_html__('Run', 'finetune-export') . '</h2>';
            echo '<p class="description"><strong>' . esc_html(self::RESPONSIBILITY) . '</strong></p>';
            if ($profile->authorWillBeRedacted()) {
                echo '<p class="notice notice-warning inline">' . esc_html(self::AUTHOR_WARNING) . '</p>';
            }
            foreach ($profile->redactionOff() as $type) {
                printf('<p class="notice notice-warning inline">%s</p>', esc_html(sprintf(__('Redaction of %s is off for this profile.', 'finetune-export'), $type)));
            }
            echo '<p>';
            $this->button('run', __('Run export', 'finetune-export'), ['profile' => $profile->id()], 'primary');
            $this->button('count', __('Count', 'finetune-export'), ['profile' => $profile->id()]);
            $this->button('set_default', __('Make default', 'finetune-export'), ['profile' => $profile->id()]);
            $this->button('delete_profile', __('Delete profile', 'finetune-export'), ['profile' => $profile->id()], 'delete');
            echo '</p>';
            $counts = get_option(\IndianOpinion\FinetuneExport\Settings::COUNTS_OPTION, []);
            if (is_array($counts) && isset($counts[$profile->id()]['stats']) && is_array($counts[$profile->id()]['stats'])) {
                echo '<h3>' . esc_html__('Last count', 'finetune-export') . '</h3>';
                $this->statsTable($counts[$profile->id()]['stats']);
                $this->skipTable(is_array($counts[$profile->id()]['skipped'] ?? null) ? $counts[$profile->id()]['skipped'] : []);
            }
        }
    }

    private function previewTab(): void
    {
        $result = null;
        $error = null;
        $profile = null;
        if (isset($_POST['finetune_export_preview'])) {
            check_admin_referer('finetune_export_preview', '_wpnonce_preview');
            try {
                $profile = !empty($_POST['from_saved'])
                    ? $this->plugin->profiles()->get(sanitize_key(wp_unslash((string) ($_POST['profile_id'] ?? ''))))
                    : Profile::fromArray(ProfileForm::fromPost());
                Profiles::assertAllowed($profile, current_user_can(Plugin::CAP_SENSITIVE));
                $size = isset($_POST['preview_size']) ? absint(wp_unslash($_POST['preview_size'])) : null;
                $result = $this->plugin->exporter()->preview($profile, $this->plugin->settings()->previewSize($size ?: null));
            } catch (FinetuneExportException $e) {
                $error = $e->getMessage();
            }
        }
        echo '<h2>' . esc_html__('Preview', 'finetune-export') . '</h2>';
        echo '<p>' . esc_html__('Preview builds the first records of a profile, after cleaning and redaction. It writes no file and starts no job. Use the Preview button on the Profiles tab to preview unsaved settings, or pick a saved profile here.', 'finetune-export') . '</p>';
        echo '<p><strong>' . esc_html(self::RESPONSIBILITY) . '</strong></p>';
        echo '<form method="post" action="' . esc_url($this->url('preview')) . '">';
        wp_nonce_field('finetune_export_preview', '_wpnonce_preview');
        echo '<input type="hidden" name="finetune_export_preview" value="1">';
        echo '<select name="profile_id">';
        foreach ($this->plugin->profiles()->all() as $id => $item) {
            printf('<option value="%s">%s</option>', esc_attr($id), esc_html($item->name()));
        }
        echo '</select> <input type="hidden" name="from_saved" value="1">';
        printf('<input type="number" min="1" max="50" name="preview_size" value="%d"> ', (int) $this->plugin->settings()->previewSize());
        submit_button(__('Preview', 'finetune-export'), 'secondary', 'submit', false);
        echo '</form>';
        if ($error !== null) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
        }
        if ($result instanceof PreviewResult && $profile instanceof Profile) {
            $this->renderPreview($result, $profile);
        }
    }

    private function renderPreview(PreviewResult $result, Profile $profile): void
    {
        if ($profile->authorWillBeRedacted()) {
            echo '<p class="notice notice-warning inline">' . esc_html(self::AUTHOR_WARNING) . '</p>';
        }
        foreach ($result->notes as $note) {
            echo '<p class="notice notice-warning inline">' . esc_html(sprintf(__('Note: %s', 'finetune-export'), $note)) . '</p>';
        }
        if ($result->records === []) {
            echo '<p>' . esc_html__('No post produced a record.', 'finetune-export') . '</p>';
        }
        foreach ($result->records as $index => $record) {
            printf('<h3>%s</h3>', esc_html(sprintf(__('Post %d', 'finetune-export'), $record['post_id'])));
            printf('<p>%s: %d</p>', esc_html(self::ESTIMATE_LABEL), (int) $record['tokens']);
            $parts = [];
            foreach ($record['counts'] as $type => $n) {
                $parts[] = $type . ' ' . (int) $n;
            }
            printf('<p>%s %s</p>', esc_html__('Replacements:', 'finetune-export'), esc_html($parts === [] ? 'none' : implode(', ', $parts)));
            // highlighted() escapes the JSON and adds only <mark> tags.
            echo '<pre style="white-space:pre-wrap;max-height:30em;overflow:auto">' . wp_kses($result->highlighted($index), ['mark' => []]) . '</pre>';
        }
        if ($result->skipped !== []) {
            echo '<h3>' . esc_html__('Posts passed over', 'finetune-export') . '</h3><ul>';
            foreach ($result->skipped as $skip) {
                printf('<li>%d: %s</li>', (int) $skip['post_id'], esc_html($skip['reason']));
            }
            echo '</ul>';
        }
    }

    private function jobsTab(): void
    {
        $exporter = $this->plugin->exporter();
        $exporter->detectStalled();
        $retention = $this->plugin->settings()->retentionDays();
        $known = [];
        $jobs = $this->plugin->jobs()->all(100);
        echo '<h2>' . esc_html__('Jobs', 'finetune-export') . '</h2>';
        if ($jobs === []) {
            echo '<p>' . esc_html__('No jobs yet.', 'finetune-export') . '</p>';
        }
        foreach ($jobs as $job) {
            $job = $exporter->checkMissing($job);
            foreach ($job->files as $file) {
                $known[] = (string) $file['name'];
                $known[] = (string) $file['name'] . '.part';
            }
            $this->renderJob($job, $retention);
        }
        try {
            $unknown = $this->plugin->storage()->unknownFiles($known);
        } catch (FinetuneExportException $e) {
            $unknown = [];
        }
        if ($unknown !== []) {
            echo '<h3>' . esc_html__('Not created by this plugin', 'finetune-export') . '</h3>';
            echo '<p>' . esc_html__('These files are in the export directory but no job records them. The plugin never removes them.', 'finetune-export') . '</p><ul>';
            foreach ($unknown as $name) {
                echo '<li><code>' . esc_html($name) . '</code></li>';
            }
            echo '</ul>';
        }
    }

    private function renderJob(Job $job, int $retention): void
    {
        $progress = $job->total > 0 ? sprintf('%d / %d', $job->counter('processed'), $job->total) : (string) $job->counter('processed');
        printf(
            '<div class="card" style="max-width:none"><h3>%s</h3><p>%s %s, %s %s, %s %s</p>',
            esc_html(sprintf(__('Job %1$d (%2$s)', 'finetune-export'), $job->id, $job->kind)),
            esc_html__('State:', 'finetune-export'),
            esc_html($job->state),
            esc_html__('profile', 'finetune-export'),
            esc_html((string) ($job->profile['id'] ?? '')),
            esc_html__('posts done:', 'finetune-export'),
            esc_html($progress)
        );
        if ($job->degraded()) {
            echo '<p class="notice notice-error inline">' . esc_html__('Redaction degraded: a rule failed and was disabled for this job. Review the file before use.', 'finetune-export') . '</p>';
        }
        foreach ($job->notes as $note) {
            echo '<p><code>' . esc_html($note) . '</code></p>';
        }
        if ($job->error !== null) {
            echo '<p class="notice notice-error inline">' . esc_html($job->error) . '</p>';
        }
        $counters = [];
        foreach ($job->counters as $name => $n) {
            $counters[] = $name . ' ' . $n;
        }
        echo '<p>' . esc_html__('Counters:', 'finetune-export') . ' ' . esc_html(implode(', ', $counters)) . '</p>';
        $this->skipTable($job->skipped);
        $report = [];
        foreach ($job->redaction as $type => $n) {
            $report[] = $type . ' ' . $n;
        }
        echo '<p>' . esc_html__('Redaction report (counts only):', 'finetune-export') . ' ' . esc_html($report === [] ? 'none' : implode(', ', $report)) . '</p>';
        if ($job->topRedacted !== []) {
            $top = [];
            foreach ($job->topRedacted as $postId => $n) {
                $top[] = '#' . $postId . ' (' . $n . ')';
            }
            echo '<p>' . esc_html__('Posts with the most replacements:', 'finetune-export') . ' ' . esc_html(implode(', ', $top)) . '</p>';
        }
        $this->statsTable(Stats::fromArray($job->stats)->summary());

        foreach ($job->files as $kind => $file) {
            $state = (string) ($file['state'] ?? '');
            $line = $kind . ': ' . $state;
            if ($state === 'present') {
                $line .= ', ' . size_format((int) ($file['size'] ?? 0));
                $line .= ', ' . ($retention > 0 && $job->endedAt !== null
                    ? sprintf(__('removed on %s', 'finetune-export'), wp_date('Y-m-d', $job->endedAt + $retention * 86400))
                    : __('kept until deleted', 'finetune-export'));
            }
            if ($state === 'missing') {
                $line .= ', ' . sprintf(__('missing, not removed by this plugin (noticed %s)', 'finetune-export'), wp_date('Y-m-d H:i', (int) ($file['missing_since'] ?? 0)));
            }
            if ($state === 'removal_failed') {
                $line .= ', ' . __('removal failed, retried by the next retention run', 'finetune-export');
            }
            echo '<p>' . esc_html($line) . '</p>';
            if ($job->downloadable($kind)) {
                $this->button('link', sprintf(__('Create %s download link', 'finetune-export'), $kind), ['job' => $job->id, 'kind' => $kind]);
            }
        }
        echo '<p>';
        if (in_array($job->state, [Job::QUEUED, Job::RUNNING, Job::STALLED], true)) {
            $this->button('cancel', __('Cancel', 'finetune-export'), ['job' => $job->id]);
        }
        if ($job->state === Job::STALLED) {
            $this->button('resume', __('Resume', 'finetune-export'), ['job' => $job->id]);
        }
        if ($job->links !== []) {
            $this->button('revoke', __('Revoke all links', 'finetune-export'), ['job' => $job->id]);
        }
        $hasFiles = false;
        foreach ($job->files as $file) {
            $hasFiles = $hasFiles || in_array($file['state'] ?? '', ['part', 'present', 'removal_failed'], true);
        }
        if ($hasFiles && !in_array($job->state, [Job::QUEUED, Job::RUNNING], true)) {
            $this->button('delete_files', __('Delete files', 'finetune-export'), ['job' => $job->id], 'delete');
        }
        if (!$hasFiles && !in_array($job->state, [Job::QUEUED, Job::RUNNING], true)) {
            $this->button('delete_record', __('Delete job record', 'finetune-export'), ['job' => $job->id], 'delete');
        }
        echo '</p>';
        if ($job->downloads !== []) {
            echo '<details><summary>' . esc_html__('Download history', 'finetune-export') . '</summary><ul>';
            foreach (array_reverse($job->downloads) as $entry) {
                printf(
                    '<li>%s, user %d, %s%s</li>',
                    esc_html((string) wp_date('Y-m-d H:i', (int) ($entry['at'] ?? 0))),
                    (int) ($entry['user'] ?? 0),
                    esc_html((string) ($entry['outcome'] ?? '')),
                    isset($entry['ip']) ? ', ' . esc_html((string) $entry['ip']) : ''
                );
            }
            echo '</ul></details>';
        }
        echo '<details><summary>' . esc_html__('History', 'finetune-export') . '</summary><ul>';
        foreach (array_reverse($job->history) as $entry) {
            $parts = [];
            foreach ($entry as $key => $value) {
                if ($key !== 'at') {
                    $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : wp_json_encode($value));
                }
            }
            printf('<li>%s %s</li>', esc_html((string) wp_date('Y-m-d H:i', (int) ($entry['at'] ?? 0))), esc_html(implode(' ', $parts)));
        }
        echo '</ul></details></div>';
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function statsTable(array $stats): void
    {
        echo '<p><strong>' . esc_html(self::ESTIMATE_LABEL) . '</strong></p>';
        printf(
            '<p>%s</p>',
            esc_html(sprintf(
                __('Records %1$d, total %2$d, mean %3$s, median %4$s, 95th percentile %5$d, maximum %6$d.', 'finetune-export'),
                (int) ($stats['records'] ?? 0),
                (int) ($stats['total'] ?? 0),
                (string) ($stats['mean'] ?? 0),
                (string) ($stats['median'] ?? 0),
                (int) ($stats['p95'] ?? 0),
                (int) ($stats['max'] ?? 0)
            ))
        );
        if (!empty($stats['histogram']) && is_array($stats['histogram'])) {
            echo '<table class="widefat striped" style="max-width:30em"><thead><tr><th>' . esc_html__('Estimated tokens', 'finetune-export') . '</th><th>' . esc_html__('Records', 'finetune-export') . '</th></tr></thead><tbody>';
            foreach ($stats['histogram'] as $bucket) {
                printf('<tr><td>%d to %d</td><td>%d</td></tr>', (int) $bucket['from'], (int) $bucket['to'], (int) $bucket['count']);
            }
            echo '</tbody></table>';
        }
    }

    /**
     * @param array<string, int> $skipped
     */
    private function skipTable(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }
        $parts = [];
        foreach ($skipped as $reason => $n) {
            $parts[] = $reason . ' ' . (int) $n;
        }
        echo '<p>' . esc_html__('Skipped:', 'finetune-export') . ' ' . esc_html(implode(', ', $parts)) . '</p>';
    }

    private function settingsTab(): void
    {
        $settings = $this->plugin->settings();
        $values = $settings->all();
        $canSensitive = current_user_can(Plugin::CAP_SENSITIVE);
        echo '<h2>' . esc_html__('Settings', 'finetune-export') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('finetune_export_save_settings');
        echo '<input type="hidden" name="action" value="finetune_export_save_settings"><table class="form-table">';
        $this->numberRow('retention_days', __('Retention in days (0 keeps files until you delete them)', 'finetune-export'), (int) $values['retention_days'], 0, 3650, $settings->locked('retention_days'));
        $this->numberRow('link_ttl_minutes', __('Download link lifetime in minutes (5 to 10080)', 'finetune-export'), (int) $values['link_ttl_minutes'], 5, 10080, $settings->locked('link_ttl_minutes'));
        $this->numberRow('preview_size', __('Preview size (1 to 50)', 'finetune-export'), (int) $values['preview_size'], 1, 50, $settings->locked('preview_size'));
        printf(
            '<tr><th>%s</th><td><label><input type="checkbox" name="email_link" value="1" %s %s> %s</label></td></tr>',
            esc_html__('Email me the link', 'finetune-export'),
            checked((bool) $values['email_link'], true, false),
            disabled($settings->locked('email_link'), true, false),
            esc_html__('Also send each new link to my own account address.', 'finetune-export')
        );
        try {
            $candidate = null;
            $this->plugin->storage()->resolve();
        } catch (UnsafeDirectory $e) {
            $candidate = $e->getMessage();
        }
        $env = $this->plugin->storageEnvironment();
        $path = $env->constantDir ?? $env->filterDir ?? dirname(rtrim($env->abspath, '/')) . '/' . Storage::DEFAULT_NAME;
        printf(
            '<tr><th>%s</th><td><label><input type="checkbox" name="dir_ack" value="1" %s %s> %s</label><p class="description">%s <code>%s</code></p>%s</td></tr>',
            esc_html__('Directory inside the web root', 'finetune-export'),
            checked($settings->acknowledgedPath() === Storage::normalise($path), true, false),
            disabled(!$canSensitive, true, false),
            esc_html__('I understand this directory is inside the web root and I have blocked it in the web server.', 'finetune-export'),
            esc_html__('Applies to the path', 'finetune-export'),
            esc_html($path),
            $candidate !== null ? '<p class="description">' . esc_html($candidate) . '</p>' : ''
        );
        echo '</table>';
        submit_button(__('Save settings', 'finetune-export'));
        echo '</form>';
        echo '<h3>' . esc_html__('Self-check', 'finetune-export') . '</h3>';
        $last = Canary::last();
        printf('<p>%s %s</p>', esc_html($last['result']), esc_html($last['detail']));
        $this->button('canary', __('Run the self-check now', 'finetune-export'));
    }

    private function numberRow(string $name, string $label, int $value, int $min, int $max, bool $locked): void
    {
        printf(
            '<tr><th><label for="%1$s">%2$s</label></th><td><input type="number" id="%1$s" name="%1$s" value="%3$d" min="%4$d" max="%5$d" %6$s>%7$s</td></tr>',
            esc_attr($name),
            esc_html($label),
            $value,
            $min,
            $max,
            disabled($locked, true, false),
            $locked ? ' <span class="description">' . esc_html__('Set by a constant in wp-config.php.', 'finetune-export') . '</span>' : ''
        );
    }

    /**
     * @param array<string, scalar> $fields
     */
    private function button(string $action, string $label, array $fields = [], string $style = 'secondary'): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px">';
        wp_nonce_field('finetune_export_' . $action);
        printf('<input type="hidden" name="action" value="%s">', esc_attr('finetune_export_' . $action));
        foreach ($fields as $name => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($name), esc_attr((string) $value));
        }
        printf(
            '<button type="submit" class="button %s">%s</button></form>',
            $style === 'primary' ? 'button-primary' : ($style === 'delete' ? 'button-link-delete' : 'button-secondary'),
            esc_html($label)
        );
    }

    /**
     * admin-post.php handler for every action: nonce, capability, work,
     * message, redirect.
     */
    public function handle(string $action): void
    {
        wp_safe_redirect($this->process($action));
        exit;
    }

    /**
     * Runs one action and returns the URL to send the user back to.
     */
    public function process(string $action): string
    {
        check_admin_referer('finetune_export_' . $action);
        if (!current_user_can(Plugin::CAP_RUN)) {
            wp_die(esc_html__('You are not allowed to use the fine-tune export.', 'finetune-export'), 403);
        }
        $userId = get_current_user_id();
        $canSensitive = current_user_can(Plugin::CAP_SENSITIVE);
        $tab = 'jobs';
        $args = [];
        try {
            $message = $this->perform($action, $userId, $canSensitive, $tab, $args);
            $this->setFlash('success', $message);
        } catch (FinetuneExportException $e) {
            $this->setFlash('error', $e->getMessage());
        }
        return $this->url($tab, $args);
    }

    /**
     * @param array<string, string> $args
     */
    private function perform(string $action, int $userId, bool $canSensitive, string &$tab, array &$args): string
    {
        $exporter = $this->plugin->exporter();
        $jobId = isset($_POST['job']) ? absint(wp_unslash($_POST['job'])) : 0;
        $profileId = isset($_POST['profile']) && is_string($_POST['profile']) ? sanitize_key(wp_unslash($_POST['profile'])) : '';
        switch ($action) {
            case 'save_profile':
                $tab = 'profiles';
                $profile = Profile::fromArray(ProfileForm::fromPost());
                (new WpContentSource())->validate($profile);
                $this->plugin->profiles()->save($profile, $canSensitive);
                $args['profile'] = $profile->id();
                return __('Profile saved.', 'finetune-export');
            case 'delete_profile':
                $tab = 'profiles';
                $this->plugin->profiles()->delete($profileId);
                return __('Profile deleted.', 'finetune-export');
            case 'set_default':
                $tab = 'profiles';
                $this->plugin->profiles()->setDefault($profileId);
                $args['profile'] = $profileId;
                return __('Default profile set.', 'finetune-export');
            case 'run':
            case 'count':
                $job = $this->plugin->startJob(
                    $this->plugin->profiles()->get($profileId),
                    $userId,
                    $action === 'run' ? Job::KIND_EXPORT : Job::KIND_COUNT,
                    $canSensitive
                );
                return sprintf(__('Job %d queued.', 'finetune-export'), $job->id);
            case 'cancel':
                $this->assertOwnOrSensitive($jobId, $userId, $canSensitive);
                $exporter->cancel($jobId, $userId);
                return __('Cancel requested. The job stops after its current batch.', 'finetune-export');
            case 'resume':
                $this->assertOwnOrSensitive($jobId, $userId, $canSensitive);
                $exporter->resume($jobId, $userId);
                $this->plugin->scheduleStep($jobId);
                return __('Job resumed.', 'finetune-export');
            case 'delete_files':
                $this->assertOwnOrSensitive($jobId, $userId, $canSensitive);
                $exporter->removeFiles($jobId, 'operator', $userId);
                return __('Files deleted. The job record keeps the history.', 'finetune-export');
            case 'delete_record':
                $this->assertOwnOrSensitive($jobId, $userId, $canSensitive);
                $exporter->deleteRecord($jobId);
                return __('Job record deleted.', 'finetune-export');
            case 'link':
                $kind = isset($_POST['kind']) && $_POST['kind'] === Job::FILE_VALIDATION ? Job::FILE_VALIDATION : Job::FILE_TRAIN;
                $link = $this->plugin->links()->issue(
                    $jobId,
                    $userId,
                    $this->plugin->settings()->linkTtl(),
                    $kind,
                    (bool) $this->plugin->settings()->get('email_link')
                );
                set_transient('finetune_export_link_' . $userId, $link, 300);
                return __('Download link created. It works only for you, while signed in.', 'finetune-export');
            case 'revoke':
                $this->assertOwnOrSensitive($jobId, $userId, $canSensitive);
                $this->plugin->links()->revokeAll($jobId, $userId);
                return __('All links for this job are revoked.', 'finetune-export');
            case 'save_settings':
                $tab = 'settings';
                return $this->saveSettings($userId, $canSensitive);
            case 'canary':
                $tab = 'settings';
                $result = $this->plugin->canary()->run();
                return sprintf(__('Self-check: %s.', 'finetune-export'), $result['result']);
        }
        throw new FinetuneExportException('Unknown action.');
    }

    private function saveSettings(int $userId, bool $canSensitive): string
    {
        $settings = $this->plugin->settings();
        $changes = [];
        foreach (['retention_days', 'link_ttl_minutes', 'preview_size'] as $key) {
            if (isset($_POST[$key]) && !$settings->locked($key)) {
                $changes[$key] = absint(wp_unslash($_POST[$key]));
            }
        }
        if (!$settings->locked('email_link')) {
            $changes['email_link'] = !empty($_POST['email_link']);
        }
        if ($canSensitive) {
            $env = $this->plugin->storageEnvironment();
            $path = $env->constantDir ?? $env->filterDir ?? dirname(rtrim($env->abspath, '/')) . '/' . Storage::DEFAULT_NAME;
            $ack = !empty($_POST['dir_ack']) ? Storage::normalise($path) : null;
            if ($ack !== $settings->acknowledgedPath()) {
                $changes['dir_ack_path'] = $ack;
                $changes['dir_ack_by'] = $userId;
                $changes['dir_ack_at'] = $this->plugin->clock()->now();
            }
        }
        $settings->update($changes);
        $this->plugin->resetStorage();
        $result = $this->plugin->canary()->run();
        return sprintf(__('Settings saved. Self-check: %s.', 'finetune-export'), $result['result']);
    }

    private function assertOwnOrSensitive(int $jobId, int $userId, bool $canSensitive): void
    {
        $job = $this->plugin->jobs()->find($jobId);
        if ($job === null) {
            throw new FinetuneExportException('No such job.');
        }
        if ($job->createdBy !== $userId && !$canSensitive) {
            throw new \IndianOpinion\FinetuneExport\PermissionDenied('Only the user who started this job, or a holder of the sensitive capability, can change it.');
        }
    }

    private function setFlash(string $type, string $message): void
    {
        set_transient('finetune_export_flash_' . get_current_user_id(), ['type' => $type, 'message' => $message], 60);
    }

    private function flash(): void
    {
        $userId = get_current_user_id();
        $flash = get_transient('finetune_export_flash_' . $userId);
        if (is_array($flash)) {
            delete_transient('finetune_export_flash_' . $userId);
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                ($flash['type'] ?? '') === 'error' ? 'error' : 'success',
                esc_html((string) ($flash['message'] ?? ''))
            );
        }
        $link = get_transient('finetune_export_link_' . $userId);
        if (is_array($link) && isset($link['url'])) {
            delete_transient('finetune_export_link_' . $userId);
            printf(
                '<div class="notice notice-info"><p>%s <a href="%s">%s</a> (%s)</p></div>',
                esc_html__('Your download link, shown once:', 'finetune-export'),
                esc_url((string) $link['url']),
                esc_html__('Download', 'finetune-export'),
                esc_html(sprintf(__('expires %s', 'finetune-export'), wp_date('Y-m-d H:i', (int) $link['expires'])))
            );
        }
    }
}

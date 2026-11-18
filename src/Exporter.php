<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

use IndianOpinion\FinetuneExport\Content\ContentSource;
use IndianOpinion\FinetuneExport\Estimate\Stats;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Jobs\JobRepository;
use IndianOpinion\FinetuneExport\Jobs\Lock;
use IndianOpinion\FinetuneExport\Storage\Storage;
use IndianOpinion\FinetuneExport\Support\Clock;
use IndianOpinion\FinetuneExport\Support\Logger;

/**
 * Runs profiles: preview, count and export jobs in bounded, resumable
 * batches (PRD sections 4.10 to 4.12).
 */
final class Exporter
{
    public const BATCH_SIZE = 100;
    public const WEB_BUDGET_SECONDS = 20.0;
    public const STALL_SECONDS = 1800;
    /** A batch takes seconds; a lock older than this belongs to a dead batch (see ARCHITECTURE.md). */
    public const LOCK_TTL = 600;
    public const MIN_FREE_BYTES = 104857600;
    private const PREVIEW_SCAN_MAX = 2000;

    /** @var array<int, Pipeline> */
    private array $pipelines = [];
    /** @var callable(): bool */
    private $blocked;

    /**
     * @param (callable(): bool)|null $blocked True while the canary check has stopped jobs and downloads.
     */
    public function __construct(
        private readonly ContentSource $source,
        private readonly JobRepository $jobs,
        private readonly Storage $storage,
        private readonly Lock $lock,
        private readonly Cleaner $cleaner,
        private readonly Logger $logger,
        private readonly Clock $clock,
        ?callable $blocked = null,
    ) {
        $this->blocked = $blocked ?? static fn (): bool => false;
    }

    public function jobs(): JobRepository
    {
        return $this->jobs;
    }

    public function storage(): Storage
    {
        return $this->storage;
    }

    /**
     * Builds the first records of a profile without writing anything and
     * without creating a job (case 89).
     */
    public function preview(Profile $profile, int $limit = 5): PreviewResult
    {
        $this->source->validate($profile);
        $limit = max(1, min(Settings::PREVIEW_MAX, $limit));
        $pipeline = new Pipeline($profile, $this->source, $this->cleaner, $this->logger);
        $records = [];
        $skipped = [];
        $after = 0;
        $scanned = 0;
        while (count($records) < $limit && $scanned < self::PREVIEW_SCAN_MAX) {
            $ids = $this->source->nextIds($profile, $after, self::BATCH_SIZE);
            if ($ids === []) {
                break;
            }
            foreach ($ids as $id) {
                $after = $id;
                $scanned++;
                $outcome = $pipeline->process($id);
                if (!$outcome->written()) {
                    $skipped[] = ['post_id' => $id, 'reason' => (string) $outcome->skip];
                    continue;
                }
                $records[] = [
                    'post_id' => $id,
                    'line' => (string) $outcome->line,
                    'pretty' => (string) json_encode(
                        $outcome->record,
                        Pipeline::JSON_FLAGS | JSON_PRETTY_PRINT
                    ),
                    'tokens' => $outcome->tokens,
                    'counts' => $outcome->counts,
                ];
                if (count($records) >= $limit) {
                    break;
                }
            }
        }
        $placeholders = [];
        foreach (Profile::PLACEHOLDER_TYPES as $type) {
            $placeholders[] = $profile->placeholder($type);
        }
        return new PreviewResult($records, $skipped, $pipeline->notes(), $placeholders);
    }

    /**
     * Creates a queued job. Export jobs need a safe directory (case 64).
     */
    public function start(Profile $profile, int $userId, string $kind = Job::KIND_EXPORT): Job
    {
        $this->source->validate($profile);
        if ($kind !== Job::KIND_EXPORT && $kind !== Job::KIND_COUNT) {
            throw new \InvalidArgumentException('Unknown job kind ' . $kind);
        }
        if ($kind === Job::KIND_EXPORT) {
            $this->storage->ensure();
            if (($this->blocked)()) {
                throw new UnsafeDirectory(
                    'Jobs are stopped: the export directory answered over HTTP during the self-check.'
                    . ' Block it in the web server or move it, then run the check again.'
                );
            }
        }
        $now = $this->clock->now();
        $job = new Job();
        $job->kind = $kind;
        $job->profile = $profile->toArray();
        $job->createdBy = $userId;
        $job->createdAt = $now;
        $job->updatedAt = $now;
        $job->seed = bin2hex(random_bytes(8));
        $job->compress = $kind === Job::KIND_EXPORT && $profile->bool('compress');
        if ($job->compress && !function_exists('gzencode')) {
            throw new InvalidProfile('compress', 'compression needs the zlib extension');
        }
        foreach ($profile->redactionOff() as $type) {
            $job->note('redaction_off:' . $type);
        }
        foreach ($profile->sensitiveChoices() as $choice) {
            if (!str_starts_with($choice, 'redaction_off:')) {
                $job->note('sensitive:' . $choice);
            }
        }
        $job->total = $this->source->total($profile);
        $job->addHistory('created', $now, ['user' => $userId, 'kind' => $kind]);
        $this->jobs->insert($job);
        if ($kind === Job::KIND_EXPORT) {
            $job->random = Storage::newRandom();
            $job->files[Job::FILE_TRAIN] = ['name' => Storage::fileName($job->id, $job->random, Job::FILE_TRAIN, $job->compress, false), 'state' => 'pending', 'size' => 0];
            if ((int) $profile->get('validation_percent') > 0) {
                $job->files[Job::FILE_VALIDATION] = ['name' => Storage::fileName($job->id, $job->random, Job::FILE_VALIDATION, $job->compress, false), 'state' => 'pending', 'size' => 0];
            }
            $this->jobs->save($job);
        }
        if ($job->notes !== []) {
            foreach ($profile->redactionOff() as $type) {
                $this->logger->warning('redaction type off for job', ['job' => $job->id, 'type' => $type]);
            }
        }
        return $job;
    }

    /**
     * Runs one batch of a job. Returns the job as saved. Safe to call for any
     * job: queued jobs wait their turn, final jobs are returned unchanged.
     */
    public function step(int $jobId, ?float $budget = self::WEB_BUDGET_SECONDS): Job
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            throw new ExportFailed('unknown_job', 'No job with id ' . $jobId . '.');
        }
        if ($job->isFinal() || $job->state === Job::STALLED) {
            return $job;
        }
        $next = $this->jobs->inStates([Job::RUNNING, Job::QUEUED]);
        if ($next !== [] && $next[0]->id !== $job->id && $next[0]->state === Job::RUNNING) {
            return $job;
        }
        if ($job->state === Job::QUEUED && $next !== [] && $next[0]->id !== $job->id) {
            return $job;
        }
        if (!$this->lock->acquire($job->id, self::LOCK_TTL)) {
            return $job;
        }
        if ($job->cancelRequested) {
            return $this->finishCancel($job);
        }
        $now = $this->clock->now();
        if ($job->state === Job::QUEUED) {
            $job->startedAt = $job->startedAt ?? $now;
            $job->transition(Job::RUNNING, $now);
            $this->jobs->save($job);
            do_action('finetune_export_job_started', $job->id, $job->counters);
            $this->logger->info('job started', ['job' => $job->id, 'kind' => $job->kind]);
        }
        try {
            return $this->runBatch($job, $budget);
        } catch (\Throwable $e) {
            $reason = $e instanceof ExportFailed ? $e->reason : 'error';
            return $this->fail($job, $reason, $e->getMessage());
        }
    }

    /**
     * Runs every batch in this process (WP-CLI --foreground).
     */
    public function runForeground(int $jobId, ?callable $progress = null): Job
    {
        $job = $this->step($jobId, null);
        $guard = 0;
        while (!$job->isFinal() && $job->state !== Job::STALLED && $guard < 1000000) {
            $before = [$job->lastPostId, $job->state, $job->updatedAt];
            $job = $this->step($jobId, null);
            if ($progress !== null) {
                $progress($job);
            }
            if ([$job->lastPostId, $job->state, $job->updatedAt] === $before && $job->state !== Job::RUNNING) {
                break;
            }
            $guard++;
        }
        return $job;
    }

    /**
     * Cancels a job (case 83): queued and stalled jobs at once, a running
     * job after its current batch.
     */
    public function cancel(int $jobId, int $userId = 0): Job
    {
        $job = $this->mustFind($jobId);
        if ($job->isFinal()) {
            return $job;
        }
        if ($job->state === Job::RUNNING && !$this->lock->acquire($job->id, self::LOCK_TTL)) {
            // A batch is running in another request: it stops when that batch ends.
            $job->cancelRequested = true;
            $job->addHistory('cancel_requested', $this->clock->now(), ['user' => $userId]);
            $this->jobs->save($job);
            return $job;
        }
        return $this->finishCancel($job);
    }

    /**
     * Puts a stalled job back in the queue (case 84). The next batch cuts
     * the file back to the last good offset.
     */
    public function resume(int $jobId, int $userId = 0): Job
    {
        $job = $this->mustFind($jobId);
        if ($job->state !== Job::STALLED) {
            throw new ExportFailed('not_stalled', 'Only a stalled job can be resumed.');
        }
        $job->transition(Job::QUEUED, $this->clock->now(), 'resume');
        $job->addHistory('resumed', $this->clock->now(), ['user' => $userId]);
        $this->jobs->save($job);
        return $job;
    }

    /**
     * Marks running jobs with no progress for 30 minutes as stalled and
     * frees their lock (case 84, failure table). Returns them.
     *
     * @return list<Job>
     */
    public function detectStalled(): array
    {
        $now = $this->clock->now();
        $out = [];
        foreach ($this->jobs->inStates([Job::RUNNING]) as $job) {
            if ($job->updatedAt > $now - self::STALL_SECONDS) {
                continue;
            }
            $job->transition(Job::STALLED, $now, 'no_progress');
            $this->jobs->save($job);
            $this->lock->release($job->id);
            $this->logger->warning('job stalled', ['job' => $job->id]);
            $out[] = $job;
        }
        return $out;
    }

    /**
     * Removes a job's files (case 94). $reason is `operator` or `retention`.
     * Every removal and every failed removal is recorded. Removing a file
     * revokes its links (case 99).
     */
    public function removeFiles(int $jobId, string $reason, int $userId = 0): Job
    {
        if ($reason !== 'operator' && $reason !== 'retention') {
            throw new \InvalidArgumentException('Removal reason must be operator or retention.');
        }
        $job = $this->mustFind($jobId);
        if ($job->state === Job::RUNNING || $job->state === Job::QUEUED) {
            throw new ExportFailed('job_active', 'Cancel the job before deleting its files.');
        }
        $now = $this->clock->now();
        foreach ($job->files as $kind => $file) {
            $state = (string) ($file['state'] ?? '');
            if (!in_array($state, ['part', 'present', 'removal_failed'], true)) {
                continue;
            }
            $names = [(string) $file['name'], (string) $file['name'] . '.part'];
            try {
                foreach ($names as $name) {
                    $this->storage->removeExportFile($name);
                }
            } catch (\Throwable $e) {
                $job->files[$kind]['state'] = 'removal_failed';
                $job->files[$kind]['removal_failed_at'] = $now;
                $job->addHistory('removal_failed', $now, [
                    'reason' => $reason, 'user' => $userId, 'file' => $kind, 'error' => $e->getMessage(),
                ]);
                $this->logger->error('file removal failed', ['job' => $job->id, 'file' => $kind, 'reason' => $reason]);
                continue;
            }
            $job->files[$kind]['state'] = 'removed';
            $job->files[$kind]['removed_at'] = $now;
            $entry = ['reason' => $reason, 'file' => $kind];
            if ($reason === 'operator') {
                $entry['user'] = $userId;
            }
            $job->addHistory('removed', $now, $entry);
            $this->logger->info('file removed', ['job' => $job->id, 'file' => $kind, 'reason' => $reason]);
            do_action('finetune_export_file_removed', $job->id, $reason);
        }
        if ($job->links !== []) {
            $job->links = [];
            $job->addHistory('links_revoked', $now, ['reason' => 'file_removed']);
        }
        $this->jobs->save($job);
        return $job;
    }

    /**
     * Daily retention (case 96). Does nothing when retention is 0 ("keep
     * until I delete"). Failed removals stay eligible and are retried.
     *
     * @return list<int> Ids of jobs whose files were touched.
     */
    public function applyRetention(int $days): array
    {
        if ($days <= 0) {
            return [];
        }
        $cutoff = $this->clock->now() - $days * 86400;
        $touched = [];
        foreach ($this->jobs->inStates(Job::FINAL_STATES) as $job) {
            if ($job->endedAt === null || $job->endedAt > $cutoff) {
                continue;
            }
            $hasFile = false;
            foreach ($job->files as $file) {
                if (in_array($file['state'] ?? '', ['part', 'present', 'removal_failed'], true)) {
                    $hasFile = true;
                }
            }
            if ($hasFile) {
                $this->removeFiles($job->id, 'retention');
                $touched[] = $job->id;
            }
        }
        return $touched;
    }

    /**
     * Notices files that vanished without this plugin (case 98).
     */
    public function checkMissing(Job $job): Job
    {
        $changed = false;
        foreach ($job->files as $kind => $file) {
            $state = (string) ($file['state'] ?? '');
            if (!in_array($state, ['part', 'present'], true)) {
                continue;
            }
            $name = (string) $file['name'] . ($state === 'part' ? '.part' : '');
            try {
                $exists = $this->storage->exists($name);
            } catch (UnsafeDirectory $e) {
                return $job;
            }
            if (!$exists) {
                $now = $this->clock->now();
                $job->files[$kind]['state'] = 'missing';
                $job->files[$kind]['missing_since'] = $now;
                $job->addHistory('missing', $now, ['file' => $kind]);
                $this->logger->warning('file missing, not removed by this plugin', ['job' => $job->id, 'file' => $kind]);
                $changed = true;
            }
        }
        if ($changed) {
            $job->links = [];
            $this->jobs->save($job);
        }
        return $job;
    }

    /**
     * Deletes a job record once it has no file left on disk (case 100).
     */
    public function deleteRecord(int $jobId): void
    {
        $job = $this->mustFind($jobId);
        foreach ($job->files as $file) {
            if (in_array($file['state'] ?? '', ['part', 'present', 'removal_failed'], true)) {
                throw new ExportFailed('files_remain', 'Delete the files of job ' . $jobId . ' before its record.');
            }
        }
        if ($job->state === Job::RUNNING || $job->state === Job::QUEUED) {
            throw new ExportFailed('job_active', 'Cancel the job before deleting its record.');
        }
        $this->jobs->delete($jobId);
    }

    private function runBatch(Job $job, ?float $budget): Job
    {
        $profile = Profile::fromArray($job->profile);
        $pipeline = $this->pipelines[$job->id] ??= new Pipeline($profile, $this->source, $this->cleaner, $this->logger);
        $started = microtime(true);
        $export = $job->kind === Job::KIND_EXPORT;
        $handles = [];

        if ($export) {
            if (($this->blocked)()) {
                throw new ExportFailed('directory_unsafe', 'The export directory answered over HTTP during the self-check.');
            }
            $this->storage->ensure();
            $written = array_sum($job->offsets);
            $free = $this->storage->freeSpace();
            if ($free !== null && $free < max(self::MIN_FREE_BYTES, 2 * $written)) {
                throw new ExportFailed('disk_space', 'Free disk space fell below the safety margin.');
            }
            foreach ($job->files as $kind => $file) {
                $handles[$kind] = $this->storage->openAt((string) $file['name'] . '.part', $job->offsets[$kind] ?? 0);
                $job->files[$kind]['state'] = 'part';
            }
        }

        try {
            $batch = (int) apply_filters('finetune_export_batch_size', self::BATCH_SIZE);
            $batch = max(1, min(5000, $batch));
            $limit = $profile->optionalInt('limit');
            $ids = $this->source->nextIds($profile, $job->lastPostId, $batch);
            $buffers = [Job::FILE_TRAIN => '', Job::FILE_VALIDATION => ''];
            $stats = Stats::fromArray($job->stats);
            $done = $ids === [];
            foreach ($ids as $id) {
                if ($limit !== null && $this->writtenRecords($job) >= $limit) {
                    $done = true;
                    break;
                }
                $this->processInto($job, $pipeline, $id, $buffers, $stats);
                $job->lastPostId = $id;
                if ($budget !== null && microtime(true) - $started > $budget) {
                    break;
                }
            }
            if ($limit !== null && $this->writtenRecords($job) >= $limit) {
                $done = true;
            }
            foreach ($pipeline->notes() as $note) {
                if (!$job->hasNote($note)) {
                    $job->note($note);
                    if ($note === 'redaction_degraded') {
                        $job->addHistory('degraded', $this->clock->now(), []);
                    }
                }
            }
            $job->stats = $stats->toArray();
            foreach ($handles as $kind => $handle) {
                if ($buffers[$kind] === '') {
                    continue;
                }
                $data = $job->compress ? (string) gzencode($buffers[$kind], 6) : $buffers[$kind];
                $job->offsets[$kind] = $this->storage->append($handle, $data);
            }
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }

        $job->updatedAt = $this->clock->now();
        // A cancel may have arrived while this batch ran (case 83).
        $fresh = $this->jobs->find($job->id);
        if ($fresh !== null && $fresh->cancelRequested) {
            $job->cancelRequested = true;
        }
        if ($done) {
            return $this->complete($job);
        }
        $this->jobs->save($job);
        if ($job->cancelRequested) {
            return $this->finishCancel($job);
        }
        $this->lock->release($job->id);
        return $job;
    }

    /**
     * @param array<string, string> $buffers
     */
    private function processInto(Job $job, Pipeline $pipeline, int $id, array &$buffers, Stats $stats): void
    {
        $job->count('processed');
        $outcome = $pipeline->process($id);
        if ($outcome->metaNotScalar > 0) {
            $job->count('meta_not_scalar', $outcome->metaNotScalar);
        }
        if (!$outcome->written()) {
            $job->skip((string) $outcome->skip);
            return;
        }
        if ($outcome->hash !== null) {
            $first = $this->jobs->firstWithHash($job->id, $outcome->hash, $id);
            if ($first !== $id) {
                $job->skip('duplicate');
                return;
            }
        }
        $stats->add($outcome->tokens);
        if ($outcome->truncated) {
            $job->count('truncated');
        }
        $total = 0;
        foreach ($outcome->counts as $type => $n) {
            $job->redaction[$type] = ($job->redaction[$type] ?? 0) + $n;
            $total += $n;
        }
        if ($total > 0) {
            $job->topRedacted[$id] = $total;
            arsort($job->topRedacted);
            $job->topRedacted = array_slice($job->topRedacted, 0, 10, true);
        }
        $kind = Job::FILE_TRAIN;
        $percent = (int) ($job->profile['validation_percent'] ?? 0);
        if ($percent > 0 && self::inValidation($job->seed, $id, $percent)) {
            $kind = Job::FILE_VALIDATION;
        }
        $job->count($kind === Job::FILE_TRAIN ? 'written' : 'written_validation');
        $buffers[$kind] .= $outcome->line . "\n";
    }

    /**
     * Stable split by a hash of the job seed and the post id (case 86).
     */
    public static function inValidation(string $seed, int $postId, int $percent): bool
    {
        $bucket = hexdec(substr(hash('sha256', $seed . ':' . $postId), 0, 8)) % 10000;
        return $bucket < $percent * 100;
    }

    private function writtenRecords(Job $job): int
    {
        return $job->counter('written') + $job->counter('written_validation');
    }

    private function complete(Job $job): Job
    {
        $now = $this->clock->now();
        if ($job->kind === Job::KIND_EXPORT) {
            foreach ($job->files as $kind => $file) {
                $name = (string) $file['name'];
                if (!$this->storage->exists($name . '.part')) {
                    $this->storage->openAt($name . '.part', 0);
                }
                $this->storage->finalise($name . '.part', $name);
                $job->files[$kind]['state'] = 'present';
                $job->files[$kind]['size'] = $this->storage->size($name);
            }
        } else {
            $counts = get_option(Settings::COUNTS_OPTION, []);
            $counts = is_array($counts) ? $counts : [];
            $counts[(string) ($job->profile['id'] ?? 'default')] = [
                'job' => $job->id,
                'at' => $now,
                'stats' => Stats::fromArray($job->stats)->summary(),
                'skipped' => $job->skipped,
            ];
            update_option(Settings::COUNTS_OPTION, $counts, false);
        }
        $job->transition(Job::COMPLETE, $now);
        $this->jobs->save($job);
        $this->jobs->clearHashes($job->id);
        $this->lock->release($job->id);
        unset($this->pipelines[$job->id]);
        do_action('finetune_export_job_completed', $job->id, $job->counters);
        $this->logger->info('job complete', [
            'job' => $job->id,
            'written' => $this->writtenRecords($job),
            'degraded' => $job->degraded(),
        ]);
        return $job;
    }

    private function finishCancel(Job $job): Job
    {
        $now = $this->clock->now();
        $job->cancelRequested = false;
        $job->transition(Job::CANCELLED, $now);
        $this->jobs->save($job);
        $this->jobs->clearHashes($job->id);
        $this->lock->release($job->id);
        unset($this->pipelines[$job->id]);
        $this->logger->info('job cancelled', ['job' => $job->id]);
        return $job;
    }

    private function fail(Job $job, string $reason, string $message): Job
    {
        $now = $this->clock->now();
        $job->error = $reason . ': ' . $message;
        $job->transition(Job::FAILED, $now, $reason);
        $this->jobs->save($job);
        $this->lock->release($job->id);
        unset($this->pipelines[$job->id]);
        do_action('finetune_export_job_failed', $job->id, $job->counters);
        $this->logger->error('job failed', ['job' => $job->id, 'reason' => $reason]);
        return $job;
    }

    private function mustFind(int $jobId): Job
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            throw new ExportFailed('unknown_job', 'No job with id ' . $jobId . '.');
        }
        return $job;
    }
}

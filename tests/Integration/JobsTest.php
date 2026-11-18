<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Integration;

use IndianOpinion\FinetuneExport\Exporter;
use IndianOpinion\FinetuneExport\ExportFailed;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Jobs\OptionLock;
use IndianOpinion\FinetuneExport\Settings;
use IndianOpinion\FinetuneExport\Storage\Storage;
use IndianOpinion\FinetuneExport\Tests\TestCase;
use IndianOpinion\FinetuneExport\UnsafeDirectory;

final class JobsTest extends TestCase
{
    private function seed(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            \WpStub::addPost(['ID' => $i, 'post_title' => 'Post ' . $i, 'post_content' => '<p>Unique body number ' . $i . '.</p>']);
        }
    }

    /**
     * @return list<int>
     */
    private function ids(Job $job, string $kind = Job::FILE_TRAIN): array
    {
        return array_map(
            static fn (array $r): int => (int) preg_replace('/\D/', '', (string) $r['messages'][0]['content']),
            $this->lines($job, $kind)
        );
    }

    private function batches(int $size): void
    {
        add_filter('finetune_export_batch_size', static fn (): int => $size);
    }

    /** Test 73: editing the profile during a job does not change the job. */
    public function testProfileSnapshot(): void
    {
        $this->seed(2);
        $this->batches(1);
        $profiles = new \IndianOpinion\FinetuneExport\Profiles();
        $profiles->save($this->profile(), true);
        $exporter = $this->exporter();
        $job = $exporter->start($profiles->get('default'), 1);
        $exporter->step($job->id);
        $profiles->save($this->profile(['shape' => 'text']), true);
        $job = $exporter->runForeground($job->id);
        self::assertSame([1, 2], $this->ids($job));
        self::assertSame('chat', $job->profile['shape']);
    }

    /** Test 74: a job writes .part and renames on completion; .part is never downloadable. */
    public function testPartFileThenRename(): void
    {
        $this->seed(3);
        $this->batches(1);
        $storage = $this->storage();
        $exporter = $this->exporter($storage);
        $job = $exporter->step($exporter->start($this->profile(), 1)->id);
        $name = (string) $job->fileName('train');
        self::assertSame('part', $job->fileState('train'));
        self::assertTrue($storage->exists($name . '.part'));
        self::assertFalse($storage->exists($name));
        self::assertFalse($job->downloadable('train'));
        $job = $exporter->runForeground($job->id);
        self::assertSame('present', $job->fileState('train'));
        self::assertTrue($storage->exists($name));
        self::assertFalse($storage->exists($name . '.part'));
        self::assertTrue($job->downloadable('train'));
        self::assertSame(filesize($storage->path($name)), $job->files['train']['size']);
    }

    /** Test 75: a job killed mid-batch resumes with no duplicated or missing records. */
    public function testResumeAfterCrash(): void
    {
        $this->seed(7);
        \WpStub::addPost(['ID' => 8, 'post_title' => 'Post 8', 'post_content' => '<p>Unique body number 2.</p>']);
        $this->batches(3);
        $storage = $this->storage();
        $exporter = $this->exporter($storage);
        $job = $exporter->step($exporter->start($this->profile(), 1)->id);
        self::assertSame(3, $job->lastPostId);
        // The next batch dies after writing half a record and registering hashes, before saving.
        $part = $storage->path((string) $job->fileName('train') . '.part');
        file_put_contents($part, '{"messages":[{"role":"user","content":"Post 4"', FILE_APPEND);
        $this->jobs->firstWithHash($job->id, sha1('Unique body number 4.'), 4);
        // A fresh request (new exporter, as WP-Cron would run) resumes from the saved offset.
        $job = $this->exporter($storage)->runForeground($job->id);
        self::assertSame(Job::COMPLETE, $job->state);
        self::assertSame([1, 2, 3, 4, 5, 6, 7], $this->ids($job));
        self::assertSame(['duplicate' => 1], $job->skipped);
    }

    /** Test 76: a second job is queued while one runs, then runs after it. */
    public function testSecondJobQueued(): void
    {
        $this->seed(4);
        $this->batches(1);
        $exporter = $this->exporter();
        $first = $exporter->step($exporter->start($this->profile(), 1)->id);
        self::assertSame(Job::RUNNING, $first->state);
        $second = $exporter->start($this->profile(), 1);
        self::assertSame(Job::QUEUED, $exporter->step($second->id)->state);
        self::assertSame(Job::QUEUED, $exporter->runForeground($second->id)->state);
        $exporter->runForeground($first->id);
        self::assertSame(Job::COMPLETE, $exporter->runForeground($second->id)->state);
    }

    /** Test 77: a cancelled job stops after its batch and cannot be downloaded. */
    public function testCancel(): void
    {
        $this->seed(4);
        $this->batches(1);
        \WpStub::addUser(1, 'Admin', ['finetune_export_run']);
        $exporter = $this->exporter();
        $job = $exporter->step($exporter->start($this->profile(), 1)->id);
        // Another request is in the middle of a batch: the cancel waits for it.
        $other = new OptionLock($this->clock);
        self::assertTrue($other->acquire($job->id, Exporter::LOCK_TTL));
        $job = $exporter->cancel($job->id, 1);
        self::assertTrue($job->cancelRequested);
        self::assertSame(Job::RUNNING, $job->state);
        self::assertSame(Job::RUNNING, $exporter->step($job->id)->state, 'this request cannot run the batch the other holds');
        $other->release($job->id);
        $job = $exporter->step($job->id);
        self::assertSame(Job::CANCELLED, $job->state);
        self::assertSame(1, $job->counter('processed'));
        self::assertSame('part', $job->fileState('train'));
        self::assertFalse($job->downloadable('train'));
        $this->expectException(ExportFailed::class);
        $this->plugin()->links()->issue($job->id, 1, 3600);
    }

    public function testCancelQueuedIsImmediate(): void
    {
        $exporter = $this->exporter();
        $job = $exporter->cancel($exporter->start($this->profile(), 1)->id);
        self::assertSame(Job::CANCELLED, $job->state);
    }

    /** Test 78 and the failure table: no progress for 30 minutes marks the job stalled and frees the lock. */
    public function testStalledAndResume(): void
    {
        $this->seed(3);
        $this->batches(1);
        $exporter = $this->exporter();
        $job = $exporter->step($exporter->start($this->profile(), 1)->id);
        $this->clock->advance(29 * 60);
        self::assertSame([], $exporter->detectStalled());
        $this->clock->advance(2 * 60);
        $stalled = $exporter->detectStalled();
        self::assertSame([$job->id], array_map(static fn (Job $j): int => $j->id, $stalled));
        self::assertNull((new OptionLock($this->clock))->holder());
        self::assertSame(Job::STALLED, $exporter->step($job->id)->state);
        $exporter->resume($job->id, 1);
        $job = $exporter->runForeground($job->id);
        self::assertSame(Job::COMPLETE, $job->state);
        self::assertSame([1, 2, 3], $this->ids($job));
    }

    public function testExpiredLockFromDeadJobIsTaken(): void
    {
        $lock = new OptionLock($this->clock);
        self::assertTrue($lock->acquire(99, Exporter::LOCK_TTL));
        self::assertFalse($lock->acquire(5, Exporter::LOCK_TTL));
        $this->clock->advance(Exporter::LOCK_TTL + 1);
        self::assertTrue($lock->acquire(5, Exporter::LOCK_TTL));
        self::assertSame(5, $lock->holder());
    }

    /** Test 79: every state is reachable and each transition is recorded. */
    public function testStateTransitionsRecorded(): void
    {
        $this->seed(2);
        $this->batches(1);
        $exporter = $this->exporter();
        $complete = $exporter->runForeground($exporter->start($this->profile(), 1)->id);
        $transitions = array_values(array_filter($complete->history, static fn (array $h): bool => $h['event'] === 'state'));
        self::assertSame([['queued', 'running'], ['running', 'complete']], array_map(static fn (array $h): array => [$h['from'], $h['to']], $transitions));

        $stalled = $exporter->step($exporter->start($this->profile(), 1)->id);
        $this->clock->advance(3600);
        $exporter->detectStalled();
        $resumed = $exporter->resume($stalled->id);
        $cancelled = $exporter->cancel($resumed->id);
        $seen = array_map(
            static fn (array $h): string => $h['from'] . '>' . $h['to'],
            array_values(array_filter($cancelled->history, static fn (array $h): bool => $h['event'] === 'state'))
        );
        self::assertSame(['queued>running', 'running>stalled', 'stalled>queued', 'queued>cancelled'], $seen);

        $failing = $this->exporter($this->storage(null, static fn (): float => 1.0));
        $failed = $failing->step($failing->start($this->profile(), 1)->id);
        self::assertSame(Job::FAILED, $failed->state);
        self::assertSame(Job::STATES, array_values(array_intersect(Job::STATES, ['queued', 'running', 'complete', 'cancelled', 'stalled', 'failed'])));
    }

    /** Test 80: the validation split is stable for a seed and near the requested share. */
    public function testValidationSplit(): void
    {
        $this->seed(400);
        $this->batches(100);
        $job = $this->runExport($this->profile(['validation_percent' => 20]));
        $validation = $job->counter('written_validation');
        self::assertSame(400, $job->counter('written') + $validation);
        self::assertGreaterThan(50, $validation);
        self::assertLessThan(110, $validation);
        foreach ($this->ids($job, Job::FILE_VALIDATION) as $id) {
            self::assertTrue(Exporter::inValidation($job->seed, $id, 20));
        }
        foreach ($this->ids($job) as $id) {
            self::assertFalse(Exporter::inValidation($job->seed, $id, 20));
        }
        self::assertSame(Exporter::inValidation('abc', 17, 20), Exporter::inValidation('abc', 17, 20));
    }

    /** Test 81: compressed output decompresses to the same lines. */
    public function testCompression(): void
    {
        $this->seed(5);
        $this->batches(2);
        $plain = $this->runExport($this->profile());
        $gz = $this->runExport($this->profile(['compress' => true]));
        self::assertStringEndsWith('.jsonl.gz', (string) $gz->fileName('train'));
        self::assertSame("\x1f\x8b", substr((string) file_get_contents($this->storage()->path((string) $gz->fileName('train'))), 0, 2));
        self::assertSame($this->lines($plain), $this->lines($gz));
    }

    /** Test 82: low free space fails the job with disk_space and keeps its output. */
    public function testDiskSpace(): void
    {
        $this->seed(3);
        $this->batches(1);
        $free = 500 * 1048576.0;
        $storage = $this->storage(null, static function () use (&$free): float {
            return $free;
        });
        $exporter = $this->exporter($storage);
        $job = $exporter->step($exporter->start($this->profile(), 1)->id);
        $free = 50 * 1048576.0;
        $job = $exporter->step($job->id);
        self::assertSame(Job::FAILED, $job->state);
        self::assertStringStartsWith('disk_space', (string) $job->error);
        self::assertSame('part', $job->fileState('train'));
        self::assertGreaterThan(0, filesize($storage->path((string) $job->fileName('train') . '.part')));
        self::assertCount(1, $this->actions('finetune_export_job_failed'));
    }

    /** Test 86: count reports statistics without writing an export file. */
    public function testCount(): void
    {
        $this->seed(3);
        $exporter = $this->exporter();
        $job = $exporter->runForeground($exporter->start($this->profile(), 1, Job::KIND_COUNT)->id);
        self::assertSame(Job::COMPLETE, $job->state);
        self::assertSame([], $job->files);
        self::assertSame(3, array_sum($job->stats));
        self::assertFalse(is_dir($this->exportDir()));
        $counts = get_option(Settings::COUNTS_OPTION);
        self::assertSame(3, $counts['default']['stats']['records']);
    }

    /** Test 59 (jobs side) and the failure table: no safe directory means no export job. */
    public function testNoDirectoryNoJob(): void
    {
        $this->seed(1);
        $storage = $this->storage($this->env(WP_CONTENT_DIR . '/uploads/exports'));
        $exporter = $this->exporter($storage);
        try {
            $exporter->start($this->profile(), 1);
            self::fail('Expected UnsafeDirectory');
        } catch (UnsafeDirectory $e) {
            self::assertStringContainsString('FINETUNE_EXPORT_DIR', $e->getMessage());
        }
        self::assertSame([], $this->jobs->all());
        self::assertFileDoesNotExist(WP_CONTENT_DIR . '/uploads/exports');
    }

    /** Failure table: a served canary stops jobs. */
    public function testServedCanaryStopsJobs(): void
    {
        $this->seed(2);
        $this->batches(1);
        $blocked = false;
        $exporter = $this->exporter(null, static function () use (&$blocked): bool {
            return $blocked;
        });
        $job = $exporter->step($exporter->start($this->profile(), 1)->id);
        $blocked = true;
        $job = $exporter->step($job->id);
        self::assertSame(Job::FAILED, $job->state);
        self::assertStringStartsWith('directory_unsafe', (string) $job->error);
        $this->expectException(UnsafeDirectory::class);
        $exporter->start($this->profile(), 1);
    }

    /** Tests 88, 93 and 94: operator delete removes files, records it, revokes links; the record stays without post text. */
    public function testOperatorDelete(): void
    {
        $this->seed(2);
        $plugin = $this->plugin();
        \WpStub::addUser(1, 'Admin', ['finetune_export_run']);
        $exporter = $plugin->exporter();
        $job = $exporter->runForeground($exporter->start($this->profile(), 1)->id);
        $plugin->links()->issue($job->id, 1, 3600);
        self::assertCount(1, (array) $this->jobs->find($job->id)?->links);
        $job = $exporter->removeFiles($job->id, 'operator', 1);
        self::assertSame('removed', $job->fileState('train'));
        self::assertFalse($plugin->storage()->exists((string) $job->fileName('train')));
        self::assertSame([], $job->links);
        $removal = array_values(array_filter($job->history, static fn (array $h): bool => $h['event'] === 'removed'));
        self::assertSame([['event' => 'removed', 'at' => $this->clock->now, 'reason' => 'operator', 'file' => 'train', 'user' => 1]], $removal);
        self::assertSame([[$job->id, 'operator']], $this->actions('finetune_export_file_removed'));
        $record = (string) json_encode($this->jobs->find($job->id)?->toArray());
        self::assertStringNotContainsString('Unique body', $record);
        $exporter->deleteRecord($job->id);
        self::assertNull($this->jobs->find($job->id));
    }

    public function testRecordWithFilesCannotBeDeleted(): void
    {
        $this->seed(1);
        $exporter = $this->exporter();
        $job = $this->runExport($this->profile(), $exporter);
        $this->expectException(ExportFailed::class);
        $exporter->deleteRecord($job->id);
    }

    /** Tests 90 and 96 (retention): only files past the period, each recorded; failures retried. */
    public function testRetention(): void
    {
        $this->seed(1);
        $storage = $this->storage();
        $exporter = $this->exporter($storage);
        $old = $this->runExport($this->profile(), $exporter);
        $this->clock->advance(10 * 86400);
        $new = $this->runExport($this->profile(), $exporter);
        $this->clock->advance(5 * 86400);
        self::assertSame([], $exporter->applyRetention(0), 'retention 0 keeps everything');
        self::assertSame([$old->id], $exporter->applyRetention(14));
        self::assertSame('removed', $this->jobs->find($old->id)?->fileState('train'));
        self::assertSame('present', $this->jobs->find($new->id)?->fileState('train'));
        $events = array_column((array) $this->jobs->find($old->id)?->history, 'reason', 'event');
        self::assertSame('retention', $events['removed']);

        // A failed removal is recorded and retried next time.
        $path = $storage->path((string) $new->fileName('train'));
        $dir = dirname($path);
        $this->clock->advance(30 * 86400);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // Root ignores directory permissions; simulate the failure through a directory in the file's place.
            unlink($path);
            mkdir($path);
        } else {
            chmod($dir, 0500);
        }
        $exporter->applyRetention(14);
        $failed = $this->jobs->find($new->id);
        self::assertSame('removal_failed', $failed?->fileState('train'));
        self::assertSame('removal_failed', $failed?->history[count($failed->history) - 1]['event']);
        is_dir($path) ? rmdir($path) : chmod($dir, 0700);
        touch($path);
        $exporter->applyRetention(14);
        self::assertSame('removed', $this->jobs->find($new->id)?->fileState('train'));
    }

    /** Test 92: a file deleted outside the plugin shows as missing; the record stays. */
    public function testMissingFile(): void
    {
        $this->seed(1);
        $storage = $this->storage();
        $exporter = $this->exporter($storage);
        $job = $this->runExport($this->profile(), $exporter);
        unlink($storage->path((string) $job->fileName('train')));
        $job = $exporter->checkMissing($job);
        self::assertSame('missing', $job->fileState('train'));
        self::assertSame($this->clock->now, $job->files['train']['missing_since']);
        self::assertNotNull($this->jobs->find($job->id));
        self::assertSame([], $exporter->applyRetention(1));
    }
}

<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Integration;

use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Jobs\WpdbJobRepository;
use IndianOpinion\FinetuneExport\Tests\TestCase;

final class WpdbJobRepositoryTest extends TestCase
{
    public function testRoundTripAndQueries(): void
    {
        global $wpdb;
        $repo = new WpdbJobRepository($wpdb);
        self::assertStringContainsString('CREATE TABLE wp_finetune_export_jobs', $repo->schema()[0]);
        self::assertStringContainsString('PRIMARY KEY  (job_id,hash)', $repo->schema()[1]);

        $job = new Job();
        $job->profile = ['id' => 'default'];
        $job->stats = [12 => 3, 40 => 1];
        $job->skipped = ['duplicate' => 2];
        $job->createdBy = 4;
        $repo->insert($job);
        self::assertSame(1, $job->id);
        $job->transition(Job::RUNNING, 100);
        $repo->save($job);
        $second = $repo->insert(new Job());

        $found = $repo->find(1);
        self::assertNotNull($found);
        self::assertSame(Job::RUNNING, $found->state);
        self::assertSame([12 => 3, 40 => 1], $found->stats);
        self::assertSame(['duplicate' => 2], $found->skipped);
        self::assertSame(4, $found->createdBy);
        self::assertSame([2, 1], array_map(static fn (Job $j): int => $j->id, $repo->all()));
        self::assertSame([1], array_map(static fn (Job $j): int => $j->id, $repo->inStates([Job::RUNNING])));
        self::assertSame([2], array_map(static fn (Job $j): int => $j->id, $repo->inStates([Job::QUEUED])));

        self::assertSame(10, $repo->firstWithHash(1, sha1('a'), 10));
        self::assertSame(10, $repo->firstWithHash(1, sha1('a'), 11));
        self::assertSame(10, $repo->firstWithHash(1, sha1('a'), 10));
        self::assertSame(12, $repo->firstWithHash(2, sha1('a'), 12));
        $repo->clearHashes(1);
        self::assertSame(11, $repo->firstWithHash(1, sha1('a'), 11));

        $repo->delete($second->id);
        self::assertNull($repo->find($second->id));
    }
}

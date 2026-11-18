<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Jobs;

/**
 * One job at a time per site (case 82), with an expiry so a dead job
 * cannot hold the site forever (failure table).
 */
interface Lock
{
    /**
     * Takes or refreshes the lock for a job. True when this job holds it.
     */
    public function acquire(int $jobId, int $ttl): bool;

    public function release(int $jobId): void;

    /**
     * Job id holding a live lock, or null.
     */
    public function holder(): ?int;
}

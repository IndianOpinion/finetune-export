<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Jobs;

/**
 * Persistence for jobs and the per-job duplicate register (case 12).
 */
interface JobRepository
{
    /**
     * Stores a new job and sets its id.
     */
    public function insert(Job $job): Job;

    public function save(Job $job): void;

    public function find(int $id): ?Job;

    /**
     * Newest first.
     *
     * @return list<Job>
     */
    public function all(int $limit = 100): array;

    /**
     * @param list<string> $states
     * @return list<Job> Oldest first.
     */
    public function inStates(array $states): array;

    /**
     * Deletes a job record. Never touches files.
     */
    public function delete(int $id): void;

    /**
     * Records that $postId produced a target with this hash, unless the hash
     * is already known for the job. Returns the post id that first produced
     * the hash (equal to $postId when it is new). Re-running a batch after a
     * crash therefore never marks a post as a duplicate of itself.
     */
    public function firstWithHash(int $jobId, string $hash, int $postId): int;

    /**
     * Empties the duplicate register of a finished job.
     */
    public function clearHashes(int $jobId): void;
}

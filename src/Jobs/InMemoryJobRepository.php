<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Jobs;

/**
 * Job storage in memory, for tests and for preview runs.
 */
final class InMemoryJobRepository implements JobRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    /** @var array<int, array<string, int>> */
    private array $hashes = [];
    private int $next = 1;

    public function insert(Job $job): Job
    {
        $job->id = $this->next++;
        $this->rows[$job->id] = $job->toArray();
        return $job;
    }

    public function save(Job $job): void
    {
        if (!isset($this->rows[$job->id])) {
            throw new \RuntimeException('Unknown job ' . $job->id);
        }
        $this->rows[$job->id] = $job->toArray();
    }

    public function find(int $id): ?Job
    {
        return isset($this->rows[$id]) ? Job::fromArray($this->rows[$id]) : null;
    }

    public function all(int $limit = 100): array
    {
        $ids = array_keys($this->rows);
        rsort($ids);
        $out = [];
        foreach (array_slice($ids, 0, $limit) as $id) {
            $out[] = Job::fromArray($this->rows[$id]);
        }
        return $out;
    }

    public function inStates(array $states): array
    {
        $ids = array_keys($this->rows);
        sort($ids);
        $out = [];
        foreach ($ids as $id) {
            if (in_array($this->rows[$id]['state'], $states, true)) {
                $out[] = Job::fromArray($this->rows[$id]);
            }
        }
        return $out;
    }

    public function delete(int $id): void
    {
        unset($this->rows[$id], $this->hashes[$id]);
    }

    public function firstWithHash(int $jobId, string $hash, int $postId): int
    {
        if (!isset($this->hashes[$jobId][$hash])) {
            $this->hashes[$jobId][$hash] = $postId;
        }
        return $this->hashes[$jobId][$hash];
    }

    public function clearHashes(int $jobId): void
    {
        unset($this->hashes[$jobId]);
    }
}

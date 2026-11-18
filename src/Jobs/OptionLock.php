<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Jobs;

use IndianOpinion\FinetuneExport\Support\Clock;

/**
 * Batch lock kept in a non-autoloaded option. add_option is an INSERT that
 * fails when the row exists, so two requests cannot both create the lock.
 * Each lock instance has its own owner token: a second request for the same
 * job waits instead of running the same batch twice.
 */
final class OptionLock implements Lock
{
    public const OPTION = 'finetune_export_lock';

    private string $owner;

    public function __construct(private readonly Clock $clock)
    {
        $this->owner = bin2hex(random_bytes(8));
    }

    public function acquire(int $jobId, int $ttl): bool
    {
        $now = $this->clock->now();
        $value = ['job' => $jobId, 'owner' => $this->owner, 'expires' => $now + $ttl];
        if (add_option(self::OPTION, $value, '', false)) {
            return true;
        }
        $current = get_option(self::OPTION);
        $mine = is_array($current)
            && (int) ($current['job'] ?? 0) === $jobId
            && ($current['owner'] ?? '') === $this->owner;
        if (!is_array($current) || $mine || (int) ($current['expires'] ?? 0) < $now) {
            update_option(self::OPTION, $value, false);
            return true;
        }
        return false;
    }

    public function release(int $jobId): void
    {
        $current = get_option(self::OPTION);
        if (is_array($current) && (int) ($current['job'] ?? 0) === $jobId) {
            delete_option(self::OPTION);
        }
    }

    public function holder(): ?int
    {
        $current = get_option(self::OPTION);
        if (!is_array($current) || (int) ($current['expires'] ?? 0) < $this->clock->now()) {
            return null;
        }
        return (int) $current['job'];
    }
}

<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Jobs;

use IndianOpinion\FinetuneExport\Support\Clock;

/**
 * Batch lock kept in one row of the options table, written with SQL that is
 * atomic in MySQL: a plain INSERT, which fails on the unique option name when
 * the row exists, and a compare-and-swap UPDATE that only succeeds when the
 * stored value is still the one this request read.
 *
 * add_option() is not used: it sends INSERT ... ON DUPLICATE KEY UPDATE, so
 * two requests racing past its existence check both get a truthy result and
 * both think they hold the lock. update_option() has the same problem when
 * two requests take over an expired lock at once.
 *
 * Each lock instance has its own owner token: a second request for the same
 * job waits instead of running the same batch twice.
 */
final class OptionLock implements Lock
{
    public const OPTION = 'finetune_export_lock';

    private string $owner;

    public function __construct(private readonly Clock $clock, private readonly \wpdb $db)
    {
        $this->owner = bin2hex(random_bytes(8));
    }

    public function acquire(int $jobId, int $ttl): bool
    {
        $now = $this->clock->now();
        $value = (string) json_encode(['job' => $jobId, 'owner' => $this->owner, 'expires' => $now + $ttl]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $stored = $this->read();
            if ($stored === null) {
                $previous = $this->db->suppress_errors(true);
                $inserted = $this->db->insert(
                    $this->db->options,
                    ['option_name' => self::OPTION, 'option_value' => $value, 'autoload' => 'no'],
                    ['%s', '%s', '%s']
                );
                $this->db->suppress_errors($previous);
                $this->forgetCache();
                if ($inserted === 1) {
                    return $this->holds($jobId);
                }
                // Another request created the row first: read it again.
                continue;
            }
            $current = self::decode($stored);
            $mine = $current !== null && $current['job'] === $jobId && $current['owner'] === $this->owner;
            if ($current !== null && !$mine && $current['expires'] >= $now) {
                return false;
            }
            $this->db->update(
                $this->db->options,
                ['option_value' => $value],
                ['option_name' => self::OPTION, 'option_value' => $stored],
                ['%s'],
                ['%s', '%s']
            );
            $this->forgetCache();
            return $this->holds($jobId);
        }
        return false;
    }

    public function release(int $jobId): void
    {
        $stored = $this->read();
        $current = $stored === null ? null : self::decode($stored);
        if ($stored !== null && ($current === null || $current['job'] === $jobId)) {
            $this->db->delete($this->db->options, ['option_name' => self::OPTION, 'option_value' => $stored], ['%s', '%s']);
            $this->forgetCache();
        }
    }

    public function holder(): ?int
    {
        $stored = $this->read();
        $current = $stored === null ? null : self::decode($stored);
        if ($current === null || $current['expires'] < $this->clock->now()) {
            return null;
        }
        return $current['job'];
    }

    private function holds(int $jobId): bool
    {
        $stored = $this->read();
        $current = $stored === null ? null : self::decode($stored);
        return $current !== null && $current['job'] === $jobId && $current['owner'] === $this->owner;
    }

    /**
     * The stored value, read from the database, never from a cache.
     */
    private function read(): ?string
    {
        return $this->db->get_var((string) $this->db->prepare(
            'SELECT option_value FROM %i WHERE option_name = %s',
            $this->db->options,
            self::OPTION
        ));
    }

    /**
     * @return array{job: int, owner: string, expires: int}|null
     */
    private static function decode(string $stored): ?array
    {
        $data = json_decode($stored, true);
        if (!is_array($data) || !isset($data['job'], $data['owner'], $data['expires'])) {
            return null;
        }
        return ['job' => (int) $data['job'], 'owner' => (string) $data['owner'], 'expires' => (int) $data['expires']];
    }

    private function forgetCache(): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION, 'options');
        }
    }
}

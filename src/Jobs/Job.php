<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Jobs;

/**
 * One run of a profile (PRD section 4.10). Holds counters, statistics, the
 * redaction report, file state, link nonces and history. Never post text.
 */
final class Job
{
    public const KIND_EXPORT = 'export';
    public const KIND_COUNT = 'count';

    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const COMPLETE = 'complete';
    public const CANCELLED = 'cancelled';
    public const STALLED = 'stalled';
    public const FAILED = 'failed';
    public const STATES = [self::QUEUED, self::RUNNING, self::COMPLETE, self::CANCELLED, self::STALLED, self::FAILED];
    /** States after which no batch runs again. */
    public const FINAL_STATES = [self::COMPLETE, self::CANCELLED, self::FAILED];

    public const FILE_TRAIN = 'train';
    public const FILE_VALIDATION = 'validation';

    public const HISTORY_MAX = 500;
    public const DOWNLOADS_MAX = 200;

    public int $id = 0;
    public string $kind = self::KIND_EXPORT;
    public string $state = self::QUEUED;
    /** @var array<string, mixed> */
    public array $profile = [];
    public int $createdBy = 0;
    public int $createdAt = 0;
    public int $updatedAt = 0;
    public ?int $startedAt = null;
    public ?int $endedAt = null;
    public int $lastPostId = 0;
    /** @var array<string, int> byte offset of the last good batch per file kind */
    public array $offsets = [];
    /** @var array<string, int> */
    public array $counters = [];
    /** @var array<string, int> skip reason => count */
    public array $skipped = [];
    /** @var array<int, int> token estimate => records */
    public array $stats = [];
    /** @var array<string, int> redaction type => replacements */
    public array $redaction = [];
    /** @var array<int, int> post id => replacements, ten largest */
    public array $topRedacted = [];
    /** @var list<string> */
    public array $notes = [];
    public ?string $error = null;
    public bool $cancelRequested = false;
    public string $random = '';
    public string $seed = '';
    public bool $compress = false;
    public int $total = 0;
    /**
     * Per file kind: name, size, state (part, present, removed, missing),
     * removed_at, missing_since, removal_failed_at.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $files = [];
    /** @var array<string, array{user: int, expires: int, kind: string}> nonce => link data */
    public array $links = [];
    /** @var list<array<string, mixed>> */
    public array $history = [];
    /** @var list<array<string, mixed>> */
    public array $downloads = [];

    public function isFinal(): bool
    {
        return in_array($this->state, self::FINAL_STATES, true);
    }

    public function note(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    public function hasNote(string $note): bool
    {
        return in_array($note, $this->notes, true);
    }

    public function degraded(): bool
    {
        return $this->hasNote('redaction_degraded');
    }

    public function skip(string $reason, int $n = 1): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + $n;
    }

    public function count(string $counter, int $n = 1): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $n;
    }

    public function counter(string $counter): int
    {
        return $this->counters[$counter] ?? 0;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function addHistory(string $event, int $at, array $entry = []): void
    {
        $this->history[] = ['event' => $event, 'at' => $at] + $entry;
        if (count($this->history) > self::HISTORY_MAX) {
            $this->history = array_values(array_slice($this->history, -self::HISTORY_MAX));
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function addDownload(array $entry): void
    {
        $this->downloads[] = $entry;
        if (count($this->downloads) > self::DOWNLOADS_MAX) {
            $this->downloads = array_values(array_slice($this->downloads, -self::DOWNLOADS_MAX));
        }
    }

    /**
     * Moves to a new state and records the transition (case 85).
     */
    public function transition(string $state, int $at, ?string $reason = null): void
    {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('Unknown job state ' . $state);
        }
        if ($state === $this->state) {
            return;
        }
        $entry = ['from' => $this->state, 'to' => $state];
        if ($reason !== null) {
            $entry['reason'] = $reason;
        }
        $this->addHistory('state', $at, $entry);
        $this->state = $state;
        $this->updatedAt = $at;
        if (in_array($state, self::FINAL_STATES, true)) {
            $this->endedAt = $at;
        }
    }

    public function fileName(string $kind): ?string
    {
        $name = $this->files[$kind]['name'] ?? null;
        return is_string($name) ? $name : null;
    }

    public function fileState(string $kind): ?string
    {
        $state = $this->files[$kind]['state'] ?? null;
        return is_string($state) ? $state : null;
    }

    /**
     * True when a finished file of this kind exists for download.
     */
    public function downloadable(string $kind): bool
    {
        return $this->kind === self::KIND_EXPORT
            && $this->state === self::COMPLETE
            && $this->fileState($kind) === 'present';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $job = new self();
        foreach (get_object_vars($job) as $key => $default) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_int($default)) {
                $job->{$key} = (int) $value;
            } elseif (is_bool($default)) {
                $job->{$key} = (bool) $value;
            } elseif (is_array($default)) {
                $job->{$key} = is_array($value) ? $value : [];
            } elseif (is_string($default)) {
                $job->{$key} = (string) $value;
            } else {
                $job->{$key} = $value === null ? null : (is_numeric($value) ? (int) $value : (string) $value);
            }
        }
        return $job;
    }
}

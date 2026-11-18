<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Estimate;

/**
 * Token statistics for a job (PRD case 58). Values are kept as a map of
 * estimate => number of records, so memory depends on the number of
 * distinct estimates, not the number of posts, and the median and 95th
 * percentile stay exact.
 */
final class Stats
{
    /** @var array<int, int> */
    private array $values = [];

    /**
     * @param array<int|string, int> $values
     */
    public static function fromArray(array $values): self
    {
        $stats = new self();
        foreach ($values as $tokens => $n) {
            if ((int) $n > 0 && (int) $tokens >= 0) {
                $stats->values[(int) $tokens] = (int) $n;
            }
        }
        return $stats;
    }

    public function add(int $tokens): void
    {
        $this->values[$tokens] = ($this->values[$tokens] ?? 0) + 1;
    }

    /**
     * @return array<int, int>
     */
    public function toArray(): array
    {
        ksort($this->values);
        return $this->values;
    }

    public function records(): int
    {
        return array_sum($this->values);
    }

    public function total(): int
    {
        $sum = 0;
        foreach ($this->values as $tokens => $n) {
            $sum += $tokens * $n;
        }
        return $sum;
    }

    public function mean(): float
    {
        $records = $this->records();
        return $records === 0 ? 0.0 : $this->total() / $records;
    }

    public function max(): int
    {
        return $this->values === [] ? 0 : max(array_keys($this->values));
    }

    /**
     * Median: the mean of the two middle values for an even count.
     */
    public function median(): float
    {
        $records = $this->records();
        if ($records === 0) {
            return 0.0;
        }
        if ($records % 2 === 1) {
            return (float) $this->rank(intdiv($records, 2) + 1);
        }
        return ($this->rank(intdiv($records, 2)) + $this->rank(intdiv($records, 2) + 1)) / 2;
    }

    /**
     * Percentile by the nearest-rank method.
     */
    public function percentile(float $p): int
    {
        $records = $this->records();
        if ($records === 0) {
            return 0;
        }
        return $this->rank(max(1, (int) ceil($p / 100 * $records)));
    }

    /**
     * Ten equal-width buckets from 0 to the maximum. Each entry holds the
     * inclusive lower and upper bound and the count.
     *
     * @return list<array{from: int, to: int, count: int}>
     */
    public function histogram(int $buckets = 10): array
    {
        $max = $this->max();
        $width = max(1, (int) ceil(($max + 1) / $buckets));
        $counts = array_fill(0, $buckets, 0);
        foreach ($this->values as $tokens => $n) {
            $counts[min($buckets - 1, intdiv($tokens, $width))] += $n;
        }
        $out = [];
        foreach ($counts as $b => $count) {
            $out[] = ['from' => $b * $width, 'to' => ($b + 1) * $width - 1, 'count' => $count];
        }
        return $out;
    }

    /**
     * @return array{records: int, total: int, mean: float, median: float, p95: int, max: int,
     *     histogram: list<array{from: int, to: int, count: int}>}
     */
    public function summary(): array
    {
        return [
            'records' => $this->records(),
            'total' => $this->total(),
            'mean' => round($this->mean(), 1),
            'median' => $this->median(),
            'p95' => $this->percentile(95),
            'max' => $this->max(),
            'histogram' => $this->histogram(),
        ];
    }

    private function rank(int $rank): int
    {
        ksort($this->values);
        $seen = 0;
        foreach ($this->values as $tokens => $n) {
            $seen += $n;
            if ($seen >= $rank) {
                return $tokens;
            }
        }
        return $this->max();
    }
}

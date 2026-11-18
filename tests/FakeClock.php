<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests;

use IndianOpinion\FinetuneExport\Support\Clock;

final class FakeClock implements Clock
{
    public function __construct(public int $now = 1767225600)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}

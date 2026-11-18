<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Support;

/**
 * Source of the current time, replaceable in tests.
 */
interface Clock
{
    public function now(): int;
}

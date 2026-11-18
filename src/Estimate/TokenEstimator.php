<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Estimate;

/**
 * Estimates the model tokens in one record. Implementations must be local:
 * no network call, no download (PRD cases 54 and 57).
 */
interface TokenEstimator
{
    /**
     * @param array<string, mixed> $record The record exactly as it is written.
     * @param string               $shape  chat, prompt_completion or text.
     */
    public function estimate(array $record, string $shape): int;
}

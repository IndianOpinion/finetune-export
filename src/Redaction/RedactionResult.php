<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

/**
 * Text after redaction plus the number of replacements per type.
 */
final class RedactionResult
{
    /**
     * @param array<string, int> $counts
     */
    public function __construct(public readonly string $text, public readonly array $counts = [])
    {
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }
}

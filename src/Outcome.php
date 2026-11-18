<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * What happened to one post: a written line, or a skip reason.
 */
final class Outcome
{
    /**
     * @param array<string, mixed>|null $record
     * @param array<string, int>        $counts Redaction counts per type.
     */
    public function __construct(
        public readonly int $postId,
        public readonly ?string $skip = null,
        public readonly ?array $record = null,
        public readonly ?string $line = null,
        public readonly int $tokens = 0,
        public readonly array $counts = [],
        public readonly ?string $hash = null,
        public readonly bool $truncated = false,
        public readonly int $metaNotScalar = 0,
    ) {
    }

    public static function skipped(int $postId, string $reason, int $metaNotScalar = 0): self
    {
        return new self($postId, $reason, metaNotScalar: $metaNotScalar);
    }

    public function written(): bool
    {
        return $this->skip === null && $this->line !== null;
    }
}

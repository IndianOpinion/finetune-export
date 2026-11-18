<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Links;

/**
 * Result of verifying a download token.
 */
final class LinkCheck
{
    public const OK = 'ok';
    public const MALFORMED = 'malformed';
    public const BAD_SIGNATURE = 'bad_signature';
    public const WRONG_USER = 'wrong_user';
    public const EXPIRED = 'expired';

    public function __construct(
        public readonly string $status,
        public readonly int $jobId = 0,
        public readonly int $userId = 0,
        public readonly int $expires = 0,
        public readonly string $kind = '',
        public readonly string $nonce = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }
}

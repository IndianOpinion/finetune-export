<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Rest;

/**
 * The parts of a download request the handler looks at.
 */
final class DownloadRequest
{
    public function __construct(
        public readonly string $token,
        public readonly string $method = 'GET',
        public readonly ?string $origin = null,
        public readonly ?string $ip = null,
    ) {
    }
}

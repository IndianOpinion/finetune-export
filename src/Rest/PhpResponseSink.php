<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Rest;

/**
 * Writes the download straight to the client and exits.
 */
final class PhpResponseSink implements ResponseSink
{
    public function __construct()
    {
        while (ob_get_level() > 0) {
            if (!ob_end_clean()) {
                break;
            }
        }
    }

    public function status(int $code): void
    {
        http_response_code($code);
    }

    public function header(string $name, string $value): void
    {
        header($name . ': ' . $value, true);
    }

    public function removeHeader(string $name): void
    {
        header_remove($name);
    }

    public function write(string $chunk): void
    {
        echo $chunk; // phpcs:ignore -- raw file bytes, sent as an attachment.
        flush();
    }

    public function end(): void
    {
        exit;
    }
}

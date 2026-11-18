<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Rest;

/**
 * Where the download handler sends status, headers and body. The real sink
 * writes to PHP's output and ends the request; tests record.
 */
interface ResponseSink
{
    public function status(int $code): void;

    public function header(string $name, string $value): void;

    public function removeHeader(string $name): void;

    public function write(string $chunk): void;

    /**
     * Ends the request so the REST layer adds nothing (lesson 9).
     */
    public function end(): void;
}

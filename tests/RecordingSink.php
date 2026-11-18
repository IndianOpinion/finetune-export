<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests;

use IndianOpinion\FinetuneExport\Rest\ResponseSink;

final class RecordingSink implements ResponseSink
{
    public int $status = 0;
    /** @var array<string, string> */
    public array $headers = [];
    /** @var list<string> */
    public array $removed = [];
    /** @var list<string> */
    public array $chunks = [];
    public bool $ended = false;

    /**
     * @param array<string, string> $preset Headers an earlier layer already set.
     */
    public function __construct(array $preset = [])
    {
        $this->headers = $preset;
    }

    public function status(int $code): void
    {
        $this->status = $code;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function removeHeader(string $name): void
    {
        $this->removed[] = $name;
        unset($this->headers[$name]);
    }

    public function write(string $chunk): void
    {
        $this->chunks[] = $chunk;
    }

    public function end(): void
    {
        $this->ended = true;
    }

    public function body(): string
    {
        return implode('', $this->chunks);
    }
}

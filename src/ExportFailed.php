<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * A job could not continue. The reason is a short machine-readable code.
 */
final class ExportFailed extends FinetuneExportException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

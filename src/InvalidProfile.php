<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * A profile failed validation. The message names the field.
 */
final class InvalidProfile extends FinetuneExportException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($field . ': ' . $message);
    }
}

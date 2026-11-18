<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

/**
 * One redaction rule. Rules run in a fixed order (PRD case 49). A rule
 * replaces each span with $context->placeholder($type), which returns a
 * protected marker that later rules never match; the Redactor turns markers
 * into the visible placeholder text at the end.
 *
 * Throw RegexLimitExceeded when a regular expression fails: the record is
 * skipped. Any other exception disables the rule for the rest of the job.
 */
interface RedactionRule
{
    /**
     * Short identifier used in the report and logs, for example `email`.
     */
    public function type(): string;

    public function apply(string $text, RedactionContext $context): RedactionResult;
}

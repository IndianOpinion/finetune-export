<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

/**
 * Case 38: email addresses, including the common written-out forms
 * `name at example dot com`, `name [at] example [dot] com` and
 * `name(at)example.com`.
 */
final class EmailRule implements RedactionRule
{
    private const LOCAL = '(?<![\w.%+\-])[A-Za-z0-9](?:[A-Za-z0-9._%+\-]{0,62}[A-Za-z0-9_%+\-])?';
    private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?';
    private const TLD = '[A-Za-z]{2,24}(?![\w\-])';

    public function type(): string
    {
        return 'email';
    }

    public function apply(string $text, RedactionContext $context): RedactionResult
    {
        $at = '(?:@|\s*[\[\(\{]\s*(?i:at)\s*[\]\)\}]\s*)';
        $dot = '(?:\.|\s*[\[\(\{]\s*(?i:dot)\s*[\]\)\}]\s*|\s+(?i:dot)\s+)';
        $symbolForm = '/' . self::LOCAL . $at . '(?:' . self::LABEL . $dot . ')+' . self::TLD . '/u';
        // The bare word form needs the word "dot" too, so "look at example.com" is left alone.
        $wordForm = '/' . self::LOCAL . '\s+(?i:at)\s+(?:' . self::LABEL . '\s+(?i:dot)\s+)+' . self::TLD . '/u';
        $replace = static fn (array $m): string => $context->placeholder('email');
        $text = Redactor::replaceCallback($symbolForm, $replace, $text);
        $text = Redactor::replaceCallback($wordForm, $replace, $text);
        return new RedactionResult($text);
    }
}

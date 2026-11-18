<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

/**
 * Case 39: international numbers (a leading + and 8 to 15 digits) and
 * national numbers of 10 or 11 digits with space, dot, hyphen or bracket
 * separators. Years, dates, times, money with a currency sign, percentages,
 * ISBN and DOI forms are left alone.
 */
final class PhoneRule implements RedactionRule
{
    private const CURRENCY = '/(?:[$£€¥₹₨]|\b(?:Rs|INR|USD|GBP|EUR|Rupees?)\.?)\s*$/iu';
    private const IDENTIFIER = '/\b(?:ISBN(?:-1[03])?|DOI|doi\.org\/|ISSN|arXiv)\b[^\n]{0,12}$/iu';

    public function type(): string
    {
        return 'phone';
    }

    public function apply(string $text, RedactionContext $context): RedactionResult
    {
        $international = '/(?<![\w+])\+\d[\d \t().\-]{6,24}\d(?![\w\/])(?![.,]\d)/u';
        $text = Redactor::replaceCallback(
            $international,
            function (array $m) use ($context): string {
                $digits = strlen((string) preg_replace('/\D/', '', $m[0]));
                if ($digits < 8 || $digits > 15) {
                    return $m[0];
                }
                return $context->placeholder('phone');
            },
            $text
        );
        $national = '/(?<![\w+.\/\-])\(?\d[\d \t().\-]{8,20}\d(?![\w\/%])(?![.,]\d)(?!\s*%)/u';
        $text = $this->nationals($national, $text, $context);
        return new RedactionResult($text);
    }

    private function nationals(string $pattern, string $text, RedactionContext $context): string
    {
        $found = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        if ($found === false) {
            throw new \IndianOpinion\FinetuneExport\RegexLimitExceeded('Phone pattern failed: ' . preg_last_error_msg());
        }
        for ($i = count($matches[0]) - 1; $i >= 0; $i--) {
            [$candidate, $offset] = $matches[0][$i];
            if (!$this->looksLikePhone($candidate, substr($text, max(0, $offset - 24), min(24, $offset)))) {
                continue;
            }
            $text = substr_replace($text, $context->placeholder('phone'), $offset, strlen($candidate));
        }
        return $text;
    }

    private function looksLikePhone(string $candidate, string $before): bool
    {
        $digits = strlen((string) preg_replace('/\D/', '', $candidate));
        if ($digits < 10 || $digits > 11) {
            return false;
        }
        if (preg_match(self::CURRENCY, $before) === 1 || preg_match(self::IDENTIFIER, $before) === 1) {
            return false;
        }
        $groups = preg_split('/[^\d]+/', $candidate, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $years = array_filter($groups, static fn (string $g): bool => preg_match('/^(?:1[5-9]|20)\d\d$/', $g) === 1);
        if (count($years) === count($groups)) {
            return false;
        }
        // Dates written with separators: 2024-01-15 or 15.01.2024 or 15/01/2024 10:30.
        if (preg_match('/^\d{4}[.\-]\d{1,2}[.\-]\d{1,2}\b|\b\d{1,2}[.\-]\d{1,2}[.\-]\d{4}$/', trim($candidate)) === 1) {
            return false;
        }
        return true;
    }
}

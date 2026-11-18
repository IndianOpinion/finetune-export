<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

/**
 * Case 42: IPv4 and IPv6 addresses. IPv6 candidates are confirmed with
 * filter_var, so clock times such as 10:30:00 are not touched.
 */
final class IpRule implements RedactionRule
{
    private const OCTET = '(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)';

    public function type(): string
    {
        return 'ip';
    }

    public function apply(string $text, RedactionContext $context): RedactionResult
    {
        $v4 = '/(?<![\d.])' . self::OCTET . '(?:\.' . self::OCTET . '){3}(?!\.?\d)/';
        $text = Redactor::replaceCallback($v4, static fn (array $m): string => $context->placeholder('ip'), $text);
        $v6 = '/(?<![\w:.])(?:[0-9A-Fa-f]{0,4}:){2,7}(?:[0-9A-Fa-f]{1,4}|:)?(?:%[\w.]+)?(?![\w:])/';
        $text = Redactor::replaceCallback(
            $v6,
            static function (array $m) use ($context): string {
                $candidate = (string) preg_replace('/%.*$/', '', $m[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    return $m[0];
                }
                return $context->placeholder('ip');
            },
            $text
        );
        return new RedactionResult($text);
    }
}

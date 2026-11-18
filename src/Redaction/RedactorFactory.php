<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

use IndianOpinion\FinetuneExport\Content\ContentSource;
use IndianOpinion\FinetuneExport\Profile;

/**
 * Builds the rule list for a profile in the fixed order of case 49 and
 * applies the redaction filters. User names are loaded once per job.
 */
final class RedactorFactory
{
    /**
     * @param (callable(string, \Throwable): void)|null $onDegraded
     * @return array{0: Redactor, 1: list<string>} The redactor and job notes.
     */
    public static function build(Profile $profile, ContentSource $source, ?callable $onDegraded = null): array
    {
        $notes = [];
        $rules = [];
        if ($profile->redactionOn('url')) {
            $rules[] = new UrlRule();
        }
        if ($profile->redactionOn('email')) {
            $rules[] = new EmailRule();
        }
        if ($profile->redactionOn('ip')) {
            $rules[] = new IpRule();
        }
        if ($profile->redactionOn('phone')) {
            $rules[] = new PhoneRule();
        }
        if ($profile->redactionOn('address')) {
            $rules[] = new AddressRule(self::words(apply_filters('finetune_export_street_types', AddressRule::STREET_TYPES)));
        }
        if ($profile->redactionOn('name')) {
            $listed = $profile->nameSourceOn('users') ? $source->userNames() : [];
            if ($profile->nameSourceOn('filter')) {
                $listed = array_merge($listed, self::words(apply_filters('finetune_export_names', [], $profile)));
            }
            $honorifics = $profile->nameSourceOn('honorifics')
                ? self::words(apply_filters('finetune_export_honorifics', NameRule::HONORIFICS))
                : [];
            $nameRule = new NameRule(
                $listed,
                $profile->nameSourceOn('operator') ? $profile->list('operator_names') : [],
                $honorifics,
                self::words(apply_filters('finetune_export_name_stoplist', NameRule::STOPLIST)),
                $profile->list('allowlist'),
            );
            if ($nameRule->truncated()) {
                $notes[] = 'name_list_truncated';
            }
            $rules[] = $nameRule;
        }
        $filtered = apply_filters('finetune_export_redaction_rules', $rules, $profile);
        $final = [];
        foreach (is_array($filtered) ? $filtered : $rules as $rule) {
            if ($rule instanceof RedactionRule) {
                $final[] = $rule;
            }
        }
        return [new Redactor($final, $onDegraded), $notes];
    }

    /**
     * @return list<string>
     */
    private static function words(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return $out;
    }
}

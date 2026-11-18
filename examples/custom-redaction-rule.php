<?php

/**
 * Adds a site-specific redaction rule: here, internal staff numbers such as
 * "EMP-12345". Rules run after the built-in ones. Use the context's
 * placeholder() so later rules never match your placeholder.
 */

declare(strict_types=1);

use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Redaction\RedactionContext;
use IndianOpinion\FinetuneExport\Redaction\RedactionResult;
use IndianOpinion\FinetuneExport\Redaction\RedactionRule;
use IndianOpinion\FinetuneExport\Redaction\Redactor;

add_filter('finetune_export_redaction_rules', static function (array $rules, Profile $profile): array {
    $rules[] = new class implements RedactionRule {
        public function type(): string
        {
            return 'staff_id';
        }

        public function apply(string $text, RedactionContext $context): RedactionResult
        {
            $text = Redactor::replaceCallback(
                '/\bEMP-\d{5}\b/',
                static fn (array $m): string => $context->placeholder('staff_id'),
                $text
            );
            return new RedactionResult($text);
        }
    };
    return $rules;
}, 10, 2);

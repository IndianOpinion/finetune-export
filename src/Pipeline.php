<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

use IndianOpinion\FinetuneExport\Content\ContentSource;
use IndianOpinion\FinetuneExport\Content\PostData;
use IndianOpinion\FinetuneExport\Estimate\HeuristicEstimator;
use IndianOpinion\FinetuneExport\Estimate\TokenEstimator;
use IndianOpinion\FinetuneExport\Redaction\RedactionContext;
use IndianOpinion\FinetuneExport\Redaction\Redactor;
use IndianOpinion\FinetuneExport\Redaction\RedactorFactory;
use IndianOpinion\FinetuneExport\Redaction\UrlRule;
use IndianOpinion\FinetuneExport\Support\Logger;

/**
 * Turns one post into one record or one skip reason (PRD sections 4.2 to
 * 4.7). Shared by preview, count and export, so all three agree. Built once
 * per job: the redactor and the user name list are compiled once.
 */
final class Pipeline
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    public const BAD_ENCODING_RATIO = 0.05;

    private Redactor $redactor;
    private TokenEstimator $estimator;
    private HeuristicEstimator $fallback;
    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly Profile $profile,
        private readonly ContentSource $source,
        private readonly Cleaner $cleaner,
        private readonly Logger $logger,
        ?TokenEstimator $estimator = null,
    ) {
        [$this->redactor, $notes] = RedactorFactory::build(
            $profile,
            $source,
            function (string $type, \Throwable $e): void {
                $this->note('redaction_degraded');
                $this->note('rule_disabled:' . $type);
                $this->logger->error('redaction rule disabled for this job', [
                    'rule' => $type,
                    'error' => substr($e->getMessage(), 0, 200),
                ]);
            }
        );
        foreach ($notes as $note) {
            $this->note($note);
        }
        if (in_array('name_list_truncated', $notes, true)) {
            $this->logger->warning('name list cut at the cap', ['cap' => Redaction\NameRule::MAX_NAMES]);
        }
        if (!$cleaner->nfcAvailable()) {
            $this->note('nfc_unavailable');
        }
        $this->fallback = new HeuristicEstimator();
        $this->estimator = $estimator ?? self::estimatorFromFilter($this->fallback);
    }

    /**
     * Notes for the job: nfc_unavailable, name_list_truncated,
     * redaction_degraded, rule_disabled:<type>, estimator_fallback.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    public function process(int $postId): Outcome
    {
        try {
            return $this->build($postId);
        } catch (RegexLimitExceeded $e) {
            // Cleaning or redaction hit a PCRE limit: skip, never write unredacted (case 53).
            $this->logger->warning('record skipped: a regex hit its limit', ['post' => $postId]);
            return Outcome::skipped($postId, 'redaction_error');
        }
    }

    private function build(int $postId): Outcome
    {
        $post = $this->source->post($postId, $this->profile);
        if ($post === null) {
            return Outcome::skipped($postId, 'not_selectable');
        }
        $reason = $this->selectionSkip($post);
        if ($reason !== null) {
            return Outcome::skipped($postId, $reason);
        }

        $body = $this->cleaner->clean(
            $this->source->renderBody($post, $this->profile),
            $this->profile->format(),
            $this->source->cleanOptions($this->profile)
        );
        $body = $this->filterClean($body, 'body', $post);
        if (Cleaner::replacementRatio($body) > self::BAD_ENCODING_RATIO) {
            return Outcome::skipped($postId, 'bad_encoding');
        }
        $length = mb_strlen($body, 'UTF-8');
        $min = $this->profile->optionalInt('min_length');
        $max = $this->profile->optionalInt('max_length');
        if ($min !== null && $length < $min) {
            return Outcome::skipped($postId, 'too_short');
        }
        if ($max !== null && $length > $max) {
            return Outcome::skipped($postId, 'too_long');
        }

        $metaNotScalar = 0;
        $fields = $this->fields($post, $body, $metaNotScalar);
        $filtered = apply_filters('finetune_export_fields', $fields, $post, $this->profile);
        if (is_array($filtered)) {
            $fields = [];
            foreach ($filtered as $key => $value) {
                if (is_scalar($value)) {
                    $fields[(string) $key] = (string) $value;
                }
            }
        }

        $shape = $this->profile->shape();
        $targetKey = Profile::TARGET_KEYS[$shape];
        $context = new RedactionContext(
            $this->placeholders(),
            $this->profile->bool('pseudonyms'),
            $postId
        );
        $parts = [];
        try {
            foreach ($this->profile->activeTemplates() as $key => $template) {
                $filled = $this->fill($template, $fields);
                if ($filled === '') {
                    if ($key === $targetKey) {
                        return Outcome::skipped($postId, 'empty_target', $metaNotScalar);
                    }
                    $parts[$key] = '';
                    continue;
                }
                $parts[$key] = $this->redactor->redact($filled, $context)->text;
            }
            $permalink = $fields['permalink'] ?? $post->permalink;
            if ($this->profile->redactionOn('url')) {
                $permalink = UrlRule::cut($permalink);
            }
        } catch (RegexLimitExceeded $e) {
            $this->logger->warning('record skipped: redaction hit a regex limit', ['post' => $postId]);
            return Outcome::skipped($postId, 'redaction_error', $metaNotScalar);
        }
        if (trim($parts[$targetKey] ?? '') === '') {
            return Outcome::skipped($postId, 'empty_target', $metaNotScalar);
        }

        $record = $this->shapeRecord($parts, $post, $permalink);
        $record = apply_filters('finetune_export_record', $record, $post, $this->profile);
        if (!is_array($record)) {
            return Outcome::skipped($postId, 'filtered', $metaNotScalar);
        }

        $truncated = false;
        $tokens = $this->estimate($record);
        $limit = $this->profile->optionalInt('max_tokens');
        if ($limit !== null && $tokens > $limit) {
            if ($this->profile->get('over_limit') !== 'truncate') {
                return Outcome::skipped($postId, 'over_token_limit', $metaNotScalar);
            }
            $cut = $this->truncate($record, $limit);
            if ($cut === null) {
                return Outcome::skipped($postId, 'over_token_limit', $metaNotScalar);
            }
            [$record, $tokens] = $cut;
            $truncated = true;
        }

        try {
            $line = json_encode($record, self::JSON_FLAGS);
        } catch (\JsonException $e) {
            return Outcome::skipped($postId, 'encode_error', $metaNotScalar);
        }
        $target = self::target($record, $shape);
        return new Outcome(
            $postId,
            null,
            $record,
            $line,
            $tokens,
            $context->counts(),
            sha1($target ?? $line),
            $truncated,
            $metaNotScalar
        );
    }

    /**
     * Estimate with the configured estimator; a throwing estimator is
     * replaced by the default for the rest of the job (failure table).
     *
     * @param array<string, mixed> $record
     */
    public function estimate(array $record): int
    {
        try {
            return max(0, $this->estimator->estimate($record, $this->profile->shape()));
        } catch (\Throwable $e) {
            $this->estimator = $this->fallback;
            $this->note('estimator_fallback');
            $this->logger->once('estimator', 'warning', 'token estimator failed; using the default', [
                'error' => substr($e->getMessage(), 0, 200),
            ]);
            return $this->fallback->estimate($record, $this->profile->shape());
        }
    }

    private static function estimatorFromFilter(TokenEstimator $default): TokenEstimator
    {
        $estimator = apply_filters('finetune_export_token_estimator', $default);
        return $estimator instanceof TokenEstimator ? $estimator : $default;
    }

    private function selectionSkip(PostData $post): ?string
    {
        if ($post->optedOut) {
            return 'opted_out';
        }
        if ($post->categoryOptedOut) {
            return 'opted_out_category';
        }
        $day = substr($post->localDate, 0, 10);
        $from = $this->profile->get('date_from');
        $to = $this->profile->get('date_to');
        if ((is_string($from) && $day < $from) || (is_string($to) && $day > $to)) {
            return 'out_of_range';
        }
        $includeAuthors = $this->profile->list('include_authors');
        if (
            ($includeAuthors !== [] && !in_array($post->authorId, $includeAuthors, true))
            || in_array($post->authorId, $this->profile->list('exclude_authors'), true)
        ) {
            return 'excluded_author';
        }
        foreach ([['categories', $post->categoryIds], ['tags', $post->tagIds]] as [$kind, $ids]) {
            $include = $this->profile->list('include_' . $kind);
            if ($include !== [] && array_intersect($include, $ids) === []) {
                return 'excluded_term';
            }
            if (array_intersect($this->profile->list('exclude_' . $kind), $ids) !== []) {
                return 'excluded_term';
            }
        }
        return null;
    }

    /**
     * @return array<string, string>
     */
    private function fields(PostData $post, string $body, int &$metaNotScalar): array
    {
        $profile = $this->profile;
        $options = new CleanOptions();
        $text = fn (string $value, string $field): string => $this->filterClean(
            $this->cleaner->clean($value, 'text', $options),
            $field,
            $post
        );
        $fields = [];
        foreach ($profile->fields() as $field) {
            $fields[$field] = match ($field) {
                'id' => (string) $post->id,
                'title' => $text($post->title, 'title'),
                'excerpt' => $text($post->excerpt, 'excerpt'),
                'body' => $body,
                'date' => $post->isoDate,
                'modified' => $post->isoModified,
                'permalink' => $post->permalink,
                'categories' => $text(htmlspecialchars(implode(', ', $post->categories), ENT_NOQUOTES), 'categories'),
                'tags' => $text(htmlspecialchars(implode(', ', $post->tags), ENT_NOQUOTES), 'tags'),
                'author' => $text(htmlspecialchars($post->authorName, ENT_NOQUOTES), 'author'),
                'image_alt' => $text(htmlspecialchars($post->imageAlt, ENT_NOQUOTES), 'image_alt'),
                default => '',
            };
        }
        foreach ($profile->metaKeys() as $key) {
            $value = $post->meta[$key] ?? null;
            if ($value === null) {
                $fields['meta:' . $key] = '';
                continue;
            }
            if (!is_scalar($value)) {
                $metaNotScalar++;
                $fields['meta:' . $key] = '';
                continue;
            }
            $raw = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            $fields['meta:' . $key] = $text($raw, 'meta:' . $key);
        }
        return $fields;
    }

    private function filterClean(string $text, string $field, PostData $post): string
    {
        $filtered = apply_filters('finetune_export_clean_text', $text, $field, $post);
        return is_string($filtered) ? $filtered : $text;
    }

    /**
     * Fills a template. Without "keep empty fields", a line whose every
     * placeholder rendered empty is dropped (case 17).
     *
     * @param array<string, string> $fields
     */
    public function fill(string $template, array $fields): string
    {
        $keepEmpty = $this->profile->bool('keep_empty_fields');
        $lines = [];
        foreach (explode("\n", $template) as $line) {
            $placeholders = Profile::placeholdersIn($line);
            $allEmpty = $placeholders !== [];
            $filled = (string) preg_replace_callback(
                '/\{(meta:[^{}\s]+|[a-z_]+)\}/',
                static function (array $m) use ($fields, &$allEmpty): string {
                    $value = $fields[$m[1]] ?? null;
                    if ($value === null) {
                        return $m[0];
                    }
                    if ($value !== '') {
                        $allEmpty = false;
                    }
                    return $value;
                },
                $line
            );
            if ($allEmpty && !$keepEmpty) {
                continue;
            }
            $lines[] = $filled;
        }
        return trim(implode("\n", $lines));
    }

    /**
     * @param array<string, string> $parts
     * @return array<string, mixed>
     */
    private function shapeRecord(array $parts, PostData $post, string $permalink): array
    {
        $keepEmpty = $this->profile->bool('keep_empty_fields');
        $record = match ($this->profile->shape()) {
            'chat' => ['messages' => array_values(array_filter([
                ($parts['system'] ?? '') !== '' || ($keepEmpty && trim((string) $this->profile->get('templates')['system']) !== '')
                    ? ['role' => 'system', 'content' => $parts['system'] ?? '']
                    : null,
                ($parts['user'] ?? '') !== '' || $keepEmpty ? ['role' => 'user', 'content' => $parts['user'] ?? ''] : null,
                ['role' => 'assistant', 'content' => $parts['assistant'] ?? ''],
            ]))],
            'prompt_completion' => ['prompt' => $parts['prompt'] ?? '', 'completion' => $parts['completion'] ?? ''],
            default => ['text' => $parts['text'] ?? ''],
        };
        if ($this->profile->bool('include_metadata')) {
            $record['metadata'] = [
                'post_id' => $post->id,
                'permalink' => $permalink,
                'date' => $post->isoDate,
            ];
        }
        return $record;
    }

    /**
     * Cuts the target text at the last paragraph break that fits (case 59).
     *
     * @param array<string, mixed> $record
     * @return array{0: array<string, mixed>, 1: int}|null
     */
    private function truncate(array $record, int $limit): ?array
    {
        $target = self::target($record, $this->profile->shape());
        if ($target === null) {
            return null;
        }
        $paragraphs = preg_split('/\n\n+/', $target) ?: [];
        while (count($paragraphs) > 1) {
            array_pop($paragraphs);
            $candidate = self::withTarget($record, $this->profile->shape(), implode("\n\n", $paragraphs));
            $tokens = $this->estimate($candidate);
            if ($tokens <= $limit) {
                return [$candidate, $tokens];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function target(array $record, string $shape): ?string
    {
        if ($shape === 'chat') {
            $messages = $record['messages'] ?? null;
            if (!is_array($messages)) {
                return null;
            }
            foreach (array_reverse($messages) as $message) {
                if (is_array($message) && ($message['role'] ?? '') === 'assistant' && is_string($message['content'] ?? null)) {
                    return $message['content'];
                }
            }
            return null;
        }
        $key = $shape === 'prompt_completion' ? 'completion' : 'text';
        return isset($record[$key]) && is_string($record[$key]) ? $record[$key] : null;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function withTarget(array $record, string $shape, string $text): array
    {
        if ($shape === 'chat' && is_array($record['messages'] ?? null)) {
            for ($i = count($record['messages']) - 1; $i >= 0; $i--) {
                if (($record['messages'][$i]['role'] ?? '') === 'assistant') {
                    $record['messages'][$i]['content'] = $text;
                    break;
                }
            }
            return $record;
        }
        $record[$shape === 'prompt_completion' ? 'completion' : 'text'] = $text;
        return $record;
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(): array
    {
        $out = [];
        foreach (Profile::PLACEHOLDER_TYPES as $type) {
            $out[$type] = $this->profile->placeholder($type);
        }
        return $out;
    }

    private function note(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }
}

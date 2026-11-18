<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * Immutable export profile: selection, fields, shape, templates, redaction
 * and limits. Built from an array with fromArray() and validated on
 * construction; every problem raises InvalidProfile naming the field.
 */
final class Profile
{
    public const SHAPES = ['chat', 'prompt_completion', 'text'];
    public const FORMATS = ['text', 'markdown', 'html'];
    public const FIELDS = [
        'id', 'title', 'excerpt', 'body', 'date', 'modified', 'permalink',
        'categories', 'tags', 'author', 'image_alt',
    ];
    public const STATUSES = ['publish', 'private', 'draft', 'pending', 'future'];
    public const REDACTION_TYPES = ['url', 'email', 'ip', 'phone', 'address', 'name'];
    public const PLACEHOLDER_TYPES = ['name', 'email', 'phone', 'address', 'postcode', 'ip'];
    public const NAME_SOURCES = ['users', 'operator', 'honorifics', 'filter'];
    public const OVER_LIMIT = ['skip', 'truncate'];
    public const TEMPLATE_KEYS = [
        'chat' => ['system', 'user', 'assistant'],
        'prompt_completion' => ['prompt', 'completion'],
        'text' => ['text'],
    ];
    /** Template key whose emptiness makes a record `empty_target`. */
    public const TARGET_KEYS = [
        'chat' => 'assistant',
        'prompt_completion' => 'completion',
        'text' => 'text',
    ];
    public const DEFAULT_PLACEHOLDERS = [
        'name' => '[NAME]',
        'email' => '[EMAIL]',
        'phone' => '[PHONE]',
        'address' => '[ADDRESS]',
        'postcode' => '[POSTCODE]',
        'ip' => '[IP]',
    ];
    /** Placeholder name in a template => field it needs. */
    private const PLACEHOLDER_FIELDS = [
        'id' => 'id',
        'title' => 'title',
        'excerpt' => 'excerpt',
        'body' => 'body',
        'date' => 'date',
        'modified' => 'modified',
        'permalink' => 'permalink',
        'categories' => 'categories',
        'tags' => 'tags',
        'author' => 'author',
        'image_alt' => 'image_alt',
    ];

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data Already normalised data.
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Default values for every setting (PRD section 7).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'id' => 'default',
            'name' => 'Default',
            'post_types' => ['post'],
            'statuses' => ['publish'],
            'allow_unpublished' => false,
            'include_password_protected' => false,
            'date_from' => null,
            'date_to' => null,
            'include_categories' => [],
            'exclude_categories' => [],
            'include_tags' => [],
            'exclude_tags' => [],
            'include_authors' => [],
            'exclude_authors' => [],
            'min_length' => null,
            'max_length' => null,
            'limit' => null,
            'fields' => ['title', 'body'],
            'meta_keys' => [],
            'keep_empty_fields' => false,
            'format' => 'text',
            'apply_content_filters' => false,
            'render_dynamic_blocks' => false,
            'keep_image_alt' => false,
            'shape' => 'chat',
            'templates' => [
                'system' => '',
                'user' => 'Write an article with the headline: {title}',
                'assistant' => '{body}',
                'prompt' => 'Write an article with the headline: {title}',
                'completion' => '{body}',
                'text' => "{title}\n\n{body}",
            ],
            'include_metadata' => false,
            'redaction' => array_fill_keys(self::REDACTION_TYPES, true),
            'placeholders' => self::DEFAULT_PLACEHOLDERS,
            'name_sources' => array_fill_keys(self::NAME_SOURCES, true),
            'operator_names' => [],
            'allowlist' => [],
            'pseudonyms' => false,
            'max_tokens' => null,
            'over_limit' => 'skip',
            'validation_percent' => 0,
            'compress' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $d = self::defaults();
        $out = [];

        $out['id'] = self::slug($input['id'] ?? $d['id'], 'id');
        $out['name'] = self::text($input['name'] ?? $d['name'], 'name', 200);
        if ($out['name'] === '') {
            $out['name'] = $out['id'];
        }

        $out['post_types'] = self::keyList($input['post_types'] ?? $d['post_types'], 'post_types');
        if ($out['post_types'] === []) {
            throw new InvalidProfile('post_types', 'choose at least one post type');
        }
        $out['statuses'] = self::keyList($input['statuses'] ?? $d['statuses'], 'statuses');
        if ($out['statuses'] === []) {
            throw new InvalidProfile('statuses', 'choose at least one status');
        }
        foreach ($out['statuses'] as $status) {
            if (!in_array($status, self::STATUSES, true)) {
                throw new InvalidProfile('statuses', 'unknown status "' . $status . '"');
            }
        }
        $out['allow_unpublished'] = self::toBool($input['allow_unpublished'] ?? false);
        if (!$out['allow_unpublished'] && $out['statuses'] !== ['publish']) {
            throw new InvalidProfile(
                'statuses',
                'statuses other than publish need the setting "allow unpublished content"'
            );
        }
        $out['include_password_protected'] = self::toBool($input['include_password_protected'] ?? false);

        $out['date_from'] = self::date($input['date_from'] ?? null, 'date_from');
        $out['date_to'] = self::date($input['date_to'] ?? null, 'date_to');
        if ($out['date_from'] !== null && $out['date_to'] !== null && $out['date_from'] > $out['date_to']) {
            throw new InvalidProfile('date_to', 'the end date is before the start date');
        }
        foreach (
            [
                'include_categories', 'exclude_categories', 'include_tags',
                'exclude_tags', 'include_authors', 'exclude_authors',
            ] as $key
        ) {
            $out[$key] = self::idList($input[$key] ?? [], $key);
        }
        $out['min_length'] = self::toOptionalInt($input['min_length'] ?? null, 'min_length', 0);
        $out['max_length'] = self::toOptionalInt($input['max_length'] ?? null, 'max_length', 1);
        if (
            $out['min_length'] !== null && $out['max_length'] !== null
            && $out['min_length'] > $out['max_length']
        ) {
            throw new InvalidProfile('max_length', 'the maximum length is below the minimum');
        }
        $out['limit'] = self::toOptionalInt($input['limit'] ?? null, 'limit', 1);

        $out['fields'] = self::keyList($input['fields'] ?? $d['fields'], 'fields');
        foreach ($out['fields'] as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new InvalidProfile('fields', 'unknown field "' . $field . '"');
            }
        }
        $out['meta_keys'] = [];
        foreach (self::stringList($input['meta_keys'] ?? []) as $key) {
            if ($key[0] === '_') {
                throw new InvalidProfile(
                    'meta_keys',
                    'meta key "' . $key . '" starts with an underscore; such keys are internal or private'
                );
            }
            if (preg_match('/^[\w\-.:]{1,191}$/u', $key) !== 1) {
                throw new InvalidProfile('meta_keys', 'meta key "' . $key . '" has characters that are not allowed');
            }
            $out['meta_keys'][] = $key;
        }
        $out['meta_keys'] = array_values(array_unique($out['meta_keys']));
        $out['keep_empty_fields'] = self::toBool($input['keep_empty_fields'] ?? false);

        $out['format'] = self::choice($input['format'] ?? $d['format'], self::FORMATS, 'format');
        $out['apply_content_filters'] = self::toBool($input['apply_content_filters'] ?? false);
        $out['render_dynamic_blocks'] = self::toBool($input['render_dynamic_blocks'] ?? false);
        $out['keep_image_alt'] = self::toBool($input['keep_image_alt'] ?? false);

        $out['shape'] = self::choice($input['shape'] ?? $d['shape'], self::SHAPES, 'shape');
        $templates = $d['templates'];
        if (isset($input['templates']) && is_array($input['templates'])) {
            foreach (array_keys($templates) as $key) {
                if (array_key_exists($key, $input['templates'])) {
                    $templates[$key] = self::template($input['templates'][$key], 'templates.' . $key);
                }
            }
        }
        $out['templates'] = $templates;
        $out['include_metadata'] = self::toBool($input['include_metadata'] ?? false);

        $redaction = $d['redaction'];
        if (isset($input['redaction']) && is_array($input['redaction'])) {
            foreach (self::REDACTION_TYPES as $type) {
                if (array_key_exists($type, $input['redaction'])) {
                    $redaction[$type] = self::toBool($input['redaction'][$type]);
                }
            }
        }
        $out['redaction'] = $redaction;
        $placeholders = $d['placeholders'];
        if (isset($input['placeholders']) && is_array($input['placeholders'])) {
            foreach (self::PLACEHOLDER_TYPES as $type) {
                $value = $input['placeholders'][$type] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $placeholders[$type] = self::text($value, 'placeholders.' . $type, 40);
                }
            }
        }
        $out['placeholders'] = $placeholders;
        $sources = $d['name_sources'];
        if (isset($input['name_sources']) && is_array($input['name_sources'])) {
            foreach (self::NAME_SOURCES as $source) {
                if (array_key_exists($source, $input['name_sources'])) {
                    $sources[$source] = self::toBool($input['name_sources'][$source]);
                }
            }
        }
        $out['name_sources'] = $sources;
        $out['operator_names'] = self::stringList($input['operator_names'] ?? []);
        $out['allowlist'] = self::stringList($input['allowlist'] ?? []);
        $out['pseudonyms'] = self::toBool($input['pseudonyms'] ?? false);

        $out['max_tokens'] = self::toOptionalInt($input['max_tokens'] ?? null, 'max_tokens', 1);
        $out['over_limit'] = self::choice($input['over_limit'] ?? $d['over_limit'], self::OVER_LIMIT, 'over_limit');
        $split = self::toOptionalInt($input['validation_percent'] ?? 0, 'validation_percent', 0) ?? 0;
        if ($split > 50) {
            throw new InvalidProfile('validation_percent', 'the validation split is at most 50 percent');
        }
        $out['validation_percent'] = $split;
        $out['compress'] = self::toBool($input['compress'] ?? false);

        self::validateTemplates($out);

        return new self($out);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function id(): string
    {
        return (string) $this->data['id'];
    }

    public function name(): string
    {
        return (string) $this->data['name'];
    }

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            throw new \InvalidArgumentException('Unknown profile key ' . $key);
        }
        return $this->data[$key];
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function shape(): string
    {
        return (string) $this->data['shape'];
    }

    public function format(): string
    {
        return (string) $this->data['format'];
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        /** @var list<string> */
        return $this->data['fields'];
    }

    public function hasField(string $field): bool
    {
        return in_array($field, $this->fields(), true);
    }

    /**
     * @return list<string>
     */
    public function metaKeys(): array
    {
        /** @var list<string> */
        return $this->data['meta_keys'];
    }

    /**
     * Templates used by the profile's shape, keyed by message or key name.
     *
     * @return array<string, string>
     */
    public function activeTemplates(): array
    {
        $out = [];
        foreach (self::TEMPLATE_KEYS[$this->shape()] as $key) {
            $out[$key] = (string) $this->data['templates'][$key];
        }
        return $out;
    }

    public function redactionOn(string $type): bool
    {
        return (bool) ($this->data['redaction'][$type] ?? true);
    }

    public function nameSourceOn(string $source): bool
    {
        return (bool) ($this->data['name_sources'][$source] ?? true);
    }

    public function placeholder(string $type): string
    {
        return (string) ($this->data['placeholders'][$type] ?? self::DEFAULT_PLACEHOLDERS[$type] ?? '[REDACTED]');
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key);
        return is_array($value) ? array_values($value) : [];
    }

    public function optionalInt(string $key): ?int
    {
        $value = $this->get($key);
        return $value === null ? null : (int) $value;
    }

    /**
     * Settings in this profile that need the sensitive capability (PRD 5.6).
     *
     * @return list<string>
     */
    public function sensitiveChoices(): array
    {
        $out = [];
        if ($this->data['statuses'] !== ['publish'] || $this->data['allow_unpublished']) {
            $out[] = 'allow_unpublished';
        }
        if ($this->data['include_password_protected']) {
            $out[] = 'include_password_protected';
        }
        foreach ($this->redactionOff() as $type) {
            $out[] = 'redaction_off:' . $type;
        }
        if ($this->redactionOn('name')) {
            // Switching every name source off is name redaction off by another route.
            foreach (self::NAME_SOURCES as $source) {
                if (!$this->nameSourceOn($source)) {
                    $out[] = 'name_source_off:' . $source;
                }
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function redactionOff(): array
    {
        $out = [];
        foreach (self::REDACTION_TYPES as $type) {
            if (!$this->redactionOn($type)) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * True when author display names will be replaced by the name placeholder
     * (PRD case 15). The screen shows a warning.
     */
    public function authorWillBeRedacted(): bool
    {
        return $this->hasField('author') && $this->redactionOn('name')
            && ($this->nameSourceOn('users') || $this->nameSourceOn('filter') || $this->nameSourceOn('operator'));
    }

    /**
     * Placeholders found in a template: list of [name, meta key or null].
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public static function placeholdersIn(string $template): array
    {
        $out = [];
        if (preg_match_all('/\{(meta:[^{}\s]+|[a-z_]+)\}/', $template, $m) === false) {
            return $out;
        }
        foreach ($m[1] as $name) {
            if (str_starts_with($name, 'meta:')) {
                $out[] = ['meta', substr($name, 5)];
            } else {
                $out[] = [$name, null];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $out
     */
    private static function validateTemplates(array $out): void
    {
        $shape = (string) $out['shape'];
        foreach (self::TEMPLATE_KEYS[$shape] as $key) {
            $template = (string) $out['templates'][$key];
            if ($key !== 'system' && trim($template) === '') {
                throw new InvalidProfile('templates.' . $key, 'this template cannot be empty');
            }
            foreach (self::placeholdersIn($template) as [$name, $metaKey]) {
                if ($name === 'meta') {
                    if (!in_array($metaKey, $out['meta_keys'], true)) {
                        throw new InvalidProfile(
                            'templates.' . $key,
                            'placeholder {meta:' . $metaKey . '} needs the meta key on the allowlist'
                        );
                    }
                    continue;
                }
                if (!isset(self::PLACEHOLDER_FIELDS[$name])) {
                    throw new InvalidProfile('templates.' . $key, 'unknown placeholder {' . $name . '}');
                }
                if (!in_array(self::PLACEHOLDER_FIELDS[$name], $out['fields'], true)) {
                    throw new InvalidProfile(
                        'templates.' . $key,
                        'placeholder {' . $name . '} needs the field "' . self::PLACEHOLDER_FIELDS[$name]
                        . '", which is not selected'
                    );
                }
            }
        }
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
        }
        return false;
    }

    private static function slug(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value) !== 1) {
            throw new InvalidProfile($field, 'use 1 to 64 lowercase letters, digits, hyphens or underscores');
        }
        return $value;
    }

    private static function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidProfile($field, 'must be text');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InvalidProfile($field, 'is longer than ' . $max . ' characters');
        }
        return $value;
    }

    private static function template(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidProfile($field, 'must be text');
        }
        if (strlen($value) > 20000) {
            throw new InvalidProfile($field, 'is longer than 20000 bytes');
        }
        return str_replace("\r\n", "\n", $value);
    }

    /**
     * @param list<string> $allowed
     */
    private static function choice(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidProfile($field, 'must be one of ' . implode(', ', $allowed));
        }
        return $value;
    }

    private static function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            throw new InvalidProfile($field, 'use the form YYYY-MM-DD');
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidProfile($field, 'is not a real date');
        }
        return $value;
    }

    private static function toOptionalInt(mixed $value, string $field, int $min): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value) || $value < $min) {
            throw new InvalidProfile($field, 'must be a whole number of at least ' . $min);
        }
        return $value;
    }

    /**
     * @return list<string>
     */
    private static function keyList(mixed $value, string $field): array
    {
        $out = [];
        foreach (self::stringList($value) as $item) {
            if (preg_match('/^[a-z0-9_-]{1,40}$/', $item) !== 1) {
                throw new InvalidProfile($field, '"' . $item . '" is not a valid key');
            }
            $out[] = $item;
        }
        return array_values(array_unique($out));
    }

    /**
     * @return list<int>
     */
    private static function idList(mixed $value, string $field): array
    {
        $out = [];
        foreach (self::stringList($value) as $item) {
            if (preg_match('/^\d{1,19}$/', $item) !== 1 || (int) $item < 1) {
                throw new InvalidProfile($field, '"' . $item . '" is not an id');
            }
            $out[] = (int) $item;
        }
        return array_values(array_unique($out));
    }

    /**
     * Accepts an array of scalars or a string with one item per line or comma.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }
}

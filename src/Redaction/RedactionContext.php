<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

/**
 * Per-record state shared by every rule: placeholder texts, the protected
 * marker table, pseudonym numbering and counts. Create one per record, so
 * consistent pseudonyms are numbered per record (PRD case 47).
 *
 * Markers are built from private-use code points (U+E000 to U+E01F). The
 * Redactor removes those code points from input text before any rule runs,
 * so a marker can only come from this table.
 */
final class RedactionContext
{
    public const MARK_OPEN = "\u{E000}";
    public const MARK_CLOSE = "\u{E001}";

    /** @var list<string> */
    private array $table = [];
    /** @var array<string, int> */
    private array $counts = [];
    /** @var array<string, int> */
    private array $pseudonyms = [];

    /**
     * @param array<string, string> $placeholders Visible text per type.
     */
    public function __construct(
        private readonly array $placeholders = [],
        private readonly bool $pseudonymise = false,
        public readonly int $postId = 0,
    ) {
    }

    /**
     * Returns a protected marker for one replacement of $type and counts it.
     * For names with consistent pseudonyms on, $key identifies the person so
     * repeated mentions share a number.
     */
    public function placeholder(string $type, ?string $key = null): string
    {
        $visible = $this->placeholders[$type] ?? '[' . strtoupper($type) . ']';
        if ($type === 'name' && $this->pseudonymise) {
            $key = $key ?? '';
            if (!isset($this->pseudonyms[$key])) {
                $this->pseudonyms[$key] = count($this->pseudonyms) + 1;
            }
            $visible = self::numbered($visible, $this->pseudonyms[$key]);
        }
        $this->counts[$type] = ($this->counts[$type] ?? 0) + 1;
        return $this->mark($visible);
    }

    /**
     * Protects text from every later rule without counting it (used for the
     * allowlist and for text a rule keeps, such as an honorific).
     */
    public function protect(string $text): string
    {
        return $this->mark($text);
    }

    /**
     * Adds to the count of a type without inserting a placeholder, for rules
     * that shorten text instead of replacing it (query strings on URLs).
     */
    public function count(string $type, int $n = 1): void
    {
        $this->counts[$type] = ($this->counts[$type] ?? 0) + $n;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * Replaces every marker with its visible text.
     */
    public function restore(string $text): string
    {
        $result = preg_replace_callback(
            '/' . self::MARK_OPEN . '([\x{E010}-\x{E019}]+)' . self::MARK_CLOSE . '/u',
            function (array $m): string {
                $digits = '';
                foreach (mb_str_split($m[1], 1, 'UTF-8') as $char) {
                    $digits .= (string) (mb_ord($char, 'UTF-8') - 0xE010);
                }
                return $this->table[(int) $digits] ?? '';
            },
            $text
        );
        if ($result === null) {
            throw new \IndianOpinion\FinetuneExport\RegexLimitExceeded('Restoring placeholders failed.');
        }
        // Markers can nest (an allowlisted phrase inside a kept span): repeat.
        return str_contains($result, self::MARK_OPEN) ? $this->restore($result) : $result;
    }

    /**
     * Visible placeholder texts used in this context, for highlighting.
     *
     * @return list<string>
     */
    public function visiblePlaceholders(): array
    {
        return array_values(array_unique($this->table));
    }

    public static function numbered(string $placeholder, int $n): string
    {
        if (str_ends_with($placeholder, ']')) {
            return substr($placeholder, 0, -1) . '_' . $n . ']';
        }
        return $placeholder . '_' . $n;
    }

    private function mark(string $visible): string
    {
        $index = count($this->table);
        $this->table[] = $visible;
        $encoded = '';
        foreach (str_split((string) $index) as $digit) {
            $encoded .= mb_chr(0xE010 + (int) $digit, 'UTF-8');
        }
        return self::MARK_OPEN . $encoded . self::MARK_CLOSE;
    }
}

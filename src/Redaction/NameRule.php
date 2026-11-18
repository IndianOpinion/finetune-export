<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

/**
 * Cases 43 to 46: names from lists (site users, the operator's list, the
 * name-source filter) and an honorific pattern become the name placeholder.
 *
 * Lists are matched by word, not by one huge regular expression, so 50,000
 * names cost one hash lookup per word and phrase length. Multi-word names
 * match as whole phrases, case-sensitively, longest first. Single-word names
 * from users and the filter must pass the limits of case 45; single words on
 * the operator's list are exempt. Allowlisted phrases are found first and no
 * name may overlap one.
 */
final class NameRule implements RedactionRule
{
    public const MAX_NAMES = 50000;
    private const MAX_WORDS = 6;
    private const TOKEN = '/[\p{L}\p{M}\p{N}]+(?:[\'’\-][\p{L}\p{M}\p{N}]+)*/u';

    public const HONORIFICS = ['Mr', 'Mrs', 'Ms', 'Mx', 'Dr', 'Prof', 'Shri', 'Smt', 'Sri', 'Kumari', 'Sir', 'Dame', 'Lady', 'Lord'];

    public const STOPLIST = [
        'May', 'Will', 'Grace', 'Mark', 'Bill', 'June', 'April', 'August', 'Rose', 'Faith', 'Hope',
        'Dawn', 'Frank', 'Rich', 'Jack', 'Lily', 'Iris', 'Ruby', 'Pearl', 'Amber', 'Sunny', 'Prince',
        'King', 'Queen', 'Baby', 'Summer', 'Autumn', 'Winter', 'Spring', 'Victory', 'Mercy', 'Joy',
        'Admin', 'Administrator', 'Editor', 'Author', 'Desk', 'Team', 'Staff', 'News', 'Guest',
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
        'January', 'February', 'March', 'July', 'September', 'October', 'November', 'December',
        'Chance', 'Hunter', 'Major', 'General', 'Justice', 'Bishop', 'Earnest', 'Noble', 'Royal',
    ];

    /** @var array<string, true> */
    private array $multi = [];
    /** @var array<string, true> */
    private array $singleStrict = [];
    /** @var array<string, true> */
    private array $singleOperator = [];
    /** @var array<string, true> */
    private array $allow = [];
    /** @var array<string, true> */
    private array $stop = [];
    private int $maxWords = 1;
    private int $maxAllowWords = 1;
    private bool $truncated = false;
    private string $honorificPattern = '';

    /**
     * @param list<string> $listedNames   Users and filter names: single words get the case 45 limits.
     * @param list<string> $operatorNames The operator's own list: always redacted.
     * @param list<string> $honorifics    Empty turns the honorific pattern off.
     * @param list<string> $stoplist
     * @param list<string> $allowlist
     */
    public function __construct(
        array $listedNames,
        array $operatorNames = [],
        array $honorifics = self::HONORIFICS,
        array $stoplist = self::STOPLIST,
        array $allowlist = [],
        int $cap = self::MAX_NAMES,
    ) {
        foreach ($stoplist as $word) {
            $this->stop[mb_strtolower($word, 'UTF-8')] = true;
        }
        $added = 0;
        foreach ([[$operatorNames, true], [$listedNames, false]] as [$names, $operator]) {
            foreach ($names as $name) {
                $words = self::words($name);
                if ($words === null) {
                    continue;
                }
                if ($added >= $cap) {
                    $this->truncated = true;
                    break 2;
                }
                $key = implode(' ', $words);
                if (count($words) > 1) {
                    $this->multi[$key] = true;
                    $this->maxWords = max($this->maxWords, count($words));
                } elseif ($operator) {
                    $this->singleOperator[$key] = true;
                } else {
                    $this->singleStrict[$key] = true;
                }
                $added++;
            }
        }
        foreach ($allowlist as $phrase) {
            $words = self::words($phrase);
            if ($words !== null) {
                $this->allow[implode(' ', $words)] = true;
                $this->maxAllowWords = max($this->maxAllowWords, count($words));
            }
        }
        $honorifics = array_values(array_filter(
            $honorifics,
            static fn (string $h): bool => preg_match('/^[\p{L}\p{M}]{1,20}$/u', $h) === 1
        ));
        if ($honorifics !== []) {
            usort($honorifics, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $word = '\p{Lu}[\p{L}\p{M}\'’\-]*';
            $this->honorificPattern = '/(?<![\p{L}\p{M}\p{N}])((?:' . implode('|', array_map(
                static fn (string $h): string => preg_quote($h, '/'),
                $honorifics
            )) . ')\.?[ \t]+)(' . $word . '(?:[ \t]+' . $word . '){0,2})(?![\p{L}\p{M}\p{N}])/u';
        }
    }

    public function type(): string
    {
        return 'name';
    }

    /**
     * True when the list was cut at the cap (performance budget, section 9).
     */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function apply(string $text, RedactionContext $context): RedactionResult
    {
        $text = $this->applyLists($text, $context);
        if ($this->honorificPattern !== '') {
            $text = Redactor::replaceCallback(
                $this->honorificPattern,
                static function (array $m) use ($context): string {
                    $key = (string) preg_replace('/\s+/u', ' ', $m[2]);
                    return $context->protect($m[1]) . $context->placeholder('name', $key);
                },
                $text
            );
        }
        return new RedactionResult($text);
    }

    private function applyLists(string $text, RedactionContext $context): string
    {
        if ($this->multi === [] && $this->singleStrict === [] && $this->singleOperator === [] && $this->allow === []) {
            return $text;
        }
        if (preg_match_all(self::TOKEN, $text, $m, PREG_OFFSET_CAPTURE) === false) {
            throw new \IndianOpinion\FinetuneExport\RegexLimitExceeded('Name tokeniser failed: ' . preg_last_error_msg());
        }
        $tokens = $m[0];
        $n = count($tokens);
        if ($n === 0) {
            return $text;
        }

        // Pass 1: allowlisted phrases, longest first.
        $allowed = array_fill(0, $n, false);
        /** @var list<array{0: int, 1: int, 2: string, 3: string}> $spans start token, end token, kind, key */
        $spans = [];
        if ($this->allow !== []) {
            for ($i = 0; $i < $n;) {
                $matched = 0;
                for ($len = min($this->maxAllowWords, $n - $i); $len >= 1; $len--) {
                    $key = $this->phrase($text, $tokens, $i, $len);
                    if ($key !== null && isset($this->allow[$key])) {
                        $matched = $len;
                        break;
                    }
                }
                if ($matched > 0) {
                    for ($k = $i; $k < $i + $matched; $k++) {
                        $allowed[$k] = true;
                    }
                    $spans[] = [$i, $i + $matched - 1, 'allow', ''];
                    $i += $matched;
                } else {
                    $i++;
                }
            }
        }

        // Pass 2: names, longest first, never overlapping an allowlisted word.
        for ($i = 0; $i < $n;) {
            if ($allowed[$i]) {
                $i++;
                continue;
            }
            $matched = 0;
            $matchKey = '';
            for ($len = min($this->maxWords, $n - $i); $len >= 1; $len--) {
                if (in_array(true, array_slice($allowed, $i, $len), true)) {
                    continue;
                }
                $key = $this->phrase($text, $tokens, $i, $len);
                if ($key === null) {
                    continue;
                }
                if ($len > 1 ? isset($this->multi[$key]) : $this->singleMatches($key, $text, $tokens[$i][1])) {
                    $matched = $len;
                    $matchKey = $key;
                    break;
                }
            }
            if ($matched > 0) {
                $spans[] = [$i, $i + $matched - 1, 'name', $matchKey];
                $i += $matched;
            } else {
                $i++;
            }
        }
        if ($spans === []) {
            return $text;
        }
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $out = '';
        $cursor = 0;
        foreach ($spans as [$first, $last, $kind, $key]) {
            $start = $tokens[$first][1];
            $end = $tokens[$last][1] + strlen($tokens[$last][0]);
            $out .= substr($text, $cursor, $start - $cursor);
            $original = substr($text, $start, $end - $start);
            $out .= $kind === 'allow' ? $context->protect($original) : $context->placeholder('name', $key);
            $cursor = $end;
        }
        return $out . substr($text, $cursor);
    }

    private function singleMatches(string $word, string $text, int $offset): bool
    {
        if (isset($this->singleOperator[$word])) {
            return true;
        }
        if (!isset($this->singleStrict[$word])) {
            return false;
        }
        if (preg_match_all('/\p{L}/u', $word) < 4 || preg_match('/^\p{Lu}/u', $word) !== 1) {
            return false;
        }
        if (isset($this->stop[mb_strtolower($word, 'UTF-8')])) {
            return false;
        }
        return !self::sentenceInitial(substr($text, 0, $offset));
    }

    /**
     * True when the text before a word ends a sentence, a line or a list
     * marker, so the word is the first of its sentence.
     */
    public static function sentenceInitial(string $before): bool
    {
        if (trim($before) === '') {
            return true;
        }
        return preg_match('/(?:[.!?…:]["\'”’)\]]*|\n[ \t]*(?:[-*•]|\d+[.)])?|^[ \t]*(?:[-*•]|\d+[.)]))[ \t]*["\'“‘(\[]*$/u', $before) === 1;
    }

    /**
     * Key of the phrase made of $len tokens from $i, or null when the tokens
     * are not separated by plain spaces (or the period after an initial).
     *
     * @param list<array{0: string, 1: int}> $tokens
     */
    private function phrase(string $text, array $tokens, int $i, int $len): ?string
    {
        $words = [$tokens[$i][0]];
        for ($k = $i; $k < $i + $len - 1; $k++) {
            $end = $tokens[$k][1] + strlen($tokens[$k][0]);
            $gap = substr($text, $end, $tokens[$k + 1][1] - $end);
            $initial = mb_strlen($tokens[$k][0], 'UTF-8') === 1;
            if (preg_match($initial ? '/^\.?[ \t]+$|^\.$/' : '/^[ \t]+$/', $gap) !== 1) {
                return null;
            }
            $words[] = $tokens[$k + 1][0];
        }
        return implode(' ', $words);
    }

    /**
     * Splits a list entry into words, or null if it cannot match as words.
     *
     * @return list<string>|null
     */
    private static function words(string $entry): ?array
    {
        $entry = trim($entry);
        if ($entry === '' || preg_match_all(self::TOKEN, $entry, $m) === false) {
            return null;
        }
        $words = $m[0];
        if ($words === [] || count($words) > self::MAX_WORDS) {
            return null;
        }
        $rest = $entry;
        foreach ($words as $word) {
            $pos = strpos($rest, $word);
            if ($pos === false || preg_match('/^[\s.]*$/u', substr($rest, 0, $pos)) !== 1) {
                return null;
            }
            $rest = substr($rest, $pos + strlen($word));
        }
        if (preg_match('/^[\s.]*$/u', $rest) !== 1) {
            return null;
        }
        return $words;
    }
}

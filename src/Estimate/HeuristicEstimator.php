<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Estimate;

/**
 * The default estimator (PRD case 55). Deterministic, local, no model.
 *
 * Over the decoded string values of the record (keys, chat roles and JSON
 * punctuation are not counted):
 * - each run of ASCII letters and digits counts ceil(length / 4);
 * - each other printable ASCII character counts 1;
 * - whitespace counts 0;
 * - each Han, Hiragana, Katakana or Hangul code point counts 1;
 * - each other code point counts ceil(UTF-8 bytes / 2).
 * Then 4 per message for the chat shape, or 3 per record otherwise.
 */
final class HeuristicEstimator implements TokenEstimator
{
    private const PATTERN = '/[A-Za-z0-9]+|\s+|[\x{0000}-\x{007F}]|[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]|./su';

    public function estimate(array $record, string $shape): int
    {
        $total = 0;
        foreach (self::strings($record) as $value) {
            $total += $this->count($value);
        }
        if ($shape === 'chat') {
            $messages = isset($record['messages']) && is_array($record['messages']) ? count($record['messages']) : 0;
            return $total + 4 * $messages;
        }
        return $total + 3;
    }

    /**
     * Estimate for one string, without envelope overhead.
     */
    public function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        if (preg_match_all(self::PATTERN, $text, $m) === false) {
            // Invalid UTF-8: fall back to bytes / 2, which errs high.
            return (int) ceil(strlen($text) / 2);
        }
        $tokens = 0;
        foreach ($m[0] as $piece) {
            $first = ord($piece[0]);
            if ($first < 0x80) {
                if (ctype_alnum($piece[0])) {
                    $tokens += (int) ceil(strlen($piece) / 4);
                } elseif (!ctype_space($piece[0])) {
                    $tokens += 1;
                }
                continue;
            }
            if (preg_match('/^\s+$/u', $piece) === 1) {
                continue;
            }
            if (preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]$/u', $piece) === 1) {
                $tokens += 1;
                continue;
            }
            $tokens += (int) ceil(strlen($piece) / 2);
        }
        return $tokens;
    }

    /**
     * Every string value in a nested array, in order.
     *
     * @param array<mixed> $value
     * @return list<string>
     */
    public static function strings(array $value): array
    {
        $out = [];
        array_walk_recursive($value, static function (mixed $v, int|string $key) use (&$out): void {
            // Chat roles are covered by the per-message overhead.
            if (is_string($v) && $key !== 'role') {
                $out[] = $v;
            }
        });
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * Preview output (PRD cases 89 to 91). Holds final lines, never the text
 * that redaction removed.
 */
final class PreviewResult
{
    /**
     * @param list<array{post_id: int, line: string, pretty: string, tokens: int, counts: array<string, int>}> $records
     * @param list<array{post_id: int, reason: string}> $skipped
     * @param list<string> $notes
     * @param list<string> $placeholders Visible placeholder texts to highlight.
     */
    public function __construct(
        public readonly array $records,
        public readonly array $skipped,
        public readonly array $notes,
        public readonly array $placeholders,
    ) {
    }

    /**
     * Pretty JSON of one record, HTML-escaped, with placeholders wrapped in
     * <mark>. Safe to print.
     */
    public function highlighted(int $index): string
    {
        $escaped = htmlspecialchars($this->records[$index]['pretty'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($this->placeholders === []) {
            return $escaped;
        }
        $alternatives = [];
        foreach ($this->placeholders as $placeholder) {
            $safe = htmlspecialchars($placeholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $quoted = preg_quote($safe, '/');
            if (str_ends_with($safe, ']')) {
                $quoted = preg_quote(substr($safe, 0, -1), '/') . '(?:_\d+)?\]';
            }
            $alternatives[] = $quoted;
        }
        usort($alternatives, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $result = preg_replace('/' . implode('|', $alternatives) . '/u', '<mark>$0</mark>', $escaped);
        return $result ?? $escaped;
    }
}

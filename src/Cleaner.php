<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * Turns stored post HTML into clean text, Markdown or a small HTML subset
 * (PRD section 4.4). Pure PHP: no WordPress calls, so it is unit tested
 * directly. Content filters and dynamic block rendering, which need
 * WordPress, happen before this class is called.
 */
final class Cleaner
{
    /** Elements removed together with everything inside them (case 22). */
    public const REMOVED_ELEMENTS = ['script', 'style', 'iframe', 'noscript', 'form', 'object', 'embed', 'svg'];

    /** Tags kept by the `html` format (case 23). */
    public const HTML_ALLOWED = ['p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'em', 'strong', 'a'];

    private const BLOCK_ELEMENTS = [
        'p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'address',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'ul', 'ol', 'li', 'table', 'thead',
        'tbody', 'tfoot', 'tr', 'figure', 'figcaption', 'hr', 'dl', 'dt', 'dd', 'details', 'summary',
    ];

    private const HTML_RENAME = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2'];

    private bool $useIntl;

    public function __construct(?bool $useIntl = null)
    {
        $this->useIntl = $useIntl ?? class_exists(\Normalizer::class);
    }

    /**
     * Whether NFC normalisation is applied (case 24).
     */
    public function nfcAvailable(): bool
    {
        return $this->useIntl;
    }

    public function clean(string $html, string $format, CleanOptions $options): string
    {
        if (!in_array($format, Profile::FORMATS, true)) {
            throw new \InvalidArgumentException('Unknown format ' . $format);
        }
        $html = self::repairUtf8($html);
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = $this->removeBlocks($html, $options);
        $html = $this->stripShortcodes($html, $options->shortcodes);

        $root = $this->parse($html);
        $this->prune($root, $options->keepImageAlt);

        $out = match ($format) {
            'text' => $this->renderText($root, false),
            'markdown' => $this->renderText($root, true),
            default => $this->renderHtml($root),
        };

        return $this->normalise($out, $format === 'html');
    }

    /**
     * Replaces invalid UTF-8 sequences with U+FFFD (case 26).
     */
    public static function repairUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        return htmlspecialchars_decode(
            htmlspecialchars($text, ENT_SUBSTITUTE | ENT_NOQUOTES, 'UTF-8'),
            ENT_NOQUOTES
        );
    }

    /**
     * Share of U+FFFD among the characters of a text, from 0 to 1.
     */
    public static function replacementRatio(string $text): float
    {
        $length = mb_strlen($text, 'UTF-8');
        if ($length === 0) {
            return 0.0;
        }
        return mb_substr_count($text, "\u{FFFD}", 'UTF-8') / $length;
    }

    private function removeBlocks(string $html, CleanOptions $options): string
    {
        if (!$options->blocksRendered) {
            foreach ($options->dynamicBlocks as $name) {
                $quoted = preg_quote($name, '/');
                $html = $this->replace(
                    '/<!--\s+wp:' . $quoted . '(?:\s[^>]*?)?\s+-->.*?<!--\s+\/wp:' . $quoted . '\s+-->/s',
                    '',
                    $html
                );
            }
        }
        // Self-closing block comments carry no saved markup; every other block
        // comment is a delimiter. Both go (case 20).
        return $this->replace('/<!--\s+\/?wp:[^>]*?-->/s', '', $html);
    }

    /**
     * @param list<string>|null $tags
     */
    private function stripShortcodes(string $html, ?array $tags): string
    {
        if ($tags === []) {
            return $html;
        }
        $names = $tags === null
            ? '[a-z][a-z0-9_-]+'
            : implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $tags));
        // Escaped shortcodes ([[tag]]) print literally in WordPress; keep them as text.
        $html = $this->replace('/\[\[(\/?(?:' . $names . ')(?![\w-])[^\[\]]*)\]\]/', "\u{E002}$1\u{E003}", $html);
        $html = $this->replace('/\[\/?(?:' . $names . ')(?![\w-])(?:[^\[\]"\']|"[^"]*"|\'[^\']*\')*\]/', '', $html);
        return strtr($html, ["\u{E002}" => '[', "\u{E003}" => ']']);
    }

    private function parse(string $html): \DOMElement
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="finetune-export-root">' . $html . '</div>',
            LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $doc->getElementsByTagName('div')->item(0) : null;
        if (!$root instanceof \DOMElement) {
            throw new FinetuneExportException('The post HTML could not be parsed.');
        }
        return $root;
    }

    private function prune(\DOMNode $node, bool $keepAlt): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::REMOVED_ELEMENTS, true) || in_array($tag, ['template', 'source', 'track', 'video', 'audio', 'canvas', 'button', 'input', 'select', 'textarea'], true)) {
                $node->removeChild($child);
                continue;
            }
            if ($tag === 'img') {
                $alt = trim($child->getAttribute('alt'));
                if ($keepAlt && $alt !== '') {
                    $node->replaceChild($node->ownerDocument?->createTextNode(' ' . $alt . ' ') ?? $child, $child);
                } else {
                    $node->removeChild($child);
                }
                continue;
            }
            $this->prune($child, $keepAlt);
        }
    }

    private function renderText(\DOMNode $node, bool $markdown, bool $inList = false, bool $pre = false): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $child->data;
                if (!$pre) {
                    $text = (string) preg_replace('/\s+/u', ' ', $text);
                }
                if ($markdown && !$pre) {
                    $text = $this->escapeMarkdown($text);
                }
                $out .= $text;
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $out .= $this->renderElement($child, $tag, $markdown, $inList, $pre);
        }
        return $out;
    }

    private function renderElement(\DOMElement $el, string $tag, bool $markdown, bool $inList, bool $pre): string
    {
        if ($tag === 'br') {
            return "\n";
        }
        if ($tag === 'hr') {
            return "\n\n";
        }
        if ($tag === 'ul' || $tag === 'ol') {
            $items = '';
            $n = 0;
            foreach ($el->childNodes as $item) {
                if (!$item instanceof \DOMElement || strtolower($item->tagName) !== 'li') {
                    continue;
                }
                $n++;
                $marker = ($markdown && $tag === 'ol') ? $n . '. ' : '- ';
                $body = trim((string) preg_replace('/\s*\n\s*/', ' ', $this->renderText($item, $markdown, true, $pre)));
                if ($body !== '') {
                    $items .= "\n" . $marker . $body;
                }
            }
            return $inList ? $items : "\n\n" . $items . "\n\n";
        }
        if ($tag === 'pre') {
            $inner = $this->renderText($el, false, $inList, true);
            return $markdown ? "\n\n```\n" . trim($inner, "\n") . "\n```\n\n" : "\n\n" . $inner . "\n\n";
        }
        $inner = $this->renderText($el, $markdown, $inList, $pre);
        if ($markdown) {
            if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
                return "\n\n" . str_repeat('#', (int) $m[1]) . ' ' . trim((string) preg_replace('/\s+/', ' ', $inner)) . "\n\n";
            }
            if ($tag === 'em' || $tag === 'i') {
                return $this->wrapInline($inner, '*');
            }
            if ($tag === 'strong' || $tag === 'b') {
                return $this->wrapInline($inner, '**');
            }
            if ($tag === 'a') {
                $href = self::safeHref($el->getAttribute('href'));
                $text = trim($inner);
                return ($href === null || $text === '') ? $inner : '[' . $text . '](' . str_replace(['(', ')', ' '], ['%28', '%29', '%20'], $href) . ')';
            }
            if ($tag === 'blockquote') {
                $body = trim($this->normalise($inner, false));
                $lines = array_map(static fn (string $l): string => $l === '' ? '>' : '> ' . $l, explode("\n", $body));
                return "\n\n" . implode("\n", $lines) . "\n\n";
            }
        }
        if (in_array($tag, self::BLOCK_ELEMENTS, true) || in_array($tag, ['td', 'th'], true)) {
            if ($inList) {
                return ' ' . $inner . ' ';
            }
            if ($tag === 'td' || $tag === 'th') {
                return ' ' . $inner . ' ';
            }
            return "\n\n" . $inner . "\n\n";
        }
        return $inner;
    }

    private function wrapInline(string $inner, string $mark): string
    {
        $trimmed = trim($inner);
        if ($trimmed === '') {
            return $inner;
        }
        $lead = $inner !== ltrim($inner) ? ' ' : '';
        $tail = $inner !== rtrim($inner) ? ' ' : '';
        return $lead . $mark . $trimmed . $mark . $tail;
    }

    private function escapeMarkdown(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]])/', '\\\\$1', $text);
    }

    private function renderHtml(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= htmlspecialchars((string) preg_replace('/\s+/u', ' ', $child->data), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $tag = self::HTML_RENAME[$tag] ?? $tag;
            $inner = $this->renderHtml($child);
            if ($tag === 'br') {
                $out .= "\n";
                continue;
            }
            if (!in_array($tag, self::HTML_ALLOWED, true)) {
                $out .= in_array($tag, self::BLOCK_ELEMENTS, true) ? "\n" . $inner . "\n" : $inner;
                continue;
            }
            if ($tag === 'a') {
                $href = self::safeHref($child->getAttribute('href'));
                $out .= $href === null
                    ? $inner
                    : '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '">' . $inner . '</a>';
                continue;
            }
            if (trim($inner) === '' && $tag !== 'li') {
                continue;
            }
            $block = in_array($tag, self::BLOCK_ELEMENTS, true);
            $out .= '<' . $tag . '>' . trim($inner) . '</' . $tag . '>' . ($block ? "\n" : '');
        }
        return $out;
    }

    /**
     * Keeps http, https, mailto and tel links and relative paths. Anything
     * else (javascript:, data:) is dropped.
     */
    public static function safeHref(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return null;
        }
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $href, $m) === 1) {
            return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true) ? $href : null;
        }
        return $href;
    }

    private function normalise(string $text, bool $html): string
    {
        // Entities were decoded by the HTML parser (case 24); the html format
        // keeps its own escaping.
        if ($this->useIntl) {
            $normalised = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normalised)) {
                $text = $normalised;
            }
        }
        // Non-breaking spaces become spaces; zero-width space, word joiner and
        // BOM go. ZWJ and ZWNJ stay: they are spelling in Indic scripts,
        // Persian and emoji sequences (see ARCHITECTURE.md).
        $text = strtr($text, [
            "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2007}" => ' ',
            "\u{200B}" => '', "\u{2060}" => '', "\u{FEFF}" => '',
        ]);
        $text = $this->replace('/[^\P{Cc}\n\t]/u', '', $text);
        $text = $this->replace('/[ \t]+/u', ' ', $text);
        $text = $this->replace('/ ?\n ?/u', "\n", $text);
        $text = $this->replace($html ? '/\n{2,}/' : '/\n{3,}/', $html ? "\n" : "\n\n", $text);
        return trim($text);
    }

    private function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);
        if ($result === null) {
            throw new RegexLimitExceeded('Cleaning failed: ' . preg_last_error_msg());
        }
        return $result;
    }
}

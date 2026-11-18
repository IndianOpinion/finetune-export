<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

/**
 * Case 40: mailto and tel links become the email and phone placeholders;
 * every other URL keeps its scheme, host and path and loses its user
 * information, query string and fragment. Counted as `url` when something
 * was removed.
 */
final class UrlRule implements RedactionRule
{
    public function type(): string
    {
        return 'url';
    }

    public function apply(string $text, RedactionContext $context): RedactionResult
    {
        $text = Redactor::replaceCallback(
            '/(?<![\w.+-])mailto:[^\s<>"\'()\[\]]+/i',
            static fn (array $m): string => $context->placeholder('email'),
            $text
        );
        $text = Redactor::replaceCallback(
            '/(?<![\w.+-])tel:\+?[\d().\-]+\d/i',
            static fn (array $m): string => $context->placeholder('phone'),
            $text
        );
        $text = Redactor::replaceCallback(
            '/\b(?:(?:https?|ftp):\/\/|www\.)[^\s<>"\'\]\)|]+/iu',
            static function (array $m) use ($context): string {
                $url = $m[0];
                $tail = '';
                if (preg_match('/[.,;:!?]+$/', $url, $t) === 1) {
                    $tail = $t[0];
                    $url = substr($url, 0, -strlen($tail));
                }
                $clean = self::cut($url);
                if ($clean !== $url) {
                    $context->count('url');
                }
                return $clean . $tail;
            },
            $text
        );
        return new RedactionResult($text);
    }

    /**
     * Removes user information, query string and fragment from a URL.
     */
    public static function cut(string $url): string
    {
        $cut = strcspn($url, '?#');
        $url = substr($url, 0, $cut);
        return (string) preg_replace('/^((?:[a-z][a-z0-9+.-]*:)?\/\/)[^\/@]*@/i', '$1', $url);
    }
}

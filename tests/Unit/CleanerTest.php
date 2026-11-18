<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Unit;

use IndianOpinion\FinetuneExport\Cleaner;
use IndianOpinion\FinetuneExport\CleanOptions;
use PHPUnit\Framework\TestCase;

final class CleanerTest extends TestCase
{
    private function clean(string $html, string $format = 'text', ?CleanOptions $options = null, ?bool $intl = null): string
    {
        return (new Cleaner($intl))->clean($html, $format, $options ?? new CleanOptions());
    }

    /** Test 19: block comments and shortcodes go; enclosed shortcode text stays; dynamic blocks drop. */
    public function testBlocksAndShortcodes(): void
    {
        $html = '<!-- wp:paragraph --><p>Intro [gallery ids="1,2"] [note type="x"]kept text[/note]</p><!-- /wp:paragraph -->'
            . '<!-- wp:latest-posts {"postsToShow":3} /-->'
            . '<!-- wp:custom/feed --><div>feed markup</div><!-- /wp:custom/feed -->';
        $out = $this->clean($html, 'text', new CleanOptions(dynamicBlocks: ['custom/feed']));
        self::assertSame('Intro kept text', $out);
        self::assertStringNotContainsString('wp:', $out);
    }

    public function testEscapedShortcodeStaysLiteral(): void
    {
        self::assertSame('Type [gallery] to show images.', $this->clean('<p>Type [[gallery]] to show images.</p>'));
    }

    public function testOnlyRegisteredShortcodesWhenListGiven(): void
    {
        self::assertSame('[1] and', $this->clean('<p>[1] [caption]and[/caption]</p>', 'text', new CleanOptions(shortcodes: ['caption'])));
    }

    /** Test 20: listed elements vanish with their content. */
    public function testRemovedElements(): void
    {
        $html = '<p>Keep</p>';
        foreach (Cleaner::REMOVED_ELEMENTS as $tag) {
            $html .= '<' . $tag . '>secret-' . $tag . '</' . $tag . '>';
        }
        $out = $this->clean($html);
        self::assertSame('Keep', $out);
        foreach (['text', 'markdown', 'html'] as $format) {
            self::assertStringNotContainsString('secret', $this->clean($html, $format));
        }
    }

    /** Test 21: the three formats. */
    public function testFormats(): void
    {
        $html = '<h2>Heading</h2><p>A <strong>bold</strong> and <em>soft</em> <a href="https://example.org/a" class="x" onclick="evil()">link</a>.</p>'
            . '<ul><li>One</li><li>Two</li></ul><ol><li>First</li><li>Second</li></ol><blockquote><p>Quoted</p></blockquote>'
            . '<div class="box"><span style="color:red">Plain</span></div><p><a href="javascript:alert(1)">bad</a></p>';
        self::assertSame(
            "Heading\n\nA bold and soft link.\n\n- One\n- Two\n\n- First\n- Second\n\nQuoted\n\nPlain\n\nbad",
            $this->clean($html, 'text')
        );
        self::assertSame(
            "## Heading\n\nA **bold** and *soft* [link](https://example.org/a).\n\n- One\n- Two\n\n1. First\n2. Second\n\n> Quoted\n\nPlain\n\nbad",
            $this->clean($html, 'markdown')
        );
        $out = $this->clean($html, 'html');
        self::assertSame(
            "<h2>Heading</h2>\n<p>A <strong>bold</strong> and <em>soft</em> <a href=\"https://example.org/a\">link</a>.</p>\n"
            . "<ul><li>One</li>\n<li>Two</li></ul>\n<ol><li>First</li>\n<li>Second</li></ol>\n<blockquote><p>Quoted</p></blockquote>\n"
            . "Plain\n<p>bad</p>",
            $out
        );
        self::assertStringNotContainsString('class=', $out);
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringNotContainsString('style', $out);
        self::assertStringNotContainsString('javascript', $out);
    }

    /** Test 22: entities decoded; NFC with intl; left alone without. */
    public function testEntitiesAndNfc(): void
    {
        self::assertSame('Tom & Jerry’s “show” ©', $this->clean('<p>Tom &amp; Jerry&#8217;s &ldquo;show&rdquo; &copy;</p>'));
        $decomposed = "cafe\u{0301}";
        if (class_exists(\Normalizer::class)) {
            self::assertSame("caf\u{00E9}", $this->clean('<p>' . $decomposed . '</p>', 'text', null, true));
        }
        $cleaner = new Cleaner(false);
        self::assertFalse($cleaner->nfcAvailable());
        self::assertSame($decomposed, $cleaner->clean('<p>' . $decomposed . '</p>', 'text', new CleanOptions()));
    }

    /** Test 23: whitespace, zero-width and control characters. */
    public function testWhitespaceAndControlCharacters(): void
    {
        $html = "<p>  a\u{00A0}b \t\t c\u{200B}d\u{FEFF}e\u{0007}f  </p>\n\n\n<p>g</p>";
        self::assertSame("a b cdef\n\ng", $this->clean($html));
        // ZWJ and ZWNJ are spelling in Indic scripts and stay.
        self::assertSame("क्\u{200D}ष", $this->clean("<p>क्\u{200D}ष</p>"));
    }

    /** Test 24: invalid UTF-8 repaired; ratio measured. */
    public function testInvalidUtf8(): void
    {
        $repaired = Cleaner::repairUtf8("ok \xC3\x28 end");
        self::assertTrue(mb_check_encoding($repaired, 'UTF-8'));
        self::assertStringContainsString("\u{FFFD}", $repaired);
        self::assertSame(0.0, Cleaner::replacementRatio('clean'));
        self::assertGreaterThan(0.05, Cleaner::replacementRatio(Cleaner::repairUtf8("\xFF\xFF\xFFabc")));
    }

    /** Test 25: images go; alt text only with the option. */
    public function testImages(): void
    {
        $html = '<p>Before <img src="a.jpg" alt="A red door"> after</p>';
        self::assertSame('Before after', $this->clean($html));
        self::assertSame('Before A red door after', $this->clean($html, 'text', new CleanOptions(keepImageAlt: true)));
    }
}

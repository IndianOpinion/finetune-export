<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Unit;

use IndianOpinion\FinetuneExport\Links\LinkCheck;
use IndianOpinion\FinetuneExport\Links\LinkSigner;
use IndianOpinion\FinetuneExport\Settings;
use IndianOpinion\FinetuneExport\Tests\TestCase;

final class LinkSignerTest extends TestCase
{
    private function signer(string $salts = 'salts'): LinkSigner
    {
        return new LinkSigner($salts, 'secret', $this->clock);
    }

    /** Test 63: default lifetime 60 minutes, clamped to 5 minutes .. 7 days. */
    public function testLifetime(): void
    {
        $settings = new Settings();
        self::assertSame(3600, $settings->linkTtl());
        self::assertSame(300, $settings->linkTtl(1));
        self::assertSame(7 * 86400, $settings->linkTtl(100000));
        add_filter('finetune_export_link_ttl_max', static fn (): int => 7200);
        self::assertSame(7200, $settings->linkTtl(1000));
    }

    /** Test 64: no file extension, no dot. */
    public function testTokenShape(): void
    {
        $token = $this->signer()->issue(5, 2, 60)['token'];
        self::assertSame(LinkSigner::TOKEN_LENGTH, strlen($token));
        self::assertMatchesRegularExpression(LinkSigner::TOKEN_PATTERN, $token);
        self::assertStringNotContainsString('.', $token);
        $url = \IndianOpinion\FinetuneExport\Links\LinkService::url($token);
        self::assertSame('https://example.org/wp-json/finetune-export/v1/download/' . $token, $url);
        self::assertDoesNotMatchRegularExpression('/\.(jsonl|gz|json|zip)$/', $url);
    }

    /** Test 65: tampering fails; hash_equals is used. */
    public function testTamperAndUser(): void
    {
        $signer = $this->signer();
        $issued = $signer->issue(5, 2, 60, 'validation');
        $check = $signer->verify($issued['token'], 2);
        self::assertTrue($check->ok());
        self::assertSame(5, $check->jobId);
        self::assertSame('validation', $check->kind);
        self::assertSame($issued['nonce'], $check->nonce);
        $token = $issued['token'];
        $flipped = substr($token, 0, 10) . ($token[10] === 'A' ? 'B' : 'A') . substr($token, 11);
        self::assertSame(LinkCheck::BAD_SIGNATURE, $signer->verify($flipped, 2)->status);
        self::assertSame(LinkCheck::WRONG_USER, $signer->verify($token, 3)->status);
        self::assertSame(LinkCheck::MALFORMED, $signer->verify($token . 'x', 2)->status);
        self::assertSame(LinkCheck::MALFORMED, $signer->verify('../../etc/passwd', 2)->status);
        self::assertSame(LinkCheck::BAD_SIGNATURE, $this->signer('rotated')->verify($token, 2)->status);
        $this->clock->advance(61);
        self::assertSame(LinkCheck::EXPIRED, $signer->verify($token, 2)->status);
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Links/LinkSigner.php');
        self::assertStringContainsString('hash_equals(', $source);
    }
}

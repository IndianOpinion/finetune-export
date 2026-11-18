<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Integration;

use IndianOpinion\FinetuneExport\Exporter;
use IndianOpinion\FinetuneExport\ExportFailed;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Jobs\OptionLock;
use IndianOpinion\FinetuneExport\Links\LinkCheck;
use IndianOpinion\FinetuneExport\Links\LinkSigner;
use IndianOpinion\FinetuneExport\PermissionDenied;
use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Profiles;
use IndianOpinion\FinetuneExport\Storage\Canary;
use IndianOpinion\FinetuneExport\Storage\Storage;
use IndianOpinion\FinetuneExport\Storage\StorageEnvironment;
use IndianOpinion\FinetuneExport\Support\Logger;
use IndianOpinion\FinetuneExport\Tests\TestCase;
use IndianOpinion\FinetuneExport\UnsafeDirectory;

/**
 * Regression tests for the defects found in code review: web roots beyond
 * ABSPATH, an atomic batch lock, strict file name and token patterns, the
 * canary's handling of redirects, deleting the files of a stalled job, and
 * name sources as a redaction switch.
 */
final class HardeningTest extends TestCase
{
    private function envWithRoots(?string $constantDir, array $webRoots, ?string $ack = null): StorageEnvironment
    {
        return new StorageEnvironment(ABSPATH, WP_CONTENT_DIR, WP_CONTENT_DIR . '/uploads', $constantDir, null, $ack, 0, $webRoots);
    }

    /** WordPress in its own subdirectory: the default one level above ABSPATH is in the served home directory. */
    public function testDefaultInsideHomeDirectoryIsRefused(): void
    {
        $home = dirname(rtrim(ABSPATH, '/'));
        try {
            $this->storage($this->envWithRoots(null, [$home]))->resolve();
            self::fail('Expected UnsafeDirectory');
        } catch (UnsafeDirectory $e) {
            self::assertStringContainsString('inside the web root', $e->getMessage());
        }
        $default = $home . '/' . Storage::DEFAULT_NAME;
        self::assertTrue($this->storage($this->envWithRoots(null, [$home], $default))->resolve()->insideWebRoot);
        self::assertFalse($this->storage($this->envWithRoots($this->root . '/outside', [$home]))->resolve()->insideWebRoot);
    }

    public function testDocumentRootCountsAsWebRoot(): void
    {
        $docroot = $this->root . '/www';
        $this->expectException(UnsafeDirectory::class);
        $this->storage($this->envWithRoots($docroot . '/exports', [$docroot]))->resolve();
    }

    public function testFilesystemRootIsNotTreatedAsWebRoot(): void
    {
        self::assertSame([rtrim(ABSPATH, '/'), WP_CONTENT_DIR], Storage::webRoots($this->envWithRoots(null, ['/', ''])));
    }

    public function testHomeDirectory(): void
    {
        self::assertSame('/srv/web', Storage::homeDirectory('/srv/web/wp/', 'https://example.org', 'https://example.org/wp'));
        self::assertSame('/srv/web', Storage::homeDirectory('/srv/web/cms/wp/', 'https://example.org/', 'https://example.org/cms/wp/'));
        self::assertSame('/srv/web', Storage::homeDirectory('/srv/web/wp/', 'https://example.org/blog', 'https://example.org/blog/wp'));
        self::assertNull(Storage::homeDirectory('/srv/web/', 'https://example.org', 'https://example.org'));
        self::assertNull(Storage::homeDirectory('/srv/other/', 'https://example.org', 'https://example.org/wp'));
    }

    public function testPluginReportsHomeDirectoryAndDocumentRoot(): void
    {
        $plugin = $this->plugin();
        $_SERVER['DOCUMENT_ROOT'] = $this->root . '/docroot';
        try {
            self::assertSame([$this->root . '/docroot' => 'https://example.org'], $plugin->webRootUrls());
            self::assertContains($this->root . '/docroot', $plugin->storageEnvironment()->webRoots);
        } finally {
            unset($_SERVER['DOCUMENT_ROOT']);
        }
    }

    /** A redirect or a server error is not evidence the directory is blocked. */
    public function testCanaryRedirectIsUnknown(): void
    {
        $inside = ABSPATH . 'private-exports';
        $storage = $this->storage($this->envWithRoots($inside, [], $inside));
        $canary = new Canary($storage, new Logger(), $this->clock);
        foreach ([301, 302, 500, 200] as $status) {
            \WpStub::$httpResponder = static fn (): array => ['response' => ['code' => $status], 'body' => 'elsewhere'];
            self::assertSame(Canary::UNKNOWN, $canary->run()['result'], 'status ' . $status);
        }
        \WpStub::$httpResponder = static fn (): array => ['response' => ['code' => 404], 'body' => 'Not found'];
        self::assertSame(Canary::SAFE, $canary->run()['result']);
    }

    /** The canary is requested through the deepest web root that holds the directory. */
    public function testCanaryUsesDeepestWebRootUrl(): void
    {
        $docroot = $this->root . '/www';
        $inside = $docroot . '/exports';
        $storage = $this->storage($this->envWithRoots($inside, [$docroot], $inside));
        $canary = new Canary($storage, new Logger(), $this->clock, [
            (string) Storage::normalise(ABSPATH) => 'https://example.org/wp',
            $docroot => 'https://example.org',
        ]);
        \WpStub::$httpResponder = static fn (): array => ['response' => ['code' => 403], 'body' => ''];
        $canary->run();
        self::assertStringStartsWith('https://example.org/exports/canary-', \WpStub::$http[0]['url']);
    }

    public function testFileNamesWithTrailingNewlineAreRefused(): void
    {
        $name = 'export-1-' . str_repeat('a', 32) . ".jsonl\n";
        $this->expectException(\InvalidArgumentException::class);
        $this->storage()->path($name);
    }

    public function testTokenWithTrailingNewlineIsMalformed(): void
    {
        $signer = new LinkSigner('salts', str_repeat('b', 64), $this->clock);
        $token = $signer->issue(1, 1, 3600)['token'];
        self::assertTrue($signer->verify($token, 1)->ok());
        self::assertSame(LinkCheck::MALFORMED, $signer->verify($token . "\n", 1)->status);
    }

    /** The lock lives in the options table and a second request cannot create or take a live one. */
    public function testLockIsAtomicRow(): void
    {
        global $wpdb;
        $a = new OptionLock($this->clock, $wpdb);
        $b = new OptionLock($this->clock, $wpdb);
        self::assertTrue($a->acquire(1, Exporter::LOCK_TTL));
        self::assertArrayNotHasKey(OptionLock::OPTION, \WpStub::$options, 'not written through add_option');
        self::assertFalse($b->acquire(1, Exporter::LOCK_TTL), 'same job, other request');
        self::assertFalse($b->acquire(2, Exporter::LOCK_TTL));
        self::assertTrue($a->acquire(1, Exporter::LOCK_TTL), 'the owner renews');
        $b->release(2);
        self::assertSame(1, $b->holder(), 'releasing another job leaves the lock');
        $this->clock->advance(Exporter::LOCK_TTL + 1);
        self::assertNull($a->holder());
        self::assertTrue($b->acquire(2, Exporter::LOCK_TTL), 'an expired lock is taken over');
        self::assertFalse($a->acquire(1, Exporter::LOCK_TTL));
        $b->release(2);
        self::assertNull($a->holder());
        self::assertTrue($a->acquire(1, Exporter::LOCK_TTL));
    }

    /** Deleting files of a stalled job would let a resume write into a removed file. */
    public function testStalledJobFilesCannotBeDeleted(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_content' => '<p>One.</p>']);
        \WpStub::addPost(['ID' => 2, 'post_content' => '<p>Two.</p>']);
        add_filter('finetune_export_batch_size', static fn (): int => 1);
        $exporter = $this->exporter();
        $job = $exporter->step($exporter->start($this->profile(), 1)->id);
        $this->clock->advance(Exporter::STALL_SECONDS + 1);
        self::assertCount(1, $exporter->detectStalled());
        try {
            $exporter->removeFiles($job->id, 'operator', 1);
            self::fail('Expected ExportFailed');
        } catch (ExportFailed $e) {
            self::assertSame('job_active', $e->reason);
        }
        $exporter->cancel($job->id, 1);
        $job = $exporter->removeFiles($job->id, 'operator', 1);
        self::assertSame('removed', $job->fileState(Job::FILE_TRAIN));
    }

    /** Switching name sources off is name redaction off by another route: it needs the sensitive capability. */
    public function testNameSourceOffIsSensitive(): void
    {
        $profile = Profile::fromArray(['name_sources' => ['users' => false, 'honorifics' => false]]);
        self::assertSame(['name_source_off:users', 'name_source_off:honorifics'], $profile->sensitiveChoices());
        Profiles::assertAllowed($profile, true);
        $off = Profile::fromArray(['redaction' => ['name' => false], 'name_sources' => ['users' => false]]);
        self::assertSame(['redaction_off:name'], $off->sensitiveChoices());
        $this->expectException(PermissionDenied::class);
        Profiles::assertAllowed($profile, false);
    }
}

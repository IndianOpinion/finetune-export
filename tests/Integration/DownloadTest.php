<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Integration;

use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Rest\DownloadHandler;
use IndianOpinion\FinetuneExport\Rest\RestController;
use IndianOpinion\FinetuneExport\Settings;
use IndianOpinion\FinetuneExport\Storage\Canary;
use IndianOpinion\FinetuneExport\Tests\RecordingSink;
use IndianOpinion\FinetuneExport\Tests\TestCase;

final class DownloadTest extends TestCase
{
    private Plugin $plugin;
    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::addUser(1, 'Admin One', [Plugin::CAP_RUN, Plugin::CAP_SENSITIVE]);
        \WpStub::addUser(2, 'Admin Two', [Plugin::CAP_RUN]);
        \WpStub::addUser(3, 'Subscriber');
        for ($i = 1; $i <= 3; $i++) {
            \WpStub::addPost(['ID' => $i, 'post_content' => '<p>Story ' . $i . '.</p>']);
        }
        $this->plugin = $this->plugin();
        $exporter = $this->plugin->exporter();
        $this->job = $exporter->runForeground($exporter->start($this->profile(), 1)->id);
    }

    private function token(int $user = 1, int $ttl = 3600, string $kind = Job::FILE_TRAIN): string
    {
        $url = $this->plugin->links()->issue($this->job->id, $user, $ttl, $kind)['url'];
        return substr($url, strrpos($url, '/') + 1);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $preset
     */
    private function download(string $token, int $user = 1, string $method = 'GET', array $headers = [], array $preset = []): RecordingSink
    {
        \WpStub::$currentUser = 0;
        \WpStub::$cookieUser = $user;
        $request = new \WP_REST_Request($method);
        $request->set_param('token', $token);
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        $sink = new RecordingSink($preset);
        (new RestController($this->plugin))->download($request, $sink);
        return $sink;
    }

    /** Tests 67 and 69: cookie-only request succeeds with every header and a streamed body. */
    public function testSuccessfulDownload(): void
    {
        $token = $this->token();
        $sink = $this->download($token, 1, 'GET', [], [
            'Access-Control-Allow-Origin' => '*',
            'Link' => '<https://example.org/wp-json/>; rel="https://api.w.org/"',
        ]);
        $file = (string) file_get_contents($this->plugin->storage()->path((string) $this->job->fileName('train')));
        self::assertSame(200, $sink->status);
        self::assertSame($file, $sink->body());
        self::assertTrue($sink->ended);
        self::assertSame([
            'Content-Type' => 'application/x-ndjson',
            'Content-Disposition' => 'attachment; filename="finetune-export-' . gmdate('Y-m-d', $this->clock->now) . '.jsonl"',
            'Content-Length' => (string) strlen($file),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Accel-Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ], $sink->headers);
        $job = $this->jobs->find($this->job->id);
        self::assertSame('success', $job?->downloads[0]['outcome']);
        self::assertArrayNotHasKey('ip', $job->downloads[0]);
    }

    public function testStreamsInChunks(): void
    {
        $sink = new RecordingSink();
        $handler = new DownloadHandler(
            $this->plugin->signer(),
            $this->jobs,
            $this->plugin->storage(),
            $this->plugin->logger(),
            $this->clock,
            ['https://example.org'],
            static fn (): bool => true,
            static fn (): bool => false,
            false,
            16
        );
        $handler->handle(new \IndianOpinion\FinetuneExport\Rest\DownloadRequest($this->token()), 1, $sink);
        self::assertGreaterThan(3, count($sink->chunks));
        foreach ($sink->chunks as $chunk) {
            self::assertLessThanOrEqual(16, strlen($chunk));
        }
    }

    /** Test 70: HEAD returns the headers and no body. */
    public function testHead(): void
    {
        $sink = $this->download($this->token(), 1, 'HEAD');
        self::assertSame(200, $sink->status);
        self::assertSame('', $sink->body());
        self::assertArrayHasKey('Content-Length', $sink->headers);
    }

    /** Test 68: each refusal path and its status; identical 404 bodies; 401 before any lookup; expired is 404 (ruling 3). */
    public function testRefusals(): void
    {
        $token = $this->token();
        $bodies = [];

        $malformed = $this->download('not-a-token');
        self::assertSame(404, $malformed->status);
        $bodies[] = $malformed->body();

        $foreign = $this->download($token, 1, 'GET', ['Origin' => 'https://evil.example']);
        self::assertSame(404, $foreign->status);
        $bodies[] = $foreign->body();
        self::assertSame(200, $this->download($token, 1, 'GET', ['Origin' => 'https://example.org'])->status);

        $before = count((array) $this->jobs->find($this->job->id)?->downloads);
        $anonymous = $this->download($token, 0);
        self::assertSame(401, $anonymous->status);
        self::assertCount($before, (array) $this->jobs->find($this->job->id)?->downloads, 'no lookup or record for anonymous');

        $wrongUser = $this->download($token, 2);
        self::assertSame(404, $wrongUser->status);
        $bodies[] = $wrongUser->body();

        $tampered = $this->download(substr($token, 0, 20) . ($token[20] === 'a' ? 'b' : 'a') . substr($token, 21));
        self::assertSame(404, $tampered->status);
        $bodies[] = $tampered->body();

        $noCap = $this->download($this->token(1), 3);
        self::assertSame(404, $noCap->status);
        $bodies[] = $noCap->body();

        $this->clock->advance(3601);
        $expired = $this->download($token);
        self::assertSame(404, $expired->status);
        $bodies[] = $expired->body();

        self::assertCount(1, array_unique($bodies));
        self::assertSame(DownloadHandler::NOT_FOUND_BODY, $bodies[0]);
    }

    /** Test 68: a user who lost the capability gets 404. */
    public function testLostCapability(): void
    {
        $token = $this->token(2);
        \WpStub::$caps[2] = [];
        self::assertSame(404, $this->download($token, 2)->status);
    }

    /** Test 66 and 93: revoked links and links to removed files give 404. */
    public function testRevokedAndRemoved(): void
    {
        $token = $this->token();
        $this->plugin->links()->revokeAll($this->job->id, 1);
        self::assertSame(404, $this->download($token)->status);
        $token = $this->token();
        $this->plugin->exporter()->removeFiles($this->job->id, 'operator', 1);
        self::assertSame(404, $this->download($token)->status);
        $outcomes = array_column((array) $this->jobs->find($this->job->id)?->downloads, 'outcome');
        self::assertSame(['revoked', 'revoked'], $outcomes);
    }

    public function testUnknownJobAndMissingFile(): void
    {
        $token = $this->token();
        unlink($this->plugin->storage()->path((string) $this->job->fileName('train')));
        self::assertSame(404, $this->download($token)->status);
        $this->jobs->delete($this->job->id);
        self::assertSame(404, $this->download($token)->status);
    }

    /** Test 74 (download side): a .part file cannot be downloaded. */
    public function testPartNotDownloadable(): void
    {
        add_filter('finetune_export_batch_size', static fn (): int => 1);
        $exporter = $this->plugin->exporter();
        $running = $exporter->step($exporter->start($this->profile(), 1)->id);
        self::assertSame(Job::RUNNING, $running->state);
        $this->expectException(\IndianOpinion\FinetuneExport\ExportFailed::class);
        $this->plugin->links()->issue($running->id, 1, 3600);
    }

    /** Test 71: no IP by default; IP with the filter. */
    public function testIpLogging(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->download($this->token());
        add_filter('finetune_export_log_download_ip', '__return_true');
        $this->download($this->token());
        unset($_SERVER['REMOTE_ADDR']);
        $downloads = (array) $this->jobs->find($this->job->id)?->downloads;
        self::assertArrayNotHasKey('ip', $downloads[0]);
        self::assertSame('203.0.113.9', $downloads[1]['ip']);
    }

    /** Test 72: no email by default; with the option, only to the requester. */
    public function testEmail(): void
    {
        $this->plugin->links()->issue($this->job->id, 2, 3600);
        self::assertSame([], \WpStub::$mails);
        $this->plugin->links()->issue($this->job->id, 2, 3600, Job::FILE_TRAIN, true);
        self::assertCount(1, \WpStub::$mails);
        self::assertSame('user2@example.org', \WpStub::$mails[0]['to']);
        self::assertStringContainsString('/finetune-export/v1/download/', \WpStub::$mails[0]['message']);
    }

    public function testLinkRefusedForUserWithoutCapability(): void
    {
        $this->expectException(\IndianOpinion\FinetuneExport\PermissionDenied::class);
        $this->plugin->links()->issue($this->job->id, 3, 3600);
    }

    /** Test 59 (download side): no acceptable directory refuses downloads. */
    public function testNoDirectoryRefusesDownload(): void
    {
        $token = $this->token();
        remove_all_filters('finetune_export_directory');
        add_filter('finetune_export_directory', static fn (): string => WP_CONTENT_DIR . '/uploads/exports');
        $this->plugin->resetStorage();
        $sink = $this->download($token);
        self::assertSame(503, $sink->status);
        self::assertSame('', implode('', array_filter($sink->chunks, static fn (string $c): bool => str_contains($c, '{'))));
    }

    /** Test 62: a served canary stops downloads and jobs; a network error is unknown; the canary is removed. */
    public function testCanary(): void
    {
        $inside = ABSPATH . 'private-exports';
        remove_all_filters('finetune_export_directory');
        add_filter('finetune_export_directory', static fn (): string => $inside);
        $this->plugin->settings()->update(['dir_ack_path' => $inside]);
        $this->plugin->resetStorage();
        $token = $this->token();

        \WpStub::$httpResponder = static function (string $url): array {
            $path = ABSPATH . substr((string) parse_url($url, PHP_URL_PATH), 1);
            return ['response' => ['code' => 200], 'body' => (string) file_get_contents($path)];
        };
        $result = $this->plugin->canary()->run();
        self::assertSame(Canary::SERVED, $result['result']);
        self::assertStringStartsWith('https://example.org/private-exports/canary-', \WpStub::$http[0]['url']);
        self::assertSame([], glob($inside . '/canary-*'));
        self::assertTrue($this->plugin->blocked());
        self::assertSame(503, $this->download($token)->status);
        try {
            $this->plugin->exporter()->start($this->profile(), 1);
            self::fail('Expected jobs to stop');
        } catch (\IndianOpinion\FinetuneExport\UnsafeDirectory $e) {
            self::assertStringContainsString('self-check', $e->getMessage());
        }
        $errors = array_filter($this->logs, static fn (array $l): bool => $l[0] === 'error');
        self::assertNotEmpty($errors);

        \WpStub::$httpResponder = static fn (): \WP_Error => new \WP_Error('http_request_failed', 'timeout');
        self::assertSame(Canary::UNKNOWN, $this->plugin->canary()->run()['result']);
        self::assertFalse($this->plugin->blocked());
        self::assertSame([], glob($inside . '/canary-*'));

        \WpStub::$httpResponder = static fn (): array => ['response' => ['code' => 403], 'body' => 'Forbidden'];
        self::assertSame(Canary::SAFE, $this->plugin->canary()->run()['result']);
        $exporter = $this->plugin->exporter();
        $this->job = $exporter->runForeground($exporter->start($this->profile(), 1)->id);
        self::assertStringStartsWith($inside, $this->plugin->storage()->path((string) $this->job->fileName('train')));
        self::assertSame(200, $this->download($this->token())->status);
    }

    public function testCanaryNotNeededOutsideWebRoot(): void
    {
        self::assertSame(Canary::NOT_NEEDED, $this->plugin->canary()->run()['result']);
        self::assertSame([], \WpStub::$http);
        self::assertSame(Canary::NOT_NEEDED, get_option(Settings::CANARY_OPTION)['result']);
    }

    public function testRoutesRegistered(): void
    {
        (new RestController($this->plugin))->register();
        $routes = array_column(\WpStub::$routes, 1);
        self::assertContains('/download/(?P<token>[A-Za-z0-9_-]{1,200})', $routes);
        self::assertContains('/preview', $routes);
        self::assertContains('/jobs', $routes);
        self::assertSame(['GET', 'HEAD'], \WpStub::$routes[0][2]['methods']);
    }
}

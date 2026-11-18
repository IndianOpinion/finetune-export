<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Rest;

use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Jobs\JobRepository;
use IndianOpinion\FinetuneExport\Links\LinkCheck;
use IndianOpinion\FinetuneExport\Links\LinkSigner;
use IndianOpinion\FinetuneExport\Storage\Storage;
use IndianOpinion\FinetuneExport\Support\Clock;
use IndianOpinion\FinetuneExport\Support\Logger;
use IndianOpinion\FinetuneExport\UnsafeDirectory;

/**
 * The download route (PRD cases 73 to 77, lessons 4 to 10).
 *
 * Order: malformed token 404; foreign Origin 404; no signed-in user 401,
 * before any lookup; user without the capability 404; directory unsafe or
 * canary served 503; then bad signature, wrong user, expired (ruling 3),
 * revoked, unknown job, file not downloadable or missing: all 404 with one
 * identical body. A success streams the file and ends the request.
 */
final class DownloadHandler
{
    public const NOT_FOUND_BODY = 'Not found.';
    public const CHUNK = 1048576;
    /** Headers another layer may have added earlier in the request. */
    public const STRIPPED_HEADERS = [
        'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods',
        'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers', 'Access-Control-Max-Age',
        'Link', 'Vary', 'Expires', 'Last-Modified', 'Pragma', 'ETag', 'Set-Cookie',
    ];

    /** @var callable(int): bool */
    private $userCan;
    /** @var callable(): bool */
    private $blocked;

    /**
     * @param list<string>           $siteOrigins Origins of the site (home and site URL).
     * @param callable(int): bool    $userCan     Whether a user holds the run capability now.
     * @param callable(): bool       $blocked     True while the canary check stops downloads.
     */
    public function __construct(
        private readonly LinkSigner $signer,
        private readonly JobRepository $jobs,
        private readonly Storage $storage,
        private readonly Logger $logger,
        private readonly Clock $clock,
        private readonly array $siteOrigins,
        callable $userCan,
        callable $blocked,
        private readonly bool $logIp = false,
        private readonly int $chunk = self::CHUNK,
    ) {
        $this->userCan = $userCan;
        $this->blocked = $blocked;
    }

    public function handle(DownloadRequest $request, int $userId, ResponseSink $sink): void
    {
        if (preg_match(LinkSigner::TOKEN_PATTERN, $request->token) !== 1) {
            $this->notFound($sink);
            return;
        }
        if ($request->origin !== null && $request->origin !== '' && !$this->sameOrigin($request->origin)) {
            $this->notFound($sink);
            return;
        }
        if ($userId <= 0) {
            $this->respond($sink, 401, 'Sign in to download this export.');
            return;
        }
        $check = $this->signer->verify($request->token, $userId);
        if (!($this->userCan)($userId)) {
            $this->refuse($sink, $request, $userId, $check, 'no_capability');
            return;
        }
        if (($this->blocked)()) {
            $this->record($check->jobId, $userId, 'blocked', $request);
            $this->respond($sink, 503, 'Downloads are stopped: the export directory is not safe. See the export screen.');
            return;
        }
        try {
            $this->storage->resolve();
        } catch (UnsafeDirectory $e) {
            $this->record($check->jobId, $userId, 'unsafe_directory', $request);
            $this->respond($sink, 503, 'Downloads are stopped: no safe export directory. See the export screen.');
            return;
        }
        if (!$check->ok()) {
            $this->refuse($sink, $request, $userId, $check, $check->status);
            return;
        }
        $job = $this->jobs->find($check->jobId);
        if ($job === null) {
            $this->refuse($sink, $request, $userId, $check, 'unknown_job');
            return;
        }
        if (!isset($job->links[$check->nonce])) {
            $this->refuse($sink, $request, $userId, $check, 'revoked');
            return;
        }
        $name = $job->fileName($check->kind);
        if ($name === null || !$job->downloadable($check->kind) || !$this->storage->exists($name)) {
            $this->refuse($sink, $request, $userId, $check, 'file_unavailable');
            return;
        }

        $size = $this->storage->size($name);
        $gzip = str_ends_with($name, '.gz');
        $date = gmdate('Y-m-d', $job->endedAt ?? $this->clock->now());
        $suffix = $check->kind === Job::FILE_VALIDATION ? '-validation' : '';
        foreach (self::STRIPPED_HEADERS as $header) {
            $sink->removeHeader($header);
        }
        $sink->status(200);
        $sink->header('Content-Type', $gzip ? 'application/gzip' : 'application/x-ndjson');
        $sink->header(
            'Content-Disposition',
            'attachment; filename="finetune-export-' . $date . $suffix . '.jsonl' . ($gzip ? '.gz' : '') . '"'
        );
        $sink->header('Content-Length', (string) $size);
        $sink->header('Cache-Control', 'private, no-store, max-age=0');
        $sink->header('X-Accel-Expires', '0');
        $sink->header('X-Content-Type-Options', 'nosniff');
        $sink->header('X-Robots-Tag', 'noindex, nofollow');
        $sink->header('Referrer-Policy', 'no-referrer');
        $this->record($job->id, $userId, 'success', $request, $job);
        if (strtoupper($request->method) !== 'HEAD') {
            $this->storage->stream($name, static function (string $data) use ($sink): void {
                $sink->write($data);
            }, $this->chunk);
        }
        $sink->end();
    }

    private function refuse(ResponseSink $sink, DownloadRequest $request, int $userId, LinkCheck $check, string $outcome): void
    {
        $this->record($check->jobId, $userId, $outcome, $request);
        $this->logger->info('download refused', [
            'job' => $check->jobId,
            'user' => $userId,
            'outcome' => $outcome,
            'token' => Logger::tokenPrefix($request->token),
        ]);
        $this->notFound($sink);
    }

    private function notFound(ResponseSink $sink): void
    {
        $this->respond($sink, 404, self::NOT_FOUND_BODY);
    }

    private function respond(ResponseSink $sink, int $status, string $message): void
    {
        foreach (self::STRIPPED_HEADERS as $header) {
            $sink->removeHeader($header);
        }
        $sink->status($status);
        $sink->header('Content-Type', 'text/plain; charset=utf-8');
        $sink->header('Cache-Control', 'private, no-store, max-age=0');
        $sink->header('X-Accel-Expires', '0');
        $sink->header('X-Content-Type-Options', 'nosniff');
        $sink->header('X-Robots-Tag', 'noindex, nofollow');
        $sink->header('Referrer-Policy', 'no-referrer');
        $sink->write($message);
        $sink->end();
    }

    /**
     * Records a download or a refusal past the 401 stage on the job (case 77).
     */
    private function record(int $jobId, int $userId, string $outcome, DownloadRequest $request, ?Job $job = null): void
    {
        if ($jobId <= 0) {
            return;
        }
        $job = $job ?? $this->jobs->find($jobId);
        if ($job === null) {
            return;
        }
        $entry = ['at' => $this->clock->now(), 'user' => $userId, 'outcome' => $outcome];
        if ($this->logIp && $request->ip !== null) {
            $entry['ip'] = $request->ip;
        }
        $job->addDownload($entry);
        $this->jobs->save($job);
        do_action('finetune_export_downloaded', $jobId, $userId, $outcome);
        if ($outcome === 'success') {
            $this->logger->info('download', ['job' => $jobId, 'user' => $userId]);
        }
    }

    private function sameOrigin(string $origin): bool
    {
        $origin = strtolower(rtrim($origin, '/'));
        foreach ($this->siteOrigins as $allowed) {
            if (strtolower(rtrim($allowed, '/')) === $origin) {
                return true;
            }
        }
        return false;
    }

    /**
     * scheme://host[:port] of a URL.
     */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        return strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }
}

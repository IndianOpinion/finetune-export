<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Storage;

use IndianOpinion\FinetuneExport\Settings;
use IndianOpinion\FinetuneExport\Support\Clock;
use IndianOpinion\FinetuneExport\Support\Logger;
use IndianOpinion\FinetuneExport\UnsafeDirectory;

/**
 * Web root self-check (PRD case 68). Only runs when the directory is inside
 * the web root (the acknowledged case). Writes a canary with random content,
 * requests it through the site's own URL, removes it. Served means unsafe;
 * a network error means unknown, never safe.
 */
final class Canary
{
    public const SAFE = 'safe';
    public const SERVED = 'served';
    public const UNKNOWN = 'unknown';
    public const NOT_NEEDED = 'not_needed';

    /**
     * @param array<string, string> $urls Web root directory => the URL it is served at. Empty maps
     *                                    ABSPATH to the site URL and the content directory to the
     *                                    content URL.
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly Logger $logger,
        private readonly Clock $clock,
        private readonly array $urls = [],
    ) {
    }

    /**
     * @return array{result: string, at: int, path: string, detail: string}
     */
    public function run(): array
    {
        try {
            $dir = $this->storage->resolve();
        } catch (UnsafeDirectory $e) {
            return $this->store(self::NOT_NEEDED, '', 'no acceptable directory');
        }
        if (!$dir->insideWebRoot) {
            return $this->store(self::NOT_NEEDED, $dir->path, 'directory is outside the web root');
        }
        $url = $this->urlFor($dir->path);
        if ($url === null) {
            return $this->store(self::UNKNOWN, $dir->path, 'could not map the directory to a URL');
        }
        $canary = $this->storage->writeCanary();
        try {
            $response = wp_remote_get($url . '/' . $canary['name'], [
                'timeout' => 10,
                'redirection' => 0,
                'headers' => ['Cache-Control' => 'no-cache'],
            ]);
        } finally {
            $this->storage->removeCanary($canary['name']);
        }
        if (is_wp_error($response)) {
            $this->logger->warning('canary check could not connect; result unknown', ['error' => $response->get_error_message()]);
            return $this->store(self::UNKNOWN, $dir->path, 'network error: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status === 200 && str_contains($body, $canary['content'])) {
            $this->logger->error('export directory is served over HTTP; jobs and downloads stopped', ['status' => $status]);
            return $this->store(self::SERVED, $dir->path, 'the canary was served with status 200');
        }
        if ($status < 400 || $status >= 500) {
            // A redirect may lead to the file over another scheme or host, and a
            // server error says nothing about the deny rule: neither is safe.
            $this->logger->warning('canary check inconclusive; result unknown', ['status' => $status]);
            return $this->store(self::UNKNOWN, $dir->path, 'inconclusive answer (status ' . $status . ')');
        }
        return $this->store(self::SAFE, $dir->path, 'the canary was refused (status ' . $status . ')');
    }

    /**
     * Last stored result.
     *
     * @return array{result: string, at: int, path: string, detail: string}
     */
    public static function last(): array
    {
        $value = get_option(Settings::CANARY_OPTION, []);
        $value = is_array($value) ? $value : [];
        return [
            'result' => (string) ($value['result'] ?? self::NOT_NEEDED),
            'at' => (int) ($value['at'] ?? 0),
            'path' => (string) ($value['path'] ?? ''),
            'detail' => (string) ($value['detail'] ?? ''),
        ];
    }

    /**
     * True while a served canary stops jobs and downloads. A result for an
     * older path does not count: the new path is checked on save.
     */
    public static function blocked(?string $currentPath): bool
    {
        $last = self::last();
        return $last['result'] === self::SERVED && ($currentPath === null || $last['path'] === $currentPath);
    }

    /**
     * URL of a directory through the deepest web root that contains it.
     */
    private function urlFor(string $path): ?string
    {
        $urls = $this->urls;
        if ($urls === []) {
            $env = $this->storage->environment();
            $urls = [
                (string) Storage::normalise($env->abspath) => site_url(),
                (string) Storage::normalise($env->contentDir) => content_url(),
            ];
        }
        $best = null;
        foreach ($urls as $base => $url) {
            $base = rtrim((string) $base, '/');
            if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
                if ($best === null || strlen($base) > strlen($best[0])) {
                    $best = [$base, rtrim($url, '/')];
                }
            }
        }
        return $best === null ? null : $best[1] . substr($path, strlen($best[0]));
    }

    /**
     * @return array{result: string, at: int, path: string, detail: string}
     */
    private function store(string $result, string $path, string $detail): array
    {
        $value = ['result' => $result, 'at' => $this->clock->now(), 'path' => $path, 'detail' => $detail];
        update_option(Settings::CANARY_OPTION, $value, false);
        return $value;
    }
}

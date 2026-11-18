<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Storage;

use IndianOpinion\FinetuneExport\FinetuneExportException;
use IndianOpinion\FinetuneExport\Support\Logger;
use IndianOpinion\FinetuneExport\UnsafeDirectory;

/**
 * The protected directory (PRD section 4.8).
 *
 * Resolution order: the FINETUNE_EXPORT_DIR constant, the directory filter,
 * then `finetune-exports` one level above ABSPATH. The first candidate that
 * is set is the only one considered: if it is refused, nothing else is
 * tried and every job and download stops (fail closed).
 *
 * This class holds the only two file removal calls in the plugin: one for
 * export files, used by retention and operator delete, and one for its own
 * canary probe.
 */
final class Storage
{
    public const DEFAULT_NAME = 'finetune-exports';
    public const FILE_PATTERN = '/^export-(\d{1,19})-([a-f0-9]{32})(-validation)?\.jsonl(\.gz)?(\.part)?$/';
    public const CANARY_PATTERN = '/^canary-[a-f0-9]{32}\.txt$/';
    public const DENY_FILES = ['index.php', '.htaccess', 'web.config'];
    private const CHUNK = 1048576;

    /** @var callable(string): (float|false) */
    private $freeSpace;

    /**
     * @param (callable(string): (float|false))|null $freeSpace Replaces disk_free_space in tests.
     */
    public function __construct(
        private readonly StorageEnvironment $env,
        private readonly Logger $logger,
        ?callable $freeSpace = null,
    ) {
        $this->freeSpace = $freeSpace ?? static fn (string $dir): float|false => disk_free_space($dir);
    }

    public function environment(): StorageEnvironment
    {
        return $this->env;
    }

    /**
     * @throws UnsafeDirectory When no acceptable directory exists.
     */
    public function resolve(): ResolvedDirectory
    {
        [$candidate, $source] = $this->candidate();
        $path = self::normalise($candidate);
        if ($path === null) {
            throw new UnsafeDirectory(
                'The export directory from the ' . $source . ' must be an absolute path without "..".'
                . ' Set FINETUNE_EXPORT_DIR in wp-config.php to a directory outside the web root.'
            );
        }
        $real = self::realish($path);
        foreach ([$this->env->uploadsDir] as $uploads) {
            $uploadsReal = self::realish((string) self::normalise($uploads));
            if ($uploadsReal !== '' && self::inside($real, $uploadsReal)) {
                throw new UnsafeDirectory(
                    'The export directory ' . $path . ' is inside the uploads directory. Uploads are often'
                    . ' copied to object storage and served without the web server, so this is refused even'
                    . ' with the acknowledgement. Set FINETUNE_EXPORT_DIR to a directory outside the web root.'
                );
            }
        }
        $insideWebRoot = false;
        foreach ([$this->env->abspath, $this->env->contentDir] as $root) {
            $rootReal = self::realish((string) self::normalise($root));
            if ($rootReal !== '' && self::inside($real, $rootReal)) {
                $insideWebRoot = true;
            }
        }
        if ($insideWebRoot) {
            $ack = $this->env->acknowledgedPath !== null ? self::normalise($this->env->acknowledgedPath) : null;
            if ($ack !== $path) {
                throw new UnsafeDirectory(
                    'The export directory ' . $path . ' is inside the web root. Move it with FINETUNE_EXPORT_DIR,'
                    . ' or block it in the web server and tick the acknowledgement on the Settings tab.'
                );
            }
        }
        if ($source === 'default' && !is_dir($path) && !is_writable(dirname($path))) {
            throw new UnsafeDirectory(
                'No export directory is available: ' . dirname($path) . ' is not writable.'
                . ' Set FINETUNE_EXPORT_DIR in wp-config.php to a writable directory outside the web root.'
            );
        }
        $final = $this->env->siteId > 0 ? $path . '/site-' . $this->env->siteId : $path;
        return new ResolvedDirectory($final, $path, $source, $insideWebRoot);
    }

    /**
     * Resolves, creates (0700) and protects the directory.
     */
    public function ensure(): ResolvedDirectory
    {
        $dir = $this->resolve();
        $targets = $dir->path === $dir->base ? [$dir->path] : [$dir->base, $dir->path];
        foreach ($targets as $target) {
            if (!is_dir($target)) {
                $old = umask(0077);
                $made = mkdir($target, 0700, true);
                umask($old);
                if (!$made && !is_dir($target)) {
                    throw new UnsafeDirectory('Could not create the export directory ' . $target . '.');
                }
            }
            if (!is_writable($target)) {
                throw new UnsafeDirectory('The export directory ' . $target . ' is not writable.');
            }
            $this->checkMode($target, 0700);
            $this->writeDenyFiles($target);
        }
        return $dir;
    }

    public static function newRandom(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function fileName(int $jobId, string $random, string $kind, bool $gzip, bool $part): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $random) !== 1) {
            throw new \InvalidArgumentException('Bad random part.');
        }
        return 'export-' . $jobId . '-' . $random . ($kind === 'validation' ? '-validation' : '')
            . '.jsonl' . ($gzip ? '.gz' : '') . ($part ? '.part' : '');
    }

    /**
     * Full path of a plugin file. The name must match the plugin's own
     * pattern, so a caller can never build a path from other input.
     */
    public function path(string $name): string
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1 && preg_match(self::CANARY_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('Not a file name this plugin creates.');
        }
        return $this->resolve()->path . '/' . $name;
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    public function size(string $name): int
    {
        clearstatcache(true, $this->path($name));
        $size = filesize($this->path($name));
        if ($size === false) {
            throw new FinetuneExportException('Could not read the size of ' . $name . '.');
        }
        return $size;
    }

    /**
     * Opens a file for appending at a byte offset, creating it with mode
     * 0600 and cutting anything after the offset (resume, case 81).
     *
     * @return resource
     */
    public function openAt(string $name, int $offset)
    {
        $offset = max(0, $offset);
        $path = $this->path($name);
        $old = umask(0177);
        $handle = fopen($path, 'c+b');
        umask($old);
        if ($handle === false) {
            throw new FinetuneExportException('Could not open ' . $name . ' for writing.');
        }
        $this->checkMode($path, 0600);
        if (!ftruncate($handle, $offset) || fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new FinetuneExportException('Could not move to the last good offset in ' . $name . '.');
        }
        return $handle;
    }

    /**
     * Writes all bytes and flushes, or throws.
     *
     * @param resource $handle
     */
    public function append($handle, string $data): int
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $n = fwrite($handle, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new FinetuneExportException('Writing the export file failed.');
            }
            $written += $n;
        }
        if (!fflush($handle)) {
            throw new FinetuneExportException('Flushing the export file failed.');
        }
        $position = ftell($handle);
        if ($position === false) {
            throw new FinetuneExportException('Could not read the export file position.');
        }
        return $position;
    }

    /**
     * Renames a finished `.part` file to its final name.
     */
    public function finalise(string $partName, string $finalName): void
    {
        if (!rename($this->path($partName), $this->path($finalName))) {
            throw new FinetuneExportException('Could not rename ' . $partName . ' to ' . $finalName . '.');
        }
    }

    /**
     * Streams a file to a sink in chunks of at most 1 MB, never reading it
     * whole into memory.
     *
     * @param callable(string): void $sink
     */
    public function stream(string $name, callable $sink, int $chunk = self::CHUNK): void
    {
        $handle = fopen($this->path($name), 'rb');
        if ($handle === false) {
            throw new FinetuneExportException('Could not open ' . $name . ' for reading.');
        }
        try {
            while (!feof($handle)) {
                $data = fread($handle, max(1, min($chunk, self::CHUNK)));
                if ($data === false) {
                    throw new FinetuneExportException('Reading ' . $name . ' failed.');
                }
                if ($data !== '') {
                    $sink($data);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Removes one export file. Called only by retention and operator delete
     * (PRD case 94); both record the removal in the job history.
     */
    public function removeExportFile(string $name): void
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('Refusing to remove a file this plugin did not name.');
        }
        $path = $this->path($name);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_file($path) || is_link($path)) {
            throw new FinetuneExportException('Refusing to remove ' . $name . ': it is not a regular file.');
        }
        if (!unlink($path)) {
            throw new FinetuneExportException('Could not remove ' . $name . '.');
        }
    }

    /**
     * Writes a canary file with random content for the web root self-check.
     *
     * @return array{name: string, content: string}
     */
    public function writeCanary(): array
    {
        $dir = $this->ensure();
        $name = 'canary-' . self::newRandom() . '.txt';
        $content = 'finetune-export-canary-' . self::newRandom();
        $old = umask(0177);
        $ok = file_put_contents($dir->path . '/' . $name, $content);
        umask($old);
        if ($ok === false) {
            throw new FinetuneExportException('Could not write the canary file.');
        }
        return ['name' => $name, 'content' => $content];
    }

    /**
     * Removes the canary probe written by writeCanary(). It is not an export file.
     */
    public function removeCanary(string $name): void
    {
        if (preg_match(self::CANARY_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('Not a canary file name.');
        }
        $path = $this->resolve()->path . '/' . $name;
        if (is_file($path) && !unlink($path)) {
            throw new FinetuneExportException('Could not remove the canary file ' . $name . '.');
        }
    }

    /**
     * Files in the directory that no job records (case 97). Never removed.
     *
     * @param list<string> $known
     * @return list<string>
     */
    public function unknownFiles(array $known): array
    {
        $dir = $this->resolve()->path;
        if (!is_dir($dir)) {
            return [];
        }
        $entries = scandir($dir);
        if ($entries === false) {
            throw new FinetuneExportException('Could not list the export directory.');
        }
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::DENY_FILES, true)) {
                continue;
            }
            if (preg_match('/^site-\d+$/', $entry) === 1 && is_dir($dir . '/' . $entry)) {
                continue;
            }
            if (!in_array($entry, $known, true)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Free bytes, or null when the host does not say.
     */
    public function freeSpace(): ?float
    {
        $free = ($this->freeSpace)($this->resolve()->path);
        return $free === false ? null : (float) $free;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function candidate(): array
    {
        if ($this->env->constantDir !== null && $this->env->constantDir !== '') {
            return [$this->env->constantDir, 'constant'];
        }
        if ($this->env->filterDir !== null && $this->env->filterDir !== '') {
            return [$this->env->filterDir, 'filter'];
        }
        $parent = dirname(rtrim($this->env->abspath, '/\\'));
        return [$parent . '/' . self::DEFAULT_NAME, 'default'];
    }

    private function writeDenyFiles(string $dir): void
    {
        $files = [
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "# finetune-export: deny all\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
                . "    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n"
                . "    <security>\n      <requestFiltering>\n        <hiddenSegments>\n"
                . "          <add segment=\".\" />\n        </hiddenSegments>\n      </requestFiltering>\n"
                . "    </security>\n  </system.webServer>\n</configuration>\n",
        ];
        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                continue;
            }
            $old = umask(0133);
            $ok = file_put_contents($path, $content);
            umask($old);
            if ($ok === false) {
                throw new UnsafeDirectory('Could not write ' . $name . ' into the export directory.');
            }
        }
    }

    private function checkMode(string $path, int $expected): void
    {
        clearstatcache(true, $path);
        $perms = fileperms($path);
        if ($perms === false) {
            throw new FinetuneExportException('Could not read the permissions of ' . $path . '.');
        }
        if (($perms & 0777) !== $expected) {
            if (chmod($path, $expected)) {
                clearstatcache(true, $path);
                $perms = fileperms($path);
            }
            if ($perms === false || ($perms & 0777) !== $expected) {
                $this->logger->warning('permission mismatch', [
                    'path' => basename($path),
                    'expected' => sprintf('%o', $expected),
                    'actual' => $perms === false ? 'unknown' : sprintf('%o', $perms & 0777),
                ]);
            }
        }
    }

    /**
     * Absolute path with forward slashes and no trailing slash, or null if
     * it is relative or has a ".." segment.
     */
    public static function normalise(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || ($path[0] !== '/' && preg_match('/^[A-Za-z]:\//', $path) !== 1)) {
            return null;
        }
        $segments = explode('/', $path);
        if (in_array('..', $segments, true)) {
            return null;
        }
        $clean = preg_replace('#/+#', '/', $path);
        $clean = preg_replace('#/\./#', '/', (string) $clean);
        $clean = rtrim((string) $clean, '/');
        return $clean === '' ? '/' : $clean;
    }

    /**
     * Resolves symbolic links on the longest existing prefix of a path.
     */
    private static function realish(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $rest = '';
        $current = $path;
        while (!file_exists($current)) {
            $parent = dirname($current);
            $rest = '/' . basename($current) . $rest;
            if ($parent === $current) {
                return $path;
            }
            $current = $parent;
        }
        $real = realpath($current);
        if ($real === false) {
            return $path;
        }
        return rtrim(str_replace('\\', '/', $real), '/') . $rest;
    }

    private static function inside(string $path, string $base): bool
    {
        return $path === $base || str_starts_with($path, rtrim($base, '/') . '/');
    }
}

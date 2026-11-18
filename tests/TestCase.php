<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests;

use IndianOpinion\FinetuneExport\Cleaner;
use IndianOpinion\FinetuneExport\Content\WpContentSource;
use IndianOpinion\FinetuneExport\Exporter;
use IndianOpinion\FinetuneExport\Jobs\InMemoryJobRepository;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Jobs\OptionLock;
use IndianOpinion\FinetuneExport\Pipeline;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Storage\Storage;
use IndianOpinion\FinetuneExport\Storage\StorageEnvironment;
use IndianOpinion\FinetuneExport\Support\Logger;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected FakeClock $clock;
    protected InMemoryJobRepository $jobs;
    /** @var list<array{0: string, 1: string}> */
    protected array $logs = [];
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::reset();
        $GLOBALS['wp_stub_actions'] = [];
        \WP_CLI::$lines = [];
        \WP_CLI::$errors = [];
        $this->clock = new FakeClock();
        $this->jobs = new InMemoryJobRepository();
        $this->root = FINETUNE_EXPORT_TEST_ROOT . '/' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
        \WpStub::$uploadsBase = WP_CONTENT_DIR . '/uploads';
        $this->logs = [];
        add_filter('finetune_export_log', function (bool $handled, string $level, string $message) {
            $this->logs[] = [$level, $message];
            return true;
        }, 10, 3);
        Plugin::setInstance(null);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
        Plugin::setInstance(null);
        parent::tearDown();
    }

    /**
     * Deletes a temporary test tree. Only ever called on paths under the
     * test root.
     */
    public static function removeTree(string $path): void
    {
        if (!str_starts_with($path, sys_get_temp_dir() . '/finetune-export-tests-') || (!file_exists($path) && !is_link($path))) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }

    protected function exportDir(): string
    {
        return $this->root . '/private/exports';
    }

    protected function env(?string $constantDir = null, ?string $filterDir = null, ?string $ack = null, int $siteId = 0): StorageEnvironment
    {
        return new StorageEnvironment(
            ABSPATH,
            WP_CONTENT_DIR,
            WP_CONTENT_DIR . '/uploads',
            $constantDir,
            $filterDir,
            $ack,
            $siteId
        );
    }

    protected function storage(?StorageEnvironment $env = null, ?callable $freeSpace = null): Storage
    {
        return new Storage($env ?? $this->env($this->exportDir()), new Logger(), $freeSpace);
    }

    protected function exporter(?Storage $storage = null, ?callable $blocked = null, ?Cleaner $cleaner = null): Exporter
    {
        return new Exporter(
            new WpContentSource(),
            $this->jobs,
            $storage ?? $this->storage(),
            new OptionLock($this->clock),
            $cleaner ?? new Cleaner(),
            new Logger(),
            $this->clock,
            $blocked
        );
    }

    /**
     * Plugin wired to in-memory jobs and the test clock, with a safe directory.
     */
    protected function plugin(): Plugin
    {
        $dir = $this->exportDir();
        add_filter('finetune_export_directory', static fn (): string => $dir);
        $plugin = new Plugin($this->clock, $this->jobs);
        Plugin::setInstance($plugin);
        return $plugin;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function profile(array $overrides = []): Profile
    {
        return Profile::fromArray(array_replace_recursive(Profile::defaults(), $overrides));
    }

    protected function pipeline(Profile $profile, ?Cleaner $cleaner = null): Pipeline
    {
        return new Pipeline($profile, new WpContentSource(), $cleaner ?? new Cleaner(), new Logger());
    }

    /**
     * Runs a whole export in the foreground and returns the finished job.
     */
    protected function runExport(Profile $profile, ?Exporter $exporter = null, int $user = 1): Job
    {
        $exporter = $exporter ?? $this->exporter();
        $job = $exporter->start($profile, $user);
        return $exporter->runForeground($job->id);
    }

    /**
     * Decoded lines of a finished export file.
     *
     * @return list<array<string, mixed>>
     */
    protected function lines(Job $job, string $kind = Job::FILE_TRAIN, ?Storage $storage = null): array
    {
        $storage = $storage ?? $this->storage();
        $path = $storage->path((string) $job->fileName($kind));
        $raw = str_ends_with($path, '.gz') ? (string) implode('', gzfile($path) ?: []) : (string) file_get_contents($path);
        $out = [];
        foreach (explode("\n", rtrim($raw, "\n")) as $line) {
            if ($line !== '') {
                $out[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $record
     */
    protected static function assistant(array $record): string
    {
        return (string) Pipeline::target($record, 'chat');
    }

    /**
     * @return list<string>
     */
    protected function actions(string $hook): array
    {
        $out = [];
        foreach ($GLOBALS['wp_stub_actions'] as [$name, $args]) {
            if ($name === $hook) {
                $out[] = $args;
            }
        }
        return $out;
    }
}

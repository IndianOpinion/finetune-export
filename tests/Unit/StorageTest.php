<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Unit;

use IndianOpinion\FinetuneExport\Storage\Storage;
use IndianOpinion\FinetuneExport\Tests\TestCase;
use IndianOpinion\FinetuneExport\UnsafeDirectory;

final class StorageTest extends TestCase
{
    /** Test 56: constant, then filter, then default. */
    public function testResolutionOrder(): void
    {
        $constant = $this->root . '/from-constant';
        $filter = $this->root . '/from-filter';
        self::assertSame('constant', $this->storage($this->env($constant, $filter))->resolve()->source);
        self::assertSame($constant, $this->storage($this->env($constant, $filter))->resolve()->path);
        self::assertSame('filter', $this->storage($this->env(null, $filter))->resolve()->source);
        $default = $this->storage($this->env())->resolve();
        self::assertSame('default', $default->source);
        self::assertSame(dirname(rtrim(ABSPATH, '/')) . '/finetune-exports', $default->path);
        self::assertFalse($default->insideWebRoot);
    }

    /** A refused first candidate is not replaced by a later one (fail closed). */
    public function testRefusedCandidateDoesNotFallThrough(): void
    {
        $this->expectException(UnsafeDirectory::class);
        $this->storage($this->env(ABSPATH . 'private', $this->root . '/fine'))->resolve();
    }

    /** Test 57: inside ABSPATH refused without acknowledgement, accepted with it, voided by a path change. */
    public function testWebRootNeedsAcknowledgement(): void
    {
        $inside = ABSPATH . 'exports';
        try {
            $this->storage($this->env($inside))->resolve();
            self::fail('Expected UnsafeDirectory');
        } catch (UnsafeDirectory $e) {
            self::assertStringContainsString('inside the web root', $e->getMessage());
        }
        $dir = $this->storage($this->env($inside, null, $inside))->resolve();
        self::assertTrue($dir->insideWebRoot);
        $this->expectException(UnsafeDirectory::class);
        $this->storage($this->env(ABSPATH . 'exports-2', null, $inside))->resolve();
    }

    public function testContentDirAlsoCountsAsWebRoot(): void
    {
        $this->expectException(UnsafeDirectory::class);
        $this->storage($this->env(WP_CONTENT_DIR . '/private-exports'))->resolve();
    }

    /** Test 58: uploads refused even with acknowledgement. */
    public function testUploadsAlwaysRefused(): void
    {
        $uploads = WP_CONTENT_DIR . '/uploads/exports';
        $this->expectException(UnsafeDirectory::class);
        $this->expectExceptionMessage('uploads');
        $this->storage($this->env($uploads, null, $uploads))->resolve();
    }

    public function testRelativeAndDotDotPathsRefused(): void
    {
        foreach (['relative/dir', $this->root . '/a/../b'] as $bad) {
            try {
                $this->storage($this->env($bad))->resolve();
                self::fail('Expected UnsafeDirectory for ' . $bad);
            } catch (UnsafeDirectory $e) {
                self::assertStringContainsString('absolute', $e->getMessage());
            }
        }
    }

    public function testSymlinkIntoUploadsRefused(): void
    {
        $link = $this->root . '/sneaky';
        symlink(WP_CONTENT_DIR . '/uploads', $link);
        $this->expectException(UnsafeDirectory::class);
        $this->storage($this->env($link . '/exports'))->resolve();
    }

    public function testDefaultNeedsWritableParent(): void
    {
        $env = new \IndianOpinion\FinetuneExport\Storage\StorageEnvironment('/nonexistent-finetune/a/public/', '/nonexistent-finetune/a/public/wp-content', '/nonexistent-finetune/a/public/wp-content/uploads');
        $this->expectException(UnsafeDirectory::class);
        $this->expectExceptionMessage('FINETUNE_EXPORT_DIR');
        (new Storage($env, new \IndianOpinion\FinetuneExport\Support\Logger()))->resolve();
    }

    public function testMultisiteSubdirectory(): void
    {
        $dir = $this->storage($this->env($this->exportDir(), null, null, 3))->ensure();
        self::assertSame($this->exportDir() . '/site-3', $dir->path);
        self::assertDirectoryExists($dir->path);
    }

    /** Test 60: 0700 directory with deny files; 0600 files. */
    public function testModesAndDenyFiles(): void
    {
        $storage = $this->storage();
        $dir = $storage->ensure();
        self::assertSame(0700, fileperms($dir->path) & 0777);
        foreach (Storage::DENY_FILES as $file) {
            self::assertFileExists($dir->path . '/' . $file);
        }
        self::assertStringContainsString('Require all denied', (string) file_get_contents($dir->path . '/.htaccess'));
        $name = Storage::fileName(7, Storage::newRandom(), 'train', false, true);
        $handle = $storage->openAt($name, 0);
        $storage->append($handle, "{}\n");
        fclose($handle);
        self::assertSame(0600, fileperms($storage->path($name)) & 0777);
    }

    /** Test 61: file names carry 32 random characters and nothing else. */
    public function testFileNames(): void
    {
        $a = Storage::newRandom();
        $b = Storage::newRandom();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $a);
        self::assertNotSame($a, $b);
        $name = Storage::fileName(12, $a, 'train', false, false);
        self::assertSame('export-12-' . $a . '.jsonl', $name);
        self::assertMatchesRegularExpression(Storage::FILE_PATTERN, $name);
        self::assertSame('export-12-' . $a . '-validation.jsonl.gz.part', Storage::fileName(12, $a, 'validation', true, true));
    }

    public function testPathRefusesForeignNames(): void
    {
        $storage = $this->storage();
        foreach (['../../wp-config.php', 'index.php', 'export-1-abc.jsonl', 'export-1-' . str_repeat('a', 32) . '.jsonl/../x'] as $bad) {
            try {
                $storage->path($bad);
                self::fail('Expected refusal for ' . $bad);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('plugin', $e->getMessage());
            }
        }
    }

    public function testOpenAtTruncatesToOffset(): void
    {
        $storage = $this->storage();
        $storage->ensure();
        $name = Storage::fileName(1, Storage::newRandom(), 'train', false, true);
        $h = $storage->openAt($name, 0);
        $offset = $storage->append($h, "one\n");
        $storage->append($h, "two\n");
        fclose($h);
        $h = $storage->openAt($name, $offset);
        $storage->append($h, "three\n");
        fclose($h);
        self::assertSame("one\nthree\n", file_get_contents($storage->path($name)));
    }

    /** Test 91: unknown files are listed and never removed. */
    public function testUnknownFiles(): void
    {
        $storage = $this->storage();
        $dir = $storage->ensure();
        $known = Storage::fileName(1, Storage::newRandom(), 'train', false, false);
        file_put_contents($dir->path . '/' . $known, '');
        file_put_contents($dir->path . '/notes.txt', 'mine');
        self::assertSame(['notes.txt'], $storage->unknownFiles([$known]));
        $this->expectException(\InvalidArgumentException::class);
        $storage->removeExportFile('notes.txt');
    }

    public function testStreamUsesBoundedChunks(): void
    {
        $storage = $this->storage();
        $storage->ensure();
        $name = Storage::fileName(1, Storage::newRandom(), 'train', false, false);
        file_put_contents($storage->path($name), str_repeat('x', 10000));
        $chunks = [];
        $storage->stream($name, static function (string $c) use (&$chunks): void {
            $chunks[] = strlen($c);
        }, 4096);
        self::assertSame([4096, 4096, 1808], $chunks);
    }

    /**
     * Test 88 (grep part): file removal calls exist only in Storage, in the
     * export-file and canary methods.
     */
    public function testOnlyStorageRemovesFiles(): void
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/\b(unlink|rmdir|wp_delete_file|wp_delete_file_from_directory|array_map\(\s*[\'"]unlink)\s*\(/', $code, $m) > 0) {
                $hits[basename($file->getPathname())] = count($m[0]);
            }
        }
        self::assertSame(['Storage.php' => 2], $hits);
        $storage = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Storage/Storage.php');
        preg_match_all('/function (\w+)\([^)]*\)[^{]*\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\}/s', $storage, $methods, PREG_SET_ORDER);
        $removers = [];
        foreach ($methods as $method) {
            if (str_contains($method[0], 'unlink(')) {
                $removers[] = $method[1];
            }
        }
        self::assertSame(['removeExportFile', 'removeCanary'], $removers);
    }
}

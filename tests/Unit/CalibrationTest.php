<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Unit;

use IndianOpinion\FinetuneExport\Estimate\Calibration;
use PHPUnit\Framework\TestCase;

final class CalibrationTest extends TestCase
{
    public function testReportPerScript(): void
    {
        $c = new Calibration();
        $c->add('Hello, world!', 4);
        $c->add('नमस्ते दुनिया', 12);
        $c->add('こんにちは世界', 7, 'Japanese');
        $report = $c->report();
        self::assertSame(['Devanagari', 'Japanese', 'Latin'], array_keys($report));
        self::assertSame(['samples' => 1, 'estimated' => 6, 'reference' => 4, 'error_percent' => 50.0, 'mean_abs_error_percent' => 50.0, 'factor' => 0.667], $report['Latin']);
        self::assertSame(100.0, $report['Devanagari']['error_percent']);
        self::assertSame(0.0, $report['Japanese']['error_percent']);
        self::assertSame('Other', Calibration::dominantScript('123 !!'));
    }

    /**
     * Runs the tool without a shell.
     *
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private static function tool(array $args): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/tools/calibrate.php'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    public function testCliRefusesUrlsAndRuns(): void
    {
        $examples = dirname(__DIR__, 2) . '/examples/calibration';
        [$code, $out] = self::tool(['--corpus=' . $examples . '/texts.jsonl', '--tokeniser=' . $examples . '/word-tokeniser.php', '--json']);
        self::assertSame(0, $code);
        self::assertSame(['Devanagari', 'Han/Kana', 'Latin'], array_keys((array) json_decode($out, true)));
        [$code, $out] = self::tool(['--reference=https://example.org/x.jsonl']);
        self::assertSame(1, $code);
        self::assertStringContainsString('never uses the network', $out);
    }
}

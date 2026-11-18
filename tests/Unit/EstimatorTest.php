<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Unit;

use IndianOpinion\FinetuneExport\Estimate\HeuristicEstimator;
use IndianOpinion\FinetuneExport\Estimate\Stats;
use IndianOpinion\FinetuneExport\Tests\TestCase;

final class EstimatorTest extends TestCase
{
    /** Test 50: exact documented counts per script. */
    public function testKnownStrings(): void
    {
        $e = new HeuristicEstimator();
        self::assertSame(6, $e->count('Hello, world!'));
        self::assertSame(5, $e->count('internationalisation'));
        self::assertSame(24, $e->count('नमस्ते दुनिया'));
        self::assertSame(7, $e->count('こんにちは世界'));
        self::assertSame(12, $e->count('AI ने 2026 में'));
        self::assertSame(6, $e->count('Привет'));
        self::assertSame(0, $e->count(" \n\t "));
    }

    public function testEnvelopeOverhead(): void
    {
        $e = new HeuristicEstimator();
        $chat = ['messages' => [
            ['role' => 'user', 'content' => 'Hello, world!'],
            ['role' => 'assistant', 'content' => 'こんにちは世界'],
        ]];
        self::assertSame(6 + 7 + 2 * 4, $e->estimate($chat, 'chat'));
        self::assertSame(6 + 7 + 3, $e->estimate(['prompt' => 'Hello, world!', 'completion' => 'こんにちは世界'], 'prompt_completion'));
        self::assertSame(6 + 3, $e->estimate(['text' => 'Hello, world!'], 'text'));
    }

    /** Test 49: the estimator makes no HTTP request (the stub fails the test on any call). */
    public function testNoNetwork(): void
    {
        (new HeuristicEstimator())->estimate(['text' => str_repeat('Some words here. ', 500)], 'text');
        self::assertSame([], \WpStub::$http);
    }

    /** Test 53: mean, median, p95, max and histogram for a known set. */
    public function testStats(): void
    {
        $stats = new Stats();
        foreach ([1, 2, 3, 4, 100] as $value) {
            $stats->add($value);
        }
        $summary = $stats->summary();
        self::assertSame(5, $summary['records']);
        self::assertSame(110, $summary['total']);
        self::assertSame(22.0, $summary['mean']);
        self::assertSame(3.0, $summary['median']);
        self::assertSame(100, $summary['p95']);
        self::assertSame(100, $summary['max']);
        self::assertCount(10, $summary['histogram']);
        self::assertSame(['from' => 0, 'to' => 10, 'count' => 4], $summary['histogram'][0]);
        self::assertSame(1, $summary['histogram'][9]['count']);
        self::assertSame(5, array_sum(array_column($summary['histogram'], 'count')));

        $even = Stats::fromArray([10 => 1, 20 => 1, 30 => 1, 40 => 1]);
        self::assertSame(25.0, $even->median());
        self::assertSame(40, $even->percentile(95));
        self::assertSame(['records' => 0, 'total' => 0, 'mean' => 0.0, 'median' => 0.0, 'p95' => 0, 'max' => 0], array_diff_key((new Stats())->summary(), ['histogram' => 1]));
    }
}

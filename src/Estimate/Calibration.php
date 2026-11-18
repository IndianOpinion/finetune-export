<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Estimate;

/**
 * Compares the default estimator with reference token counts per script
 * (ruling 4). Offline: the reference counts come from a tokeniser the
 * operator runs or supplies; nothing is downloaded.
 */
final class Calibration
{
    public const SCRIPTS = [
        'Han/Kana' => '/[\p{Han}\p{Hiragana}\p{Katakana}]/u',
        'Hangul' => '/\p{Hangul}/u',
        'Devanagari' => '/\p{Devanagari}/u',
        'Bengali' => '/\p{Bengali}/u',
        'Tamil' => '/\p{Tamil}/u',
        'Arabic' => '/\p{Arabic}/u',
        'Cyrillic' => '/\p{Cyrillic}/u',
        'Latin' => '/\p{Latin}/u',
    ];

    /** @var array<string, list<array{estimate: int, reference: int}>> */
    private array $samples = [];

    public function __construct(private readonly HeuristicEstimator $estimator = new HeuristicEstimator())
    {
    }

    /**
     * The script with the most letters in a text, or "Other".
     */
    public static function dominantScript(string $text): string
    {
        $best = 'Other';
        $bestCount = 0;
        foreach (self::SCRIPTS as $name => $pattern) {
            $count = (int) preg_match_all($pattern, $text);
            if ($count > $bestCount) {
                $best = $name;
                $bestCount = $count;
            }
        }
        return $best;
    }

    public function add(string $text, int $reference, ?string $label = null): void
    {
        if ($reference < 0) {
            throw new \InvalidArgumentException('A reference count cannot be negative.');
        }
        $label = $label ?? self::dominantScript($text);
        $this->samples[$label][] = ['estimate' => $this->estimator->count($text), 'reference' => $reference];
    }

    /**
     * Per label: samples, estimated and reference totals, signed error of
     * the totals in percent (positive means the estimate is high), mean
     * absolute error per sample in percent, and the factor that would make
     * the totals agree.
     *
     * @return array<string, array{samples: int, estimated: int, reference: int, error_percent: float, mean_abs_error_percent: float, factor: float}>
     */
    public function report(): array
    {
        $out = [];
        ksort($this->samples);
        foreach ($this->samples as $label => $rows) {
            $estimated = array_sum(array_column($rows, 'estimate'));
            $reference = array_sum(array_column($rows, 'reference'));
            $abs = [];
            foreach ($rows as $row) {
                if ($row['reference'] > 0) {
                    $abs[] = abs($row['estimate'] - $row['reference']) / $row['reference'] * 100;
                }
            }
            $out[$label] = [
                'samples' => count($rows),
                'estimated' => $estimated,
                'reference' => $reference,
                'error_percent' => $reference > 0 ? round(($estimated - $reference) / $reference * 100, 1) : 0.0,
                'mean_abs_error_percent' => $abs === [] ? 0.0 : round(array_sum($abs) / count($abs), 1),
                'factor' => $estimated > 0 ? round($reference / $estimated, 3) : 0.0,
            ];
        }
        return $out;
    }
}

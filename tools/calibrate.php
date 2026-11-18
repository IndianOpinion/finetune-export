<?php

/**
 * Offline calibration of the default token estimator (ruling 4).
 *
 * Compares the estimator with a tokeniser you supply, per script, and
 * prints the error. It never touches the network and needs no WordPress.
 *
 * Two ways to give reference counts:
 *
 *   php tools/calibrate.php --reference=counts.jsonl
 *       Each line: {"text": "...", "tokens": 123, "label": "optional"}.
 *       Make the file with any tokeniser, on any machine.
 *
 *   php tools/calibrate.php --corpus=texts.jsonl --tokeniser=my-tokeniser.php
 *       texts.jsonl lines: {"text": "...", "label": "optional"}, or a
 *       directory of .txt files. my-tokeniser.php must return a callable
 *       that takes a string and returns an int. It is your own code and runs
 *       in this process.
 *
 * Add --json for machine-readable output.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use IndianOpinion\FinetuneExport\Estimate\Calibration;

/**
 * @param list<string> $argv
 * @return array<string, string|bool>
 */
function finetune_export_calibrate_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m) === 1) {
            $out[$m[1]] = $m[2] ?? true;
        }
    }
    return $out;
}

/**
 * @return list<array{text: string, tokens: ?int, label: ?string}>
 */
function finetune_export_calibrate_read(string $path): array
{
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
        throw new InvalidArgumentException('Give a local file, not a URL. This tool never uses the network.');
    }
    $rows = [];
    if (is_dir($path)) {
        foreach (glob(rtrim($path, '/') . '/*.txt') ?: [] as $file) {
            $rows[] = ['text' => (string) file_get_contents($file), 'tokens' => null, 'label' => null];
        }
        return $rows;
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new InvalidArgumentException('Cannot read ' . $path);
    }
    $n = 0;
    while (($line = fgets($handle)) !== false) {
        $n++;
        if (trim($line) === '') {
            continue;
        }
        $data = json_decode($line, true);
        if (!is_array($data) || !isset($data['text']) || !is_string($data['text'])) {
            throw new InvalidArgumentException('Line ' . $n . ' of ' . $path . ' is not {"text": ...}.');
        }
        $rows[] = [
            'text' => $data['text'],
            'tokens' => isset($data['tokens']) ? (int) $data['tokens'] : null,
            'label' => isset($data['label']) && is_string($data['label']) ? $data['label'] : null,
        ];
    }
    fclose($handle);
    return $rows;
}

$args = finetune_export_calibrate_args($argv);
try {
    $calibration = new Calibration();
    if (isset($args['reference']) && is_string($args['reference'])) {
        foreach (finetune_export_calibrate_read($args['reference']) as $row) {
            if ($row['tokens'] === null) {
                throw new InvalidArgumentException('Every reference line needs "tokens".');
            }
            $calibration->add($row['text'], $row['tokens'], $row['label']);
        }
    } elseif (isset($args['corpus'], $args['tokeniser']) && is_string($args['corpus']) && is_string($args['tokeniser'])) {
        $tokeniser = require $args['tokeniser'];
        if (!is_callable($tokeniser)) {
            throw new InvalidArgumentException('The tokeniser file must return a callable.');
        }
        foreach (finetune_export_calibrate_read($args['corpus']) as $row) {
            $calibration->add($row['text'], (int) $tokeniser($row['text']), $row['label']);
        }
    } else {
        fwrite(STDERR, "Usage: php tools/calibrate.php --reference=counts.jsonl [--json]\n"
            . "   or: php tools/calibrate.php --corpus=texts.jsonl|dir --tokeniser=file.php [--json]\n");
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$report = $calibration->report();
if (!empty($args['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
printf("%-12s %8s %10s %10s %8s %10s %8s\n", 'script', 'samples', 'estimated', 'reference', 'error%', 'mean|err|%', 'factor');
foreach ($report as $label => $row) {
    printf(
        "%-12s %8d %10d %10d %8.1f %10.1f %8.3f\n",
        $label,
        $row['samples'],
        $row['estimated'],
        $row['reference'],
        $row['error_percent'],
        $row['mean_abs_error_percent'],
        $row['factor']
    );
}
echo "Positive error means the estimate is high. The estimate is advisory; see docs/ARCHITECTURE.md.\n";

<?php

/**
 * Plug in your own local token estimator, for example a tokeniser library
 * you installed yourself. Drop this into a must-use plugin. The plugin never
 * downloads a tokeniser.
 */

declare(strict_types=1);

use IndianOpinion\FinetuneExport\Estimate\HeuristicEstimator;
use IndianOpinion\FinetuneExport\Estimate\TokenEstimator;

add_filter('finetune_export_token_estimator', static function (TokenEstimator $default): TokenEstimator {
    return new class implements TokenEstimator {
        public function estimate(array $record, string $shape): int
        {
            // Example: the default estimate scaled by a factor measured with tools/calibrate.php.
            return (int) ceil((new HeuristicEstimator())->estimate($record, $shape) * 0.9);
        }
    };
});

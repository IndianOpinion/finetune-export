<?php

/**
 * A stand-in "tokeniser" that counts words and punctuation. It shows the
 * file format tools/calibrate.php expects. For a real calibration, return a
 * callable that runs the tokeniser of the model you will fine-tune, locally.
 */

declare(strict_types=1);

return static function (string $text): int {
    return (int) preg_match_all('/[\p{L}\p{M}\p{N}]+|[^\s\p{L}\p{M}\p{N}]/u', $text);
};

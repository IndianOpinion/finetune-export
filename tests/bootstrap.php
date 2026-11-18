<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/finetune-export-tests-' . getmypid();
define('FINETUNE_EXPORT_TEST_ROOT', $root);
define('ABSPATH', $root . '/site/public/');
define('WP_CONTENT_DIR', $root . '/site/public/wp-content');
define('ARRAY_A', 'ARRAY_A');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs/classes.php';
require __DIR__ . '/stubs/state.php';
require __DIR__ . '/stubs/functions.php';

if (!is_dir(WP_CONTENT_DIR . '/uploads')) {
    mkdir(WP_CONTENT_DIR . '/uploads', 0755, true);
}

register_shutdown_function(static function () use ($root): void {
    \IndianOpinion\FinetuneExport\Tests\TestCase::removeTree($root);
});

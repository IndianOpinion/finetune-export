<?php

/**
 * Plugin Name: Fine-tune Export (must-use loader)
 * Description: Loads finetune-export from wp-content/mu-plugins/finetune-export/.
 *
 * Copy this file to wp-content/mu-plugins/finetune-export-loader.php and the
 * plugin folder to wp-content/mu-plugins/finetune-export/. Must-use plugins
 * have no activation hook, so the loader runs the activation once.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/finetune-export/autoload.php';

\IndianOpinion\FinetuneExport\Plugin::instance()->register();

add_action('init', static function (): void {
    if (get_option('finetune_export_db_version') === false) {
        \IndianOpinion\FinetuneExport\Plugin::activate();
    }
}, 1);

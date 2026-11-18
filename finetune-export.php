<?php

/**
 * Plugin Name:       Fine-tune Export
 * Plugin URI:        https://github.com/IndianOpinion/finetune-export
 * Description:       Exports published posts as fine-tuning JSONL with best-effort redaction, a local token estimate and protected, expiring downloads. Calls no model.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       finetune-export
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

\IndianOpinion\FinetuneExport\Plugin::boot(__FILE__);

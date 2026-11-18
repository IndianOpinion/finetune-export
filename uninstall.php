<?php

/**
 * Uninstall (PRD case 102): removes options, the job tables, the per-post
 * and per-category opt-out meta and the capabilities. It never removes
 * export files: delete them first with the Delete button or
 * `wp finetune-export delete <job>`. Remaining files stay in the export
 * directory (FINETUNE_EXPORT_DIR, or finetune-exports one level above the
 * WordPress root).
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

\IndianOpinion\FinetuneExport\Uninstaller::run();

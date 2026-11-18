<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * Removes what the plugin created in the database (case 102). Export files
 * are never touched here, because the job history that records removals
 * goes with the table.
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site();
                restore_current_blog();
            }
            return;
        }
        self::site();
    }

    public static function site(): void
    {
        global $wpdb;
        foreach (Settings::ALL_OPTIONS as $option) {
            delete_option($option);
        }
        foreach (['finetune_export_jobs', 'finetune_export_hashes'] as $table) {
            $wpdb->query((string) $wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
        }
        delete_post_meta_by_key(Plugin::POST_OPTOUT_META);
        delete_metadata('term', 0, Plugin::TERM_OPTOUT_META, '', true);
        foreach (wp_roles()->role_objects as $role) {
            $role->remove_cap(Plugin::CAP_RUN);
            $role->remove_cap(Plugin::CAP_SENSITIVE);
        }
        wp_unschedule_hook(Plugin::CRON_STEP);
        wp_unschedule_hook(Plugin::CRON_DAILY);
    }
}

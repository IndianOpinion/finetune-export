<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Admin;

use IndianOpinion\FinetuneExport\Plugin;

/**
 * The per-post switch (case 10) in the block editor document panel and the
 * classic editor publish box, and the per-category switch (ruling 6). Shown
 * only to holders of the run capability.
 */
final class OptOut
{
    public function register(): void
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueueEditor']);
        add_action('post_submitbox_misc_actions', [$this, 'classicBox']);
        add_action('save_post', [$this, 'savePost'], 10, 2);
        add_action('category_add_form_fields', [$this, 'categoryAddField']);
        add_action('category_edit_form_fields', [$this, 'categoryEditField']);
        add_action('created_category', [$this, 'saveCategory']);
        add_action('edited_category', [$this, 'saveCategory']);
    }

    public function enqueueEditor(): void
    {
        if (!current_user_can(Plugin::CAP_RUN)) {
            return;
        }
        wp_enqueue_script(
            'finetune-export-editor',
            plugins_url('assets/editor.js', dirname(__DIR__, 2) . '/finetune-export.php'),
            ['wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-i18n'],
            Plugin::VERSION,
            true
        );
        wp_add_inline_script(
            'finetune-export-editor',
            'window.finetuneExportOptOut = ' . wp_json_encode(['metaKey' => Plugin::POST_OPTOUT_META]) . ';',
            'before'
        );
    }

    public function classicBox(\WP_Post $post): void
    {
        if (!current_user_can(Plugin::CAP_RUN) || use_block_editor_for_post($post)) {
            return;
        }
        wp_nonce_field('finetune_export_optout_' . $post->ID, 'finetune_export_optout_nonce');
        printf(
            '<div class="misc-pub-section"><input type="hidden" name="finetune_export_optout_present" value="1"><label><input type="checkbox" name="finetune_export_optout" value="1" %s> %s</label></div>',
            checked((bool) get_post_meta($post->ID, Plugin::POST_OPTOUT_META, true), true, false),
            esc_html__('Exclude from training exports', 'finetune-export')
        );
    }

    public function savePost(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['finetune_export_optout_nonce'], $_POST['finetune_export_optout_present'])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash((string) $_POST['finetune_export_optout_nonce']));
        if (!wp_verify_nonce($nonce, 'finetune_export_optout_' . $postId)) {
            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if (!current_user_can(Plugin::CAP_RUN) || !current_user_can('edit_post', $postId)) {
            return;
        }
        if (!empty($_POST['finetune_export_optout'])) {
            update_post_meta($postId, Plugin::POST_OPTOUT_META, true);
        } else {
            delete_post_meta($postId, Plugin::POST_OPTOUT_META);
        }
    }

    public function categoryAddField(): void
    {
        if (!current_user_can(Plugin::CAP_RUN)) {
            return;
        }
        wp_nonce_field('finetune_export_term_optout', 'finetune_export_term_nonce');
        printf(
            '<div class="form-field"><label><input type="checkbox" name="finetune_export_term_optout" value="1"> %s</label><p>%s</p></div>',
            esc_html__('Exclude from training exports', 'finetune-export'),
            esc_html__('Posts in this category are skipped by fine-tune exports.', 'finetune-export')
        );
    }

    public function categoryEditField(\WP_Term $term): void
    {
        if (!current_user_can(Plugin::CAP_RUN)) {
            return;
        }
        wp_nonce_field('finetune_export_term_optout', 'finetune_export_term_nonce');
        printf(
            '<tr class="form-field"><th scope="row">%s</th><td><label><input type="checkbox" name="finetune_export_term_optout" value="1" %s> %s</label></td></tr>',
            esc_html__('Training exports', 'finetune-export'),
            checked((bool) get_term_meta($term->term_id, Plugin::TERM_OPTOUT_META, true), true, false),
            esc_html__('Exclude posts in this category from training exports', 'finetune-export')
        );
    }

    public function saveCategory(int $termId): void
    {
        if (!isset($_POST['finetune_export_term_nonce'])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash((string) $_POST['finetune_export_term_nonce']));
        if (!wp_verify_nonce($nonce, 'finetune_export_term_optout') || !current_user_can(Plugin::CAP_RUN)) {
            return;
        }
        if (!empty($_POST['finetune_export_term_optout'])) {
            update_term_meta($termId, Plugin::TERM_OPTOUT_META, true);
        } else {
            delete_term_meta($termId, Plugin::TERM_OPTOUT_META);
        }
    }
}

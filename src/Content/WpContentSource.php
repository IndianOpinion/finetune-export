<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Content;

use IndianOpinion\FinetuneExport\CleanOptions;
use IndianOpinion\FinetuneExport\InvalidProfile;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Profile;

/**
 * Reads posts through WordPress core functions.
 */
final class WpContentSource implements ContentSource
{
    /** Post types never offered or selected, whatever the settings (case 5). */
    public const NEVER_TYPES = [
        'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
    ];
    /** Statuses never selected (case 5). */
    public const NEVER_STATUSES = ['trash', 'inherit', 'auto-draft'];

    /**
     * Public post types that may be offered (case 2).
     *
     * @return array<string, string> name => label
     */
    public static function offeredPostTypes(): array
    {
        $out = [];
        foreach (get_post_types(['public' => true], 'objects') as $name => $type) {
            if (in_array($name, self::NEVER_TYPES, true)) {
                continue;
            }
            $label = $type instanceof \WP_Post_Type ? $type->label : (string) $name;
            $out[(string) $name] = $label;
        }
        return $out;
    }

    public function validate(Profile $profile): void
    {
        $offered = self::offeredPostTypes();
        foreach ($profile->list('post_types') as $type) {
            if (!isset($offered[$type])) {
                throw new InvalidProfile('post_types', '"' . $type . '" is not a public post type on this site');
            }
        }
    }

    public function nextIds(Profile $profile, int $afterId, int $limit): array
    {
        $ids = get_posts($this->queryArgs($profile, $afterId, $limit));
        $out = [];
        foreach ($ids as $id) {
            $id = is_object($id) ? (int) $id->ID : (int) $id;
            if ($id > $afterId) {
                $out[] = $id;
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    public function total(Profile $profile): int
    {
        $args = $this->queryArgs($profile, 0, -1);
        $args['posts_per_page'] = -1;
        return count(get_posts($args));
    }

    public function post(int $id, Profile $profile): ?PostData
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        if (
            in_array($post->post_type, self::NEVER_TYPES, true)
            || in_array($post->post_status, self::NEVER_STATUSES, true)
            || !in_array($post->post_type, $profile->list('post_types'), true)
            || !in_array($post->post_status, $profile->list('statuses'), true)
            || ($post->post_password !== '' && !$profile->bool('include_password_protected'))
        ) {
            return null;
        }

        $categories = [];
        $categoryIds = [];
        $categoryOptOut = false;
        foreach (self::terms($id, 'category') as $term) {
            $categories[] = $term->name;
            $categoryIds[] = (int) $term->term_id;
            if (get_term_meta((int) $term->term_id, Plugin::TERM_OPTOUT_META, true)) {
                $categoryOptOut = true;
            }
        }
        $tags = [];
        $tagIds = [];
        foreach (self::terms($id, 'post_tag') as $term) {
            $tags[] = $term->name;
            $tagIds[] = (int) $term->term_id;
        }
        $author = get_userdata((int) $post->post_author);
        $imageAlt = '';
        $thumbnail = (int) get_post_meta($id, '_thumbnail_id', true);
        if ($thumbnail > 0) {
            $imageAlt = (string) get_post_meta($thumbnail, '_wp_attachment_image_alt', true);
        }
        $meta = [];
        foreach ($profile->metaKeys() as $key) {
            $values = get_post_meta($id, $key, false);
            if (is_array($values) && $values !== []) {
                $meta[$key] = count($values) === 1 ? $values[0] : $values;
            }
        }
        $date = get_post_datetime($post, 'date');
        $modified = get_post_datetime($post, 'modified');
        $permalink = get_permalink($post);

        return new PostData(
            $id,
            $post->post_type,
            $post->post_status,
            $post->post_password !== '',
            (string) $post->post_date,
            $date instanceof \DateTimeInterface ? $date->format('c') : '',
            $modified instanceof \DateTimeInterface ? $modified->format('c') : '',
            (string) $post->post_title,
            (string) $post->post_excerpt,
            (string) $post->post_content,
            is_string($permalink) ? $permalink : '',
            $categories,
            $categoryIds,
            $tags,
            $tagIds,
            (int) $post->post_author,
            $author instanceof \WP_User ? (string) $author->display_name : '',
            $imageAlt,
            $meta,
            (bool) get_post_meta($id, Plugin::POST_OPTOUT_META, true),
            $categoryOptOut,
        );
    }

    public function renderBody(PostData $post, Profile $profile): string
    {
        $content = $post->content;
        if ($profile->bool('apply_content_filters')) {
            $wpPost = get_post($post->id);
            if ($wpPost instanceof \WP_Post) {
                $previous = $GLOBALS['post'] ?? null;
                $GLOBALS['post'] = $wpPost;
                setup_postdata($wpPost);
                $content = (string) apply_filters('the_content', $content);
                $GLOBALS['post'] = $previous;
                if ($previous instanceof \WP_Post) {
                    setup_postdata($previous);
                }
            }
        } elseif ($profile->bool('render_dynamic_blocks') && function_exists('do_blocks')) {
            $content = do_blocks($content);
        }
        return $content;
    }

    public function cleanOptions(Profile $profile): CleanOptions
    {
        $dynamic = function_exists('get_dynamic_block_names') ? get_dynamic_block_names() : [];
        global $shortcode_tags;
        $registered = is_array($shortcode_tags) ? array_map('strval', array_keys($shortcode_tags)) : [];
        // Strip any shortcode-looking tag by default, which also catches tags left
        // by plugins that are no longer active; false strips registered tags only.
        $stripUnknown = (bool) apply_filters('finetune_export_strip_unknown_shortcodes', true);
        return new CleanOptions(
            $profile->bool('keep_image_alt'),
            array_values(array_map('strval', $dynamic)),
            $stripUnknown ? null : $registered,
            $profile->bool('render_dynamic_blocks') || $profile->bool('apply_content_filters'),
        );
    }

    public function userNames(): array
    {
        $names = [];
        $users = get_users(['fields' => 'all', 'number' => 50000, 'orderby' => 'ID']);
        foreach ($users as $user) {
            if (!$user instanceof \WP_User) {
                continue;
            }
            $names[] = (string) $user->display_name;
            $names[] = (string) $user->user_login;
            foreach (['first_name', 'last_name', 'nickname'] as $key) {
                $names[] = (string) get_user_meta($user->ID, $key, true);
            }
            $first = (string) get_user_meta($user->ID, 'first_name', true);
            $last = (string) get_user_meta($user->ID, 'last_name', true);
            if ($first !== '' && $last !== '') {
                $names[] = $first . ' ' . $last;
            }
        }
        return array_values(array_unique(array_filter($names, static fn (string $n): bool => trim($n) !== '')));
    }

    /**
     * @return array<string, mixed>
     */
    private function queryArgs(Profile $profile, int $afterId, int $limit): array
    {
        $args = [
            'post_type' => array_values(array_diff($profile->list('post_types'), self::NEVER_TYPES)),
            'post_status' => array_values(array_diff($profile->list('statuses'), self::NEVER_STATUSES)),
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'suppress_filters' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'finetune_export_after_id' => $afterId,
        ];
        if (!$profile->bool('include_password_protected')) {
            $args['has_password'] = false;
        }
        $args = apply_filters('finetune_export_query_args', $args, $profile);
        if (!is_array($args)) {
            throw new InvalidProfile('query_args', 'the query-args filter must return an array');
        }
        // A filter may widen the query; post() still enforces types, statuses and passwords.
        return $args;
    }

    /**
     * @return list<\WP_Term>
     */
    private static function terms(int $postId, string $taxonomy): array
    {
        $terms = get_the_terms($postId, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }
        return array_values(array_filter($terms, static fn ($t): bool => $t instanceof \WP_Term));
    }
}

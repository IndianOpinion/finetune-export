<?php

declare(strict_types=1);

/**
 * In-memory state behind the WordPress stubs. Tests call WpStub::reset() in
 * setUp and seed posts, users, options and filters through it.
 */
final class WpStub
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $filters = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, list<mixed>>> */
    public static array $postMeta = [];
    /** @var array<int, array<string, list<WP_Term>>> */
    public static array $postTerms = [];
    /** @var array<int, array<string, mixed>> */
    public static array $termMeta = [];
    /** @var array<int, WP_User> */
    public static array $users = [];
    /** @var array<int, array<string, mixed>> */
    public static array $userMeta = [];
    /** @var array<int, list<string>> */
    public static array $caps = [];
    public static int $currentUser = 0;
    public static int $cookieUser = 0;
    /** @var list<array{time: int, hook: string, args: array<mixed>, recurrence: string|false}> */
    public static array $cron = [];
    /** @var list<array{to: string, subject: string, message: string}> */
    public static array $mails = [];
    /** @var list<array{url: string, args: array<mixed>}> */
    public static array $http = [];
    /** @var callable|null */
    public static $httpResponder = null;
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var list<array{0: string, 1: string, 2: array<mixed>}> */
    public static array $routes = [];
    public static bool $multisite = false;
    public static int $blogId = 1;
    /** @var list<int> */
    public static array $superAdmins = [];
    /** @var list<string> */
    public static array $dynamicBlocks = [];
    public static string $uploadsBase = '';
    /** @var array<string, WP_Role> */
    public static array $roles = [];
    public static ?string $timezone = 'UTC';

    public static function reset(): void
    {
        self::$options = [];
        self::$filters = [];
        self::$posts = [];
        self::$postMeta = [];
        self::$postTerms = [];
        self::$termMeta = [];
        self::$users = [];
        self::$userMeta = [];
        self::$caps = [];
        self::$currentUser = 0;
        self::$cookieUser = 0;
        self::$cron = [];
        self::$mails = [];
        self::$http = [];
        self::$httpResponder = static function (string $url): never {
            throw new RuntimeException('Unexpected HTTP request to ' . $url);
        };
        self::$transients = [];
        self::$routes = [];
        self::$multisite = false;
        self::$blogId = 1;
        self::$superAdmins = [];
        self::$dynamicBlocks = [];
        self::$uploadsBase = rtrim(WP_CONTENT_DIR, '/') . '/uploads';
        self::$roles = ['administrator' => new WP_Role('administrator', []), 'editor' => new WP_Role('editor', [])];
        $GLOBALS['wpdb'] = new wpdb();
        $GLOBALS['shortcode_tags'] = [];
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function addPost(array $fields): WP_Post
    {
        $id = (int) ($fields['ID'] ?? (self::$posts === [] ? 1 : max(array_keys(self::$posts)) + 1));
        $post = new WP_Post();
        $post->ID = $id;
        $post->post_type = 'post';
        $post->post_status = 'publish';
        $post->post_title = 'Title ' . $id;
        $post->post_content = '<p>Body of post ' . $id . '.</p>';
        $post->post_date = '2026-01-15 10:00:00';
        $post->post_date_gmt = '2026-01-15 10:00:00';
        $post->post_modified = '2026-01-16 10:00:00';
        $post->post_author = 1;
        foreach ($fields as $key => $value) {
            if ($key === 'meta' || $key === 'categories' || $key === 'tags') {
                continue;
            }
            $post->{$key} = $value;
        }
        self::$posts[$id] = $post;
        foreach ((array) ($fields['meta'] ?? []) as $key => $value) {
            self::$postMeta[$id][(string) $key] = [$value];
        }
        foreach (['categories' => 'category', 'tags' => 'post_tag'] as $field => $taxonomy) {
            foreach ((array) ($fields[$field] ?? []) as $termId => $name) {
                $term = new WP_Term();
                $term->term_id = (int) $termId;
                $term->name = (string) $name;
                $term->taxonomy = $taxonomy;
                self::$postTerms[$id][$taxonomy][] = $term;
            }
        }
        return $post;
    }

    /**
     * @param list<string> $caps
     * @param array<string, mixed> $meta
     */
    public static function addUser(int $id, string $display, array $caps = [], string $login = '', array $meta = [], string $email = ''): WP_User
    {
        $user = new WP_User();
        $user->ID = $id;
        $user->display_name = $display;
        $user->user_login = $login !== '' ? $login : 'user' . $id;
        $user->user_email = $email !== '' ? $email : 'user' . $id . '@example.org';
        self::$users[$id] = $user;
        self::$caps[$id] = $caps;
        self::$userMeta[$id] = $meta;
        return $user;
    }

    /**
     * @param list<mixed> $args
     */
    public static function apply(string $hook, mixed $value, array $args): mixed
    {
        if (!isset(self::$filters[$hook])) {
            return $value;
        }
        ksort(self::$filters[$hook]);
        foreach (self::$filters[$hook] as $callbacks) {
            foreach ($callbacks as [$callback, $accepted]) {
                $value = $callback(...array_slice(array_merge([$value], $args), 0, max(1, $accepted)));
            }
        }
        return $value;
    }
}

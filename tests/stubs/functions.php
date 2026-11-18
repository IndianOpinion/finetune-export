<?php

declare(strict_types=1);

// phpcs:ignoreFile -- WordPress function stand-ins for the test suite.

namespace WP_CLI\Utils {
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string>               $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        foreach ($items as $item) {
            $parts = [];
            foreach ($fields as $field) {
                $parts[] = $field . '=' . (string) ($item[$field] ?? '');
            }
            \WP_CLI::line(implode(' ', $parts));
        }
    }
}

namespace {
    // Hooks.
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool
    {
        WpStub::$filters[$hook][$priority][] = [$callback, $accepted];
        return true;
    }

    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool
    {
        return add_filter($hook, $callback, $priority, $accepted);
    }

    function remove_all_filters(string $hook): void
    {
        unset(WpStub::$filters[$hook]);
    }

    function has_filter(string $hook): bool
    {
        return isset(WpStub::$filters[$hook]);
    }

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return WpStub::apply($hook, $value, $args);
    }

    function do_action(string $hook, mixed ...$args): void
    {
        $GLOBALS['wp_stub_actions'][] = [$hook, $args];
        WpStub::apply($hook, $args[0] ?? null, array_slice($args, 1));
    }

    // Options and transients.
    function get_option(string $name, mixed $default = false): mixed
    {
        return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
    }

    function add_option(string $name, mixed $value = '', string $deprecated = '', bool|string|null $autoload = null): bool
    {
        if (array_key_exists($name, WpStub::$options)) {
            return false;
        }
        WpStub::$options[$name] = $value;
        return true;
    }

    function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool
    {
        WpStub::$options[$name] = $value;
        return true;
    }

    function delete_option(string $name): bool
    {
        unset(WpStub::$options[$name]);
        return true;
    }

    function set_transient(string $name, mixed $value, int $ttl = 0): bool
    {
        WpStub::$transients[$name] = $value;
        return true;
    }

    function get_transient(string $name): mixed
    {
        return WpStub::$transients[$name] ?? false;
    }

    function delete_transient(string $name): bool
    {
        unset(WpStub::$transients[$name]);
        return true;
    }

    // Posts, terms, meta.
    /**
     * @param array<string, mixed> $args
     * @return list<int|WP_Post>
     */
    function get_posts(array $args = []): array
    {
        $types = (array) ($args['post_type'] ?? ['post']);
        $statuses = (array) ($args['post_status'] ?? ['publish']);
        $after = (int) ($args['finetune_export_after_id'] ?? 0);
        $in = isset($args['post__in']) ? array_map('intval', (array) $args['post__in']) : null;
        $notIn = array_map('intval', (array) ($args['post__not_in'] ?? []));
        $out = [];
        $posts = WpStub::$posts;
        ksort($posts);
        foreach ($posts as $id => $post) {
            if ($id <= $after || in_array($id, $notIn, true)) {
                continue;
            }
            if ($in !== null) {
                if (!in_array($id, $in, true)) {
                    continue;
                }
            } elseif (!in_array($post->post_type, $types, true) || !in_array($post->post_status, $statuses, true)) {
                continue;
            }
            if (array_key_exists('has_password', $args) && $args['has_password'] === false && $post->post_password !== '') {
                continue;
            }
            $out[] = ($args['fields'] ?? '') === 'ids' ? $id : $post;
        }
        $limit = (int) ($args['posts_per_page'] ?? 10);
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    function get_post(int|WP_Post|null $post = null): ?WP_Post
    {
        if ($post instanceof WP_Post) {
            return $post;
        }
        return WpStub::$posts[(int) $post] ?? null;
    }

    /**
     * @return array<string, WP_Post_Type|string>
     */
    function get_post_types(array $args = [], string $output = 'names'): array
    {
        $types = [
            'post' => new WP_Post_Type('post', 'Posts'),
            'page' => new WP_Post_Type('page', 'Pages'),
            'attachment' => new WP_Post_Type('attachment', 'Media'),
        ];
        return $output === 'objects' ? $types : array_combine(array_keys($types), array_keys($types));
    }

    function get_post_meta(int $id, string $key = '', bool $single = false): mixed
    {
        $values = WpStub::$postMeta[$id][$key] ?? [];
        if ($single) {
            return $values[0] ?? '';
        }
        return $values;
    }

    function update_post_meta(int $id, string $key, mixed $value): bool
    {
        WpStub::$postMeta[$id][$key] = [$value];
        return true;
    }

    function delete_post_meta(int $id, string $key): bool
    {
        unset(WpStub::$postMeta[$id][$key]);
        return true;
    }

    function delete_post_meta_by_key(string $key): bool
    {
        foreach (WpStub::$postMeta as $id => $meta) {
            unset(WpStub::$postMeta[$id][$key]);
        }
        return true;
    }

    function delete_metadata(string $type, int $objectId, string $key, mixed $value = '', bool $all = false): bool
    {
        if ($type === 'term') {
            foreach (WpStub::$termMeta as $id => $meta) {
                unset(WpStub::$termMeta[$id][$key]);
            }
        }
        return true;
    }

    /**
     * @return list<WP_Term>|false
     */
    function get_the_terms(int $postId, string $taxonomy): array|false
    {
        return WpStub::$postTerms[$postId][$taxonomy] ?? false;
    }

    function get_term_meta(int $termId, string $key = '', bool $single = false): mixed
    {
        return WpStub::$termMeta[$termId][$key] ?? '';
    }

    function update_term_meta(int $termId, string $key, mixed $value): bool
    {
        WpStub::$termMeta[$termId][$key] = $value;
        return true;
    }

    function delete_term_meta(int $termId, string $key): bool
    {
        unset(WpStub::$termMeta[$termId][$key]);
        return true;
    }

    function get_permalink(int|WP_Post $post): string|false
    {
        $id = $post instanceof WP_Post ? $post->ID : $post;
        return 'https://example.org/?p=' . $id;
    }

    function get_post_datetime(int|WP_Post $post, string $field = 'date'): ?DateTimeImmutable
    {
        $post = get_post($post);
        if ($post === null) {
            return null;
        }
        $value = $field === 'modified' ? $post->post_modified : $post->post_date;
        return new DateTimeImmutable($value, new DateTimeZone(WpStub::$timezone ?? 'UTC'));
    }

    function setup_postdata(WP_Post $post): bool
    {
        return true;
    }

    function do_blocks(string $content): string
    {
        return (string) preg_replace('/<!--\s+wp:([a-z\/-]+)[^>]*\/-->/', '<p>Rendered $1</p>', $content);
    }

    /** @return list<string> */
    function get_dynamic_block_names(): array
    {
        return WpStub::$dynamicBlocks;
    }

    function wp_is_post_revision(int $id): bool
    {
        return false;
    }

    function wp_is_post_autosave(int $id): bool
    {
        return false;
    }

    function use_block_editor_for_post(WP_Post $post): bool
    {
        return false;
    }

    function register_post_meta(string $type, string $key, array $args): bool
    {
        return true;
    }

    function register_term_meta(string $taxonomy, string $key, array $args): bool
    {
        return true;
    }

    // Users and capabilities.
    function get_userdata(int $id): WP_User|false
    {
        return WpStub::$users[$id] ?? false;
    }

    /** @return list<WP_User> */
    function get_users(array $args = []): array
    {
        $users = WpStub::$users;
        ksort($users);
        return array_values($users);
    }

    function get_user_meta(int $id, string $key = '', bool $single = false): mixed
    {
        return WpStub::$userMeta[$id][$key] ?? '';
    }

    function get_current_user_id(): int
    {
        return WpStub::$currentUser;
    }

    function wp_set_current_user(int $id): void
    {
        WpStub::$currentUser = $id;
    }

    function user_can(int $userId, string $cap, mixed ...$args): bool
    {
        if (!isset(WpStub::$users[$userId])) {
            return false;
        }
        $caps = apply_filters('map_meta_cap', [$cap], $cap, $userId, $args);
        if (in_array('do_not_allow', (array) $caps, true)) {
            return false;
        }
        return in_array($cap, WpStub::$caps[$userId] ?? [], true) || in_array($userId, WpStub::$superAdmins, true);
    }

    function current_user_can(string $cap, mixed ...$args): bool
    {
        return user_can(WpStub::$currentUser, $cap, ...$args);
    }

    function is_super_admin(int $userId = 0): bool
    {
        return in_array($userId, WpStub::$superAdmins, true);
    }

    function wp_validate_auth_cookie(string $cookie = '', string $scheme = ''): int|false
    {
        return WpStub::$cookieUser > 0 ? WpStub::$cookieUser : false;
    }

    function get_role(string $role): ?WP_Role
    {
        return WpStub::$roles[$role] ?? null;
    }

    function wp_roles(): WP_Roles
    {
        $roles = new WP_Roles();
        $roles->role_objects = WpStub::$roles;
        return $roles;
    }

    function is_email(string $email): string|false
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
    }

    // Multisite.
    function is_multisite(): bool
    {
        return WpStub::$multisite;
    }

    function get_current_blog_id(): int
    {
        return WpStub::$blogId;
    }

    /** @return list<int> */
    function get_sites(array $args = []): array
    {
        return [1];
    }

    function switch_to_blog(int $id): bool
    {
        return true;
    }

    function restore_current_blog(): bool
    {
        return true;
    }

    // Cron.
    function wp_schedule_single_event(int $time, string $hook, array $args = []): bool
    {
        WpStub::$cron[] = ['time' => $time, 'hook' => $hook, 'args' => $args, 'recurrence' => false];
        return true;
    }

    function wp_schedule_event(int $time, string $recurrence, string $hook, array $args = []): bool
    {
        WpStub::$cron[] = ['time' => $time, 'hook' => $hook, 'args' => $args, 'recurrence' => $recurrence];
        return true;
    }

    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        foreach (WpStub::$cron as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args) {
                return $event['time'];
            }
        }
        return false;
    }

    function wp_clear_scheduled_hook(string $hook, array $args = []): int
    {
        $before = count(WpStub::$cron);
        WpStub::$cron = array_values(array_filter(WpStub::$cron, static fn (array $e): bool => !($e['hook'] === $hook && $e['args'] === $args)));
        return $before - count(WpStub::$cron);
    }

    function wp_unschedule_hook(string $hook): int
    {
        $before = count(WpStub::$cron);
        WpStub::$cron = array_values(array_filter(WpStub::$cron, static fn (array $e): bool => $e['hook'] !== $hook));
        return $before - count(WpStub::$cron);
    }

    function spawn_cron(): bool
    {
        return true;
    }

    // HTTP and mail.
    /** @return array<string, mixed>|WP_Error */
    function wp_remote_get(string $url, array $args = []): array|WP_Error
    {
        WpStub::$http[] = ['url' => $url, 'args' => $args];
        return (WpStub::$httpResponder)($url, $args);
    }

    function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
    }

    function wp_remote_retrieve_body(array|WP_Error $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }

    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }

    function wp_mail(string|array $to, string $subject, string $message, mixed $headers = '', mixed $attachments = []): bool
    {
        WpStub::$mails[] = ['to' => (string) (is_array($to) ? implode(',', $to) : $to), 'subject' => $subject, 'message' => $message];
        return true;
    }

    // URLs and paths.
    function home_url(string $path = ''): string
    {
        return 'https://example.org' . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    function site_url(string $path = ''): string
    {
        return home_url($path);
    }

    function content_url(string $path = ''): string
    {
        return 'https://example.org/wp-content' . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    function admin_url(string $path = ''): string
    {
        return 'https://example.org/wp-admin/' . ltrim($path, '/');
    }

    function rest_url(string $path = ''): string
    {
        return 'https://example.org/wp-json/' . ltrim($path, '/');
    }

    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'https://example.org/wp-content/plugins/finetune-export/' . ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    function wp_upload_dir(?string $time = null, bool $create = true): array
    {
        return ['basedir' => WpStub::$uploadsBase, 'baseurl' => 'https://example.org/wp-content/uploads'];
    }

    function add_query_arg(array $args, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }

    function wp_salt(string $scheme = 'auth'): string
    {
        return 'test-salt-' . $scheme;
    }

    function register_rest_route(string $namespace, string $route, array $args): bool
    {
        WpStub::$routes[] = [$namespace, $route, $args];
        return true;
    }

    function register_activation_hook(string $file, callable $callback): void
    {
    }

    function register_deactivation_hook(string $file, callable $callback): void
    {
    }

    function dbDelta(string $sql): array
    {
        return [];
    }

    // Escaping, i18n and form helpers.
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }

    function esc_html__(string $text, string $domain = ''): string
    {
        return esc_html($text);
    }

    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }

    function esc_textarea(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    function wp_kses(string $html, array $allowed): string
    {
        return $html;
    }

    function sanitize_key(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }

    function sanitize_text_field(string $text): string
    {
        return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($text)));
    }

    function sanitize_textarea_field(string $text): string
    {
        return trim(strip_tags($text));
    }

    function absint(mixed $value): int
    {
        return abs((int) $value);
    }

    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }

    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }

    function wp_date(string $format, ?int $timestamp = null): string
    {
        return gmdate($format, $timestamp ?? time());
    }

    function size_format(int $bytes): string
    {
        return $bytes . ' B';
    }

    function checked(mixed $a, mixed $b = true, bool $echo = true): string
    {
        return $a == $b ? 'checked="checked"' : '';
    }

    function selected(mixed $a, mixed $b = true, bool $echo = true): string
    {
        return (string) $a === (string) $b ? 'selected="selected"' : '';
    }

    function disabled(mixed $a, mixed $b = true, bool $echo = true): string
    {
        return $a == $b ? 'disabled="disabled"' : '';
    }

    function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
    {
        $field = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
        echo $field;
        return $field;
    }

    function wp_create_nonce(string $action = '-1'): string
    {
        return 'nonce-' . $action;
    }

    function wp_verify_nonce(string $nonce, string $action = '-1'): int|false
    {
        return $nonce === 'nonce-' . $action ? 1 : false;
    }

    function check_admin_referer(string $action = '-1', string $arg = '_wpnonce'): int
    {
        $nonce = (string) ($_REQUEST[$arg] ?? $_POST[$arg] ?? '');
        if (wp_verify_nonce($nonce, $action) === false) {
            throw new RuntimeException('Nonce check failed for ' . $action);
        }
        return 1;
    }

    function submit_button(string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true): void
    {
        echo '<button type="submit" name="' . $name . '">' . esc_html($text) . '</button>';
    }

    function wp_die(string $message = '', mixed $title = '', mixed $args = []): never
    {
        throw new RuntimeException('wp_die: ' . $message);
    }

    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        $GLOBALS['wp_stub_redirect'] = $location;
        return true;
    }

    function add_management_page(string $title, string $menu, string $cap, string $slug, callable $callback): string
    {
        return $slug;
    }

    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = []): void
    {
    }

    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        return true;
    }
}

namespace {
    function __return_true(): bool
    {
        return true;
    }

    function __return_false(): bool
    {
        return false;
    }
}

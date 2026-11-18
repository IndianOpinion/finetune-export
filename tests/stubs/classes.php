<?php

declare(strict_types=1);

// phpcs:ignoreFile -- WordPress class stand-ins for the test suite.

class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_password = '';
    public string $post_title = '';
    public string $post_excerpt = '';
    public string $post_content = '';
    public string $post_date = '';
    public string $post_date_gmt = '';
    public string $post_modified = '';
    public int|string $post_author = 0;
}

class WP_User
{
    public int $ID = 0;
    public string $display_name = '';
    public string $user_login = '';
    public string $user_email = '';
}

class WP_Term
{
    public int $term_id = 0;
    public string $name = '';
    public string $taxonomy = '';
}

class WP_Post_Type
{
    public function __construct(public string $name, public string $label)
    {
    }
}

class WP_Role
{
    /**
     * @param array<string, bool> $capabilities
     */
    public function __construct(public string $name, public array $capabilities)
    {
    }

    public function add_cap(string $cap): void
    {
        $this->capabilities[$cap] = true;
    }

    public function remove_cap(string $cap): void
    {
        unset($this->capabilities[$cap]);
    }
}

class WP_Roles
{
    /** @var array<string, WP_Role> */
    public array $role_objects = [];
}

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public mixed $data = null)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

class WP_Query
{
    /** @param array<string, mixed> $vars */
    public function __construct(public array $vars = [])
    {
    }

    public function get(string $key): mixed
    {
        return $this->vars[$key] ?? '';
    }
}

class WP_REST_Request
{
    /** @var array<string, mixed> */
    private array $params = [];
    /** @var array<string, string> */
    private array $headers = [];
    private mixed $json = null;

    public function __construct(private string $method = 'GET', private string $route = '')
    {
    }

    public function set_param(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function set_header(string $key, string $value): void
    {
        $this->headers[strtolower($key)] = $value;
    }

    public function get_header(string $key): ?string
    {
        return $this->headers[strtolower($key)] ?? null;
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function set_body_json(mixed $json): void
    {
        $this->json = $json;
    }

    public function get_json_params(): mixed
    {
        return $this->json;
    }
}

class WP_REST_Response
{
    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function get_data(): mixed
    {
        return $this->data;
    }
}

/**
 * A small wpdb that understands exactly the queries the plugin sends.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $last_error = '';
    public int $insert_id = 0;
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var array<string, int> */
    private array $autoIncrement = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[ids]/', function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? '';
            return match ($m[0]) {
                '%i' => '`' . str_replace('`', '', (string) $value) . '`',
                '%d' => (string) (int) $value,
                default => "'" . addslashes((string) $value) . "'",
            };
        }, $query);
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data, mixed $format = null): int|false
    {
        $this->autoIncrement[$table] = ($this->autoIncrement[$table] ?? 0) + 1;
        $id = $this->autoIncrement[$table];
        $data['id'] = $id;
        $this->tables[$table][$id] = $data;
        $this->insert_id = $id;
        return 1;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where, mixed $format = null, mixed $whereFormat = null): int|false
    {
        $id = (int) $where['id'];
        if (!isset($this->tables[$table][$id])) {
            return 0;
        }
        $this->tables[$table][$id] = array_merge($this->tables[$table][$id], $data);
        return 1;
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where, mixed $format = null): int|false
    {
        $n = 0;
        foreach ($this->tables[$table] ?? [] as $key => $row) {
            $match = true;
            foreach ($where as $column => $value) {
                if ((string) ($row[$column] ?? '') !== (string) $value) {
                    $match = false;
                }
            }
            if ($match) {
                unset($this->tables[$table][$key]);
                $n++;
            }
        }
        return $n;
    }

    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        $rows = $this->select($query);
        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        return $this->select($query);
    }

    public function get_var(string $query): ?string
    {
        if (preg_match('/SELECT post_id FROM `(\w+)` WHERE job_id = (\d+) AND hash = \'(\w+)\'/', $query, $m) === 1) {
            $row = $this->tables[$m[1]][$m[2] . ':' . $m[3]] ?? null;
            return $row === null ? null : (string) $row['post_id'];
        }
        return null;
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;
        if (preg_match('/INSERT IGNORE INTO `(\w+)` \(job_id, hash, post_id\) VALUES \((\d+), \'(\w+)\', (\d+)\)/', $query, $m) === 1) {
            $key = $m[2] . ':' . $m[3];
            if (isset($this->tables[$m[1]][$key])) {
                return 0;
            }
            $this->tables[$m[1]][$key] = ['job_id' => (int) $m[2], 'hash' => $m[3], 'post_id' => (int) $m[4]];
            return 1;
        }
        if (preg_match('/DROP TABLE IF EXISTS `(\w+)`/', $query, $m) === 1) {
            unset($this->tables[$m[1]]);
            return true;
        }
        return true;
    }

    /** @return list<array<string, mixed>> */
    private function select(string $query): array
    {
        if (preg_match('/SELECT \* FROM `(\w+)` WHERE id = (\d+)/', $query, $m) === 1) {
            $row = $this->tables[$m[1]][(int) $m[2]] ?? null;
            return $row === null ? [] : [$row];
        }
        if (preg_match('/SELECT \* FROM `(\w+)` ORDER BY id DESC LIMIT (\d+)/', $query, $m) === 1) {
            $rows = $this->tables[$m[1]] ?? [];
            krsort($rows);
            return array_values(array_slice($rows, 0, (int) $m[2]));
        }
        if (preg_match('/SELECT \* FROM `(\w+)` WHERE state IN \(([^)]*)\) ORDER BY id ASC/', $query, $m) === 1) {
            $states = array_map(static fn (string $s): string => trim($s, " '"), explode(',', $m[2]));
            $rows = $this->tables[$m[1]] ?? [];
            ksort($rows);
            return array_values(array_filter($rows, static fn (array $r): bool => in_array($r['state'], $states, true)));
        }
        throw new RuntimeException('Unexpected query: ' . $query);
    }
}

class WP_CLI_Exit extends RuntimeException
{
}

class WP_CLI
{
    /** @var list<string> */
    public static array $lines = [];
    /** @var list<string> */
    public static array $errors = [];

    public static function add_command(string $name, mixed $callable): void
    {
    }

    public static function line(string $message = ''): void
    {
        self::$lines[] = $message;
    }

    public static function log(string $message): void
    {
        self::$lines[] = $message;
    }

    public static function success(string $message): void
    {
        self::$lines[] = 'Success: ' . $message;
    }

    public static function warning(string $message): void
    {
        self::$lines[] = 'Warning: ' . $message;
    }

    public static function error(string $message): never
    {
        self::$errors[] = $message;
        throw new WP_CLI_Exit($message);
    }

    /** @param array<string, mixed> $assoc */
    public static function confirm(string $question, array $assoc = []): void
    {
        if (empty($assoc['yes'])) {
            self::error('Confirmation needed: ' . $question);
        }
    }
}

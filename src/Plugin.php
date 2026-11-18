<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

use IndianOpinion\FinetuneExport\Content\WpContentSource;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Jobs\JobRepository;
use IndianOpinion\FinetuneExport\Jobs\OptionLock;
use IndianOpinion\FinetuneExport\Jobs\WpdbJobRepository;
use IndianOpinion\FinetuneExport\Links\LinkService;
use IndianOpinion\FinetuneExport\Links\LinkSigner;
use IndianOpinion\FinetuneExport\Rest\DownloadHandler;
use IndianOpinion\FinetuneExport\Storage\Canary;
use IndianOpinion\FinetuneExport\Storage\Storage;
use IndianOpinion\FinetuneExport\Storage\StorageEnvironment;
use IndianOpinion\FinetuneExport\Support\Clock;
use IndianOpinion\FinetuneExport\Support\Logger;
use IndianOpinion\FinetuneExport\Support\SystemClock;

/**
 * Wires the plugin into WordPress and builds its services once per request.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const CAP_RUN = 'finetune_export_run';
    public const CAP_SENSITIVE = 'finetune_export_sensitive';
    public const POST_OPTOUT_META = '_finetune_export_exclude';
    public const TERM_OPTOUT_META = 'finetune_export_exclude';
    public const REST_NAMESPACE = 'finetune-export/v1';
    public const CRON_STEP = 'finetune_export_step';
    public const CRON_DAILY = 'finetune_export_daily';
    public const WATCHDOG_DELAY = 900;
    public const QUERY_AFTER_ID = 'finetune_export_after_id';

    private static ?self $instance = null;

    private Clock $clock;
    private Logger $logger;
    private Settings $settings;
    private Profiles $profiles;
    private ?Storage $storage = null;
    private ?JobRepository $jobs = null;
    private ?Exporter $exporter = null;
    private ?LinkService $links = null;
    private ?LinkSigner $signer = null;

    public function __construct(?Clock $clock = null, ?JobRepository $jobs = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->logger = new Logger();
        $this->settings = new Settings();
        $this->profiles = new Profiles();
        $this->jobs = $jobs;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replaces the shared instance (tests).
     */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public static function boot(string $file): void
    {
        $plugin = self::instance();
        register_activation_hook($file, [self::class, 'activate']);
        register_deactivation_hook($file, [self::class, 'deactivate']);
        $plugin->register();
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerMeta']);
        add_filter('posts_where', [$this, 'filterPostsWhere'], 10, 2);
        add_filter('map_meta_cap', [$this, 'mapMetaCap'], 10, 4);
        add_action('rest_api_init', [new Rest\RestController($this), 'register']);
        add_action(self::CRON_STEP, [$this, 'cronStep'], 10, 2);
        add_action(self::CRON_DAILY, [$this, 'daily']);
        add_action('admin_init', [$this, 'maybeUpgrade']);
        add_filter('site_status_tests', [new Admin\SiteHealth($this), 'register']);
        (new Admin\AdminScreen($this))->register();
        (new Admin\OptOut())->register();
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('finetune-export', new Cli\Command($this));
        }
    }

    /**
     * Activation: tables, capabilities, secret, daily event.
     */
    public static function activate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::activateSite();
                restore_current_blog();
            }
            return;
        }
        self::activateSite();
    }

    private static function activateSite(): void
    {
        self::instance()->installTables();
        $role = get_role('administrator');
        if ($role instanceof \WP_Role) {
            $role->add_cap(self::CAP_RUN);
            $role->add_cap(self::CAP_SENSITIVE);
        }
        Settings::secret();
        if (!wp_next_scheduled(self::CRON_DAILY)) {
            wp_schedule_event(time() + 3600, 'daily', self::CRON_DAILY);
        }
    }

    /**
     * Deactivation removes scheduled events and nothing else (case 101).
     */
    public static function deactivate(): void
    {
        wp_unschedule_hook(self::CRON_STEP);
        wp_unschedule_hook(self::CRON_DAILY);
    }

    public function installTables(): void
    {
        global $wpdb;
        $repo = new WpdbJobRepository($wpdb);
        if (!function_exists('dbDelta')) {
            $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
            require_once $upgrade;
        }
        foreach ($repo->schema() as $sql) {
            dbDelta($sql);
        }
        update_option(Settings::DB_VERSION_OPTION, WpdbJobRepository::SCHEMA_VERSION, false);
    }

    public function maybeUpgrade(): void
    {
        if (get_option(Settings::DB_VERSION_OPTION) !== WpdbJobRepository::SCHEMA_VERSION) {
            $this->installTables();
        }
    }

    public function registerMeta(): void
    {
        register_post_meta('', self::POST_OPTOUT_META, [
            'type' => 'boolean',
            'single' => true,
            'default' => false,
            'show_in_rest' => true,
            'auth_callback' => static fn (): bool => current_user_can(self::CAP_RUN),
        ]);
        register_term_meta('category', self::TERM_OPTOUT_META, [
            'type' => 'boolean',
            'single' => true,
            'default' => false,
            'show_in_rest' => false,
            'auth_callback' => static fn (): bool => current_user_can(self::CAP_RUN),
        ]);
    }

    /**
     * Adds `ID > n` for the plugin's own queries, so selection stays in
     * query arguments (case 8) and resumes by id (case 9).
     */
    public function filterPostsWhere(string $where, mixed $query): string
    {
        if (!$query instanceof \WP_Query) {
            return $where;
        }
        $after = $query->get(self::QUERY_AFTER_ID);
        if ($after === '' || $after === null) {
            return $where;
        }
        global $wpdb;
        return $where . ' AND ' . $wpdb->posts . '.ID > ' . max(0, (int) $after);
    }

    /**
     * On multisite only super admins hold the sensitive capability (5.6).
     *
     * @param list<string> $caps
     * @param mixed        ...$args
     * @return list<string>
     */
    public function mapMetaCap(array $caps, string $cap, int $userId, mixed ...$args): array
    {
        if ($cap === self::CAP_SENSITIVE && is_multisite() && !is_super_admin($userId)) {
            return ['do_not_allow'];
        }
        return $caps;
    }

    /**
     * Cron handler for one batch. A watchdog event 15 minutes out resumes a
     * batch that died (case 81); it is cleared when the job ends.
     */
    public function cronStep(int $jobId, string $mode = ''): void
    {
        $watchdog = [$jobId, 'watchdog'];
        if (!wp_next_scheduled(self::CRON_STEP, $watchdog)) {
            wp_schedule_single_event(time() + self::WATCHDOG_DELAY, self::CRON_STEP, $watchdog);
        }
        $job = $this->exporter()->step($jobId);
        if ($job->isFinal() || $job->state === Job::STALLED) {
            wp_clear_scheduled_hook(self::CRON_STEP, $watchdog);
            $this->scheduleNextQueued();
            return;
        }
        if ($job->state === Job::RUNNING) {
            $this->scheduleStep($jobId);
        } elseif ($job->state === Job::QUEUED) {
            wp_clear_scheduled_hook(self::CRON_STEP, $watchdog);
        }
    }

    public function scheduleStep(int $jobId, int $delay = 0): void
    {
        if (!wp_next_scheduled(self::CRON_STEP, [$jobId])) {
            wp_schedule_single_event(time() + $delay, self::CRON_STEP, [$jobId]);
        }
        if (function_exists('spawn_cron') && !(defined('WP_CLI') && constant('WP_CLI'))) {
            spawn_cron();
        }
    }

    public function scheduleNextQueued(): void
    {
        foreach ($this->jobs()->inStates([Job::QUEUED]) as $job) {
            $this->scheduleStep($job->id);
            return;
        }
    }

    /**
     * Daily event: stall detection, retention, missing files, canary.
     */
    public function daily(): void
    {
        $exporter = $this->exporter();
        $exporter->detectStalled();
        try {
            $exporter->applyRetention($this->settings->retentionDays());
        } catch (UnsafeDirectory $e) {
            $this->logger->warning('retention skipped: no safe directory');
        }
        foreach ($this->jobs()->all(500) as $job) {
            $exporter->checkMissing($job);
        }
        $this->canary()->run();
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function profiles(): Profiles
    {
        return $this->profiles;
    }

    public function jobs(): JobRepository
    {
        if ($this->jobs === null) {
            global $wpdb;
            $this->jobs = new WpdbJobRepository($wpdb);
        }
        return $this->jobs;
    }

    public function storageEnvironment(): StorageEnvironment
    {
        $uploads = wp_upload_dir(null, false);
        $filtered = apply_filters('finetune_export_directory', null);
        return new StorageEnvironment(
            ABSPATH,
            WP_CONTENT_DIR,
            is_array($uploads) && isset($uploads['basedir']) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads',
            defined('FINETUNE_EXPORT_DIR') ? (string) constant('FINETUNE_EXPORT_DIR') : null,
            is_string($filtered) ? $filtered : null,
            $this->settings->acknowledgedPath(),
            is_multisite() ? get_current_blog_id() : 0,
            array_keys($this->webRootUrls()),
        );
    }

    /**
     * Directories a web server serves beyond ABSPATH and the content
     * directory, with the URL each is served at: the home directory when
     * WordPress lives in a subdirectory, and the document root.
     *
     * @return array<string, string> path => URL
     */
    public function webRootUrls(): array
    {
        $out = [];
        $home = Storage::homeDirectory(ABSPATH, home_url('/'), site_url('/'));
        if ($home !== null) {
            $out[$home] = rtrim(home_url('/'), '/');
        }
        $docroot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])
            ? Storage::normalise(sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])))
            : null;
        $origin = DownloadHandler::originOf(home_url('/'));
        if ($docroot !== null && $docroot !== '/' && $origin !== null && !isset($out[$docroot])) {
            $out[$docroot] = $origin;
        }
        return $out;
    }

    public function storage(): Storage
    {
        return $this->storage ??= new Storage($this->storageEnvironment(), $this->logger);
    }

    /**
     * Forgets the storage after a settings change.
     */
    public function resetStorage(): void
    {
        $this->storage = null;
        $this->exporter = null;
    }

    public function blocked(): bool
    {
        try {
            $path = $this->storage()->resolve()->path;
        } catch (UnsafeDirectory $e) {
            return false;
        }
        return Canary::blocked($path);
    }

    public function exporter(): Exporter
    {
        global $wpdb;
        return $this->exporter ??= new Exporter(
            new WpContentSource(),
            $this->jobs(),
            $this->storage(),
            new OptionLock($this->clock, $wpdb),
            new Cleaner(),
            $this->logger,
            $this->clock,
            fn (): bool => $this->blocked(),
        );
    }

    public function signer(): LinkSigner
    {
        return $this->signer ??= new LinkSigner(
            wp_salt('auth') . wp_salt('secure_auth') . wp_salt('logged_in'),
            Settings::secret(),
            $this->clock
        );
    }

    public function links(): LinkService
    {
        return $this->links ??= new LinkService($this->signer(), $this->jobs(), $this->logger, $this->clock);
    }

    public function canary(): Canary
    {
        $urls = [
            (string) Storage::normalise(ABSPATH) => rtrim(site_url('/'), '/'),
            (string) Storage::normalise(WP_CONTENT_DIR) => rtrim(content_url(), '/'),
        ] + $this->webRootUrls();
        return new Canary($this->storage(), $this->logger, $this->clock, $urls);
    }

    public function downloadHandler(): DownloadHandler
    {
        $origins = array_values(array_unique(array_filter([
            DownloadHandler::originOf(home_url('/')),
            DownloadHandler::originOf(site_url('/')),
        ])));
        return new DownloadHandler(
            $this->signer(),
            $this->jobs(),
            $this->storage(),
            $this->logger,
            $this->clock,
            $origins,
            static fn (int $userId): bool => user_can($userId, self::CAP_RUN),
            fn (): bool => $this->blocked(),
            (bool) apply_filters('finetune_export_log_download_ip', false),
        );
    }

    /**
     * Starts a job for a user after the capability checks of section 5.6.
     */
    public function startJob(Profile $profile, int $userId, string $kind, bool $canSensitive): Job
    {
        Profiles::assertAllowed($profile, $canSensitive);
        $job = $this->exporter()->start($profile, $userId, $kind);
        $this->scheduleStep($job->id);
        return $job;
    }
}

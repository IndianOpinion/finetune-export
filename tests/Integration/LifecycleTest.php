<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Integration;

use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Jobs\WpdbJobRepository;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Settings;
use IndianOpinion\FinetuneExport\Tests\TestCase;
use IndianOpinion\FinetuneExport\Uninstaller;

final class LifecycleTest extends TestCase
{
    public function testActivation(): void
    {
        Plugin::setInstance(new Plugin($this->clock, $this->jobs));
        Plugin::activate();
        self::assertTrue(\WpStub::$roles['administrator']->capabilities[Plugin::CAP_RUN]);
        self::assertTrue(\WpStub::$roles['administrator']->capabilities[Plugin::CAP_SENSITIVE]);
        self::assertArrayNotHasKey(Plugin::CAP_RUN, \WpStub::$roles['editor']->capabilities);
        self::assertSame(64, strlen((string) get_option(Settings::SECRET_OPTION)));
        self::assertNotFalse(wp_next_scheduled(Plugin::CRON_DAILY));
        self::assertSame(WpdbJobRepository::SCHEMA_VERSION, get_option(Settings::DB_VERSION_OPTION));
    }

    /** Test 95: deactivation clears scheduled events only. */
    public function testDeactivation(): void
    {
        update_option(Settings::OPTION, ['retention_days' => 3]);
        wp_schedule_event(1, 'daily', Plugin::CRON_DAILY);
        wp_schedule_single_event(1, Plugin::CRON_STEP, [4]);
        wp_schedule_single_event(1, 'other_plugin_hook');
        $dir = $this->exportDir();
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/export-1-' . str_repeat('a', 32) . '.jsonl', '{}');
        Plugin::deactivate();
        self::assertSame(['other_plugin_hook'], array_column(\WpStub::$cron, 'hook'));
        self::assertSame(['retention_days' => 3], get_option(Settings::OPTION));
        self::assertFileExists($dir . '/export-1-' . str_repeat('a', 32) . '.jsonl');
    }

    /** Test 96: uninstall removes options, tables and opt-out meta; export files stay. */
    public function testUninstall(): void
    {
        global $wpdb;
        $repo = new WpdbJobRepository($wpdb);
        $plugin = $this->plugin();
        \WpStub::addUser(1, 'Admin', [Plugin::CAP_RUN]);
        \WpStub::addPost(['ID' => 1, 'meta' => [Plugin::POST_OPTOUT_META => '1']]);
        \WpStub::addPost(['ID' => 2]);
        \WpStub::$termMeta[7][Plugin::TERM_OPTOUT_META] = '1';
        $exporter = $plugin->exporter();
        $job = $exporter->runForeground($exporter->start($this->profile(), 1)->id);
        $file = $plugin->storage()->path((string) $job->fileName('train'));
        $repo->insert(new Job());
        foreach (Settings::ALL_OPTIONS as $option) {
            update_option($option, 'x');
        }
        \WpStub::$roles['administrator']->add_cap(Plugin::CAP_RUN);

        Uninstaller::run();

        foreach (Settings::ALL_OPTIONS as $option) {
            self::assertFalse(get_option($option), $option);
        }
        self::assertArrayNotHasKey('wp_finetune_export_jobs', $wpdb->tables);
        self::assertContains('DROP TABLE IF EXISTS `wp_finetune_export_hashes`', $wpdb->queries);
        self::assertSame('', get_post_meta(1, Plugin::POST_OPTOUT_META, true));
        self::assertSame('', get_term_meta(7, Plugin::TERM_OPTOUT_META, true));
        self::assertArrayNotHasKey(Plugin::CAP_RUN, \WpStub::$roles['administrator']->capabilities);
        self::assertFileExists($file);
    }

    public function testUninstallFileNeverRemovesFiles(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php')
            . (string) file_get_contents(dirname(__DIR__, 2) . '/src/Uninstaller.php');
        self::assertDoesNotMatchRegularExpression('/\b(unlink|rmdir|removeExportFile|removeFiles|wp_delete_file)\s*\(/', $code);
    }

    /** Case 81 wiring: the cron step schedules a watchdog and the next batch, and clears them at the end. */
    public function testCronStepScheduling(): void
    {
        $plugin = $this->plugin();
        add_filter('finetune_export_batch_size', static fn (): int => 1);
        \WpStub::addPost(['ID' => 1]);
        \WpStub::addPost(['ID' => 2]);
        $job = $plugin->startJob($this->profile(), 1, Job::KIND_EXPORT, true);
        self::assertNotFalse(wp_next_scheduled(Plugin::CRON_STEP, [$job->id]));
        \WpStub::$cron = [];
        $plugin->cronStep($job->id);
        self::assertNotFalse(wp_next_scheduled(Plugin::CRON_STEP, [$job->id, 'watchdog']));
        self::assertNotFalse(wp_next_scheduled(Plugin::CRON_STEP, [$job->id]));
        $second = $plugin->startJob($this->profile(), 1, Job::KIND_EXPORT, true);
        \WpStub::$cron = [];
        $plugin->cronStep($job->id);
        $plugin->cronStep($job->id);
        self::assertSame(Job::COMPLETE, $this->jobs->find($job->id)?->state);
        self::assertFalse(wp_next_scheduled(Plugin::CRON_STEP, [$job->id, 'watchdog']));
        self::assertNotFalse(wp_next_scheduled(Plugin::CRON_STEP, [$second->id]), 'the queued job is scheduled next');
    }

    public function testDailyRunsRetentionAndStallCheck(): void
    {
        $plugin = $this->plugin();
        \WpStub::addPost(['ID' => 1]);
        $exporter = $plugin->exporter();
        $job = $exporter->runForeground($exporter->start($this->profile(), 1)->id);
        $plugin->settings()->update(['retention_days' => 1]);
        $this->clock->advance(2 * 86400);
        $plugin->daily();
        self::assertSame('removed', $this->jobs->find($job->id)?->fileState('train'));
        self::assertSame('not_needed', get_option(Settings::CANARY_OPTION)['result']);
    }

    public function testConstantsOverrideSettings(): void
    {
        $settings = new Settings();
        self::assertFalse($settings->locked('preview_size'));
        self::assertSame(0, $settings->retentionDays(), 'ruling 1: keep until deleted');
        self::assertSame(60, $settings->get('link_ttl_minutes'));
        self::assertSame(5, $settings->previewSize());
        self::assertSame(50, $settings->previewSize(500));
    }
}

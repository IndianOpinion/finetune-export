<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Integration;

use IndianOpinion\FinetuneExport\Admin\AdminScreen;
use IndianOpinion\FinetuneExport\Admin\OptOut;
use IndianOpinion\FinetuneExport\Admin\SiteHealth;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Rest\RestController;
use IndianOpinion\FinetuneExport\Tests\TestCase;

final class AdminTest extends TestCase
{
    private Plugin $plugin;
    private AdminScreen $screen;

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::addUser(1, 'Admin', [Plugin::CAP_RUN, Plugin::CAP_SENSITIVE, 'edit_post']);
        \WpStub::addUser(2, 'Editor', [Plugin::CAP_RUN]);
        \WpStub::addUser(3, 'Subscriber');
        \WpStub::$currentUser = 1;
        \WpStub::addPost(['ID' => 1, 'post_title' => 'Hello', 'post_content' => '<p>Write to asha@example.com today.</p>']);
        \WpStub::addPost(['ID' => 2, 'post_content' => '<p>Hi</p>']);
        $this->plugin = $this->plugin();
        $this->screen = new AdminScreen($this->plugin);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    private function render(string $tab, array $get = []): string
    {
        $_GET = ['tab' => $tab] + $get;
        ob_start();
        try {
            $this->screen->render();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function act(string $action, array $post = []): string
    {
        $_POST = $post + ['_wpnonce' => 'nonce-finetune_export_' . $action];
        $_REQUEST = $_POST;
        $this->screen->process($action);
        $flash = get_transient('finetune_export_flash_' . get_current_user_id());
        return is_array($flash) ? $flash['type'] . ': ' . $flash['message'] : '';
    }

    /** Section 8 and test 14: responsibility text above Run; author warning. */
    public function testProfilesTab(): void
    {
        $html = $this->render('profiles');
        self::assertStringContainsString(esc_html(AdminScreen::RESPONSIBILITY), $html);
        self::assertLessThan(strpos($html, 'Run export'), strpos($html, esc_html(AdminScreen::RESPONSIBILITY)));
        self::assertStringNotContainsString(esc_html(AdminScreen::AUTHOR_WARNING), $html);
        $this->plugin->profiles()->save(Profile::fromArray(['fields' => ['title', 'body', 'author']]), true);
        self::assertStringContainsString(esc_html(AdminScreen::AUTHOR_WARNING), $this->render('profiles'));
        self::assertStringContainsString('value="page"', $html);
        self::assertStringNotContainsString('value="attachment"', $html);
    }

    /** Tests 3 and 50 (screen side): sensitive settings refused without the capability. */
    public function testSensitiveSaveRefused(): void
    {
        \WpStub::$currentUser = 2;
        $message = $this->act('save_profile', ['profile' => ['id' => 'default', 'redaction' => ['phone' => '0']]]);
        self::assertStringStartsWith('error:', $message);
        self::assertStringContainsString('redaction_off:phone', $message);
        $html = $this->render('profiles');
        self::assertMatchesRegularExpression('/name="profile\[redaction\]\[phone\]" value="1" checked="checked" disabled="disabled"/', $html);
        \WpStub::$currentUser = 1;
        self::assertStringStartsWith('success:', $this->act('save_profile', ['profile' => ['id' => 'default', 'redaction' => ['phone' => '0']]]));
        self::assertFalse($this->plugin->profiles()->get('default')->redactionOn('phone'));
    }

    /** Test 83 to 85 (screen): preview of unsaved settings, highlighted, with estimates and skips, writing nothing. */
    public function testPreviewTab(): void
    {
        $_POST = ['finetune_export_preview' => '1', '_wpnonce_preview' => 'nonce-finetune_export_preview', 'profile' => ['id' => 'draft', 'min_length' => '5']];
        $_REQUEST = $_POST;
        $html = $this->render('preview');
        self::assertStringContainsString('<mark>[EMAIL]</mark>', $html);
        self::assertStringNotContainsString('asha@example.com', $html);
        self::assertStringContainsString(esc_html(AdminScreen::ESTIMATE_LABEL), $html);
        self::assertStringContainsString('2: too_short', $html);
        self::assertStringContainsString('email 1', $html);
        self::assertSame([], $this->jobs->all());
        self::assertDirectoryDoesNotExist($this->exportDir());
        self::assertArrayNotHasKey('draft', $this->plugin->profiles()->all());
    }

    /** Tests 51 and 89: jobs show estimate labels and "kept until deleted" or the removal date. */
    public function testJobsTab(): void
    {
        $this->act('run', ['profile' => 'default']);
        $job = $this->jobs->all()[0];
        $this->plugin->exporter()->runForeground($job->id);
        $html = $this->render('jobs');
        self::assertStringContainsString('kept until deleted', $html);
        self::assertStringContainsString(esc_html(AdminScreen::ESTIMATE_LABEL), $html);
        self::assertStringContainsString('Create train download link', $html);
        self::assertStringContainsString('Redaction report (counts only):', $html);
        $this->plugin->settings()->update(['retention_days' => 14]);
        self::assertStringContainsString('removed on ' . gmdate('Y-m-d', $this->clock->now + 14 * 86400), $this->render('jobs'));
    }

    public function testJobActionsAndLinkShownOnce(): void
    {
        $this->act('run', ['profile' => 'default']);
        $job = $this->plugin->exporter()->runForeground($this->jobs->all()[0]->id);
        self::assertStringStartsWith('success:', $this->act('link', ['job' => $job->id, 'kind' => 'train']));
        $html = $this->render('jobs');
        self::assertStringContainsString('/finetune-export/v1/download/', $html);
        self::assertStringNotContainsString('/finetune-export/v1/download/', $this->render('jobs'));
        \WpStub::$currentUser = 2;
        self::assertStringStartsWith('error:', $this->act('delete_files', ['job' => $job->id]));
        \WpStub::$currentUser = 1;
        self::assertStringStartsWith('success:', $this->act('delete_files', ['job' => $job->id]));
        self::assertSame('removed', $this->jobs->find($job->id)?->fileState('train'));
        self::assertStringStartsWith('success:', $this->act('delete_record', ['job' => $job->id]));
        self::assertNull($this->jobs->find($job->id));
    }

    public function testUnknownFilesListed(): void
    {
        $dir = $this->plugin->storage()->ensure()->path;
        file_put_contents($dir . '/stray.txt', 'x');
        self::assertStringContainsString('stray.txt', $this->render('jobs'));
        self::assertFileExists($dir . '/stray.txt');
    }

    public function testNonceAndCapabilityChecks(): void
    {
        $_POST = ['_wpnonce' => 'wrong', 'profile' => 'default'];
        $_REQUEST = $_POST;
        try {
            $this->screen->process('run');
            self::fail('Expected nonce failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Nonce', $e->getMessage());
        }
        \WpStub::$currentUser = 3;
        try {
            $this->render('profiles');
            self::fail('Expected wp_die');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('wp_die', $e->getMessage());
        }
        self::assertSame([], $this->jobs->all());
    }

    /** Test 87: preview and count refuse users without the capability. */
    public function testPreviewAndCountNeedCapability(): void
    {
        \WpStub::$currentUser = 3;
        (new RestController($this->plugin))->register();
        foreach (\WpStub::$routes as [, $route, $args]) {
            if ($route === '/preview' || $route === '/jobs') {
                self::assertFalse(($args['permission_callback'])());
            }
        }
        try {
            $this->act('count', ['profile' => 'default']);
            self::fail('Expected wp_die');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('wp_die', $e->getMessage());
        }
        self::assertSame([], $this->jobs->all());
    }

    public function testRestPreview(): void
    {
        $request = new \WP_REST_Request('POST');
        $request->set_body_json(['profile' => ['id' => 'x'], 'limit' => 1]);
        $response = (new RestController($this->plugin))->preview($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        self::assertCount(1, $data['records']);
        self::assertStringContainsString('[EMAIL]', $data['records'][0]['line']);
        self::assertStringContainsString('estimate', $data['estimate_note']);
        \WpStub::$currentUser = 2;
        $request->set_body_json(['profile' => ['id' => 'x', 'redaction' => ['email' => false]]]);
        $error = (new RestController($this->plugin))->preview($request);
        self::assertInstanceOf(\WP_Error::class, $error);
    }

    public function testSettingsSaveAndAcknowledgement(): void
    {
        self::assertStringStartsWith('success:', $this->act('save_settings', ['retention_days' => '7', 'link_ttl_minutes' => '1', 'email_link' => '1', 'dir_ack' => '1']));
        $values = $this->plugin->settings()->all();
        self::assertSame(7, $values['retention_days']);
        self::assertSame(5, $values['link_ttl_minutes']);
        self::assertTrue($values['email_link']);
        self::assertSame($this->exportDir(), $values['dir_ack_path']);
        \WpStub::$currentUser = 2;
        $this->act('save_settings', ['retention_days' => '7']);
        self::assertSame($this->exportDir(), $this->plugin->settings()->acknowledgedPath(), 'non-sensitive users cannot change the acknowledgement');
        self::assertStringContainsString('Retention in days', $this->render('settings'));
    }

    public function testOptOutControls(): void
    {
        $optOut = new OptOut();
        $post = \WpStub::$posts[1];
        ob_start();
        $optOut->classicBox($post);
        $box = (string) ob_get_clean();
        self::assertStringContainsString('Exclude from training exports', $box);
        $_POST = ['finetune_export_optout_nonce' => 'nonce-finetune_export_optout_1', 'finetune_export_optout_present' => '1', 'finetune_export_optout' => '1'];
        $optOut->savePost(1, $post);
        self::assertTrue((bool) get_post_meta(1, Plugin::POST_OPTOUT_META, true));
        $_POST = ['finetune_export_optout_nonce' => 'nonce-finetune_export_optout_1', 'finetune_export_optout_present' => '1'];
        $optOut->savePost(1, $post);
        self::assertSame('', get_post_meta(1, Plugin::POST_OPTOUT_META, true));
        $_POST = ['finetune_export_term_nonce' => 'nonce-finetune_export_term_optout', 'finetune_export_term_optout' => '1'];
        $optOut->saveCategory(5);
        self::assertTrue((bool) get_term_meta(5, Plugin::TERM_OPTOUT_META, true));
        \WpStub::$currentUser = 3;
        ob_start();
        $optOut->classicBox($post);
        self::assertSame('', ob_get_clean());
        $_POST = ['finetune_export_term_nonce' => 'nonce-finetune_export_term_optout'];
        $optOut->saveCategory(5);
        self::assertTrue((bool) get_term_meta(5, Plugin::TERM_OPTOUT_META, true), 'users without the capability cannot change it');
    }

    public function testSiteHealth(): void
    {
        $health = new SiteHealth($this->plugin);
        $tests = $health->register([]);
        self::assertArrayHasKey('finetune_export_directory', $tests['direct']);
        $result = $health->test();
        self::assertSame('good', $result['status']);
        self::assertStringContainsString('Retention is off', $result['description']);
        remove_all_filters('finetune_export_directory');
        add_filter('finetune_export_directory', static fn (): string => WP_CONTENT_DIR . '/uploads/x');
        $this->plugin->resetStorage();
        self::assertSame('critical', $health->test()['status']);
    }

    public function testMultisiteSensitiveForSuperAdminsOnly(): void
    {
        $this->plugin->register();
        \WpStub::$multisite = true;
        self::assertFalse(user_can(1, Plugin::CAP_SENSITIVE));
        self::assertTrue(user_can(1, Plugin::CAP_RUN));
        \WpStub::$superAdmins = [1];
        self::assertTrue(user_can(1, Plugin::CAP_SENSITIVE));
    }

    public function testJobStateForOtherUsersJob(): void
    {
        $this->act('run', ['profile' => 'default']);
        $job = $this->jobs->all()[0];
        self::assertSame(Job::QUEUED, $job->state);
        self::assertNotFalse(wp_next_scheduled(Plugin::CRON_STEP, [$job->id]));
    }
}

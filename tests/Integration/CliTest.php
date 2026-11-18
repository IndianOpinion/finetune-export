<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Integration;

use IndianOpinion\FinetuneExport\Cli\Command;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Tests\TestCase;

final class CliTest extends TestCase
{
    private Command $cli;
    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::addUser(1, 'Admin', [Plugin::CAP_RUN, Plugin::CAP_SENSITIVE]);
        \WpStub::addUser(3, 'Subscriber');
        \WpStub::addPost(['ID' => 1, 'post_title' => 'Secret headline', 'post_content' => '<p>Private body words.</p>']);
        $this->plugin = $this->plugin();
        $this->cli = new Command($this->plugin);
    }

    private function cliOutput(): string
    {
        return implode("\n", \WP_CLI::$lines);
    }

    /** Tests 51 and 86: count labels the figure as an estimate and writes no file. */
    public function testCount(): void
    {
        $this->cli->count([], []);
        self::assertStringContainsString('estimate', $this->cliOutput());
        self::assertStringContainsString('Records 1', $this->cliOutput());
        self::assertDirectoryDoesNotExist($this->exportDir());
    }

    public function testPreviewLabelsEstimate(): void
    {
        $this->cli->preview([], ['limit' => '1']);
        self::assertStringContainsString('estimated tokens (an estimate)', $this->cliOutput());
        self::assertStringContainsString('Redaction is best-effort.', $this->cliOutput());
    }

    /** No command prints file contents: run, jobs show and link print no post text. */
    public function testRunJobsLinkDelete(): void
    {
        $this->cli->run([], ['foreground' => true]);
        $job = $this->jobs->all()[0];
        self::assertSame(Job::COMPLETE, $job->state);
        $this->cli->jobs(['show', (string) $job->id], []);
        $this->cli->jobs(['list'], []);
        self::assertStringContainsString('kept until deleted', $this->cliOutput());
        self::assertStringContainsString('estimate', $this->cliOutput());
        $this->cli->link([(string) $job->id], ['user' => '1', 'ttl' => '10']);
        self::assertMatchesRegularExpression('#/finetune-export/v1/download/[A-Za-z0-9_-]{83}#', $this->cliOutput());
        self::assertStringNotContainsString('Private body', $this->cliOutput());
        self::assertStringNotContainsString('Secret headline', $this->cliOutput());

        try {
            $this->cli->delete([(string) $job->id], []);
            self::fail('Expected a confirmation');
        } catch (\WP_CLI_Exit $e) {
            self::assertSame('present', $this->jobs->find($job->id)?->fileState('train'));
        }
        $this->cli->delete([(string) $job->id], ['yes' => true]);
        self::assertSame('removed', $this->jobs->find($job->id)?->fileState('train'));
    }

    /** `link` refuses --user for a user without finetune_export_run. */
    public function testLinkRefusesUserWithoutCapability(): void
    {
        $this->cli->run([], ['foreground' => true]);
        $job = $this->jobs->all()[0];
        $this->expectException(\WP_CLI_Exit::class);
        $this->expectExceptionMessage('does not have the finetune_export_run capability');
        $this->cli->link([(string) $job->id], ['user' => '3']);
    }

    public function testProfilesImportExport(): void
    {
        $file = $this->root . '/profile.json';
        file_put_contents($file, json_encode(['id' => 'imported', 'shape' => 'text']));
        $this->cli->profiles(['import', $file], []);
        $this->cli->profiles(['list'], []);
        self::assertStringContainsString('id=imported', $this->cliOutput());
        \WP_CLI::$lines = [];
        $this->cli->profiles(['export', 'imported'], []);
        self::assertSame('text', json_decode($this->cliOutput(), true)['shape']);
        \WpStub::$currentUser = 3;
        file_put_contents($file, json_encode(['id' => 'risky', 'redaction' => ['name' => false]]));
        $this->expectException(\WP_CLI_Exit::class);
        $this->cli->profiles(['import', $file], []);
    }

    public function testCheckDirectory(): void
    {
        $this->cli->checkDirectory([], []);
        self::assertStringContainsString('Outside the web root.', $this->cliOutput());
        self::assertDirectoryExists($this->exportDir());
    }

    public function testJobsCancelAndResume(): void
    {
        add_filter('finetune_export_batch_size', static fn (): int => 1);
        \WpStub::addPost(['ID' => 2]);
        $this->cli->run([], []);
        $job = $this->jobs->all()[0];
        self::assertSame(Job::QUEUED, $job->state);
        $this->cli->jobs(['cancel', (string) $job->id], []);
        self::assertSame(Job::CANCELLED, $this->jobs->find($job->id)?->state);
        $this->expectException(\WP_CLI_Exit::class);
        $this->cli->jobs(['resume', (string) $job->id], []);
    }
}

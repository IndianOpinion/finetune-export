<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Integration;

use IndianOpinion\FinetuneExport\Content\WpContentSource;
use IndianOpinion\FinetuneExport\InvalidProfile;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Tests\TestCase;

final class SelectionTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private static function titles(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $out[] = (string) $record['messages'][0]['content'];
        }
        return $out;
    }

    private function seedStatuses(): void
    {
        foreach (['publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit'] as $i => $status) {
            \WpStub::addPost(['ID' => $i + 1, 'post_status' => $status, 'post_title' => 'Post ' . $status, 'post_content' => '<p>Body ' . $status . '</p>']);
        }
    }

    /** Test 1: only published posts by default. */
    public function testDefaultExportsOnlyPublished(): void
    {
        $this->seedStatuses();
        $job = $this->runExport($this->profile());
        self::assertSame(['Write an article with the headline: Post publish'], self::titles($this->lines($job)));
    }

    /** Test 2: only public post types are offered; others are refused. */
    public function testOnlyPublicTypes(): void
    {
        self::assertSame(['post' => 'Posts', 'page' => 'Pages'], WpContentSource::offeredPostTypes());
        $this->expectException(InvalidProfile::class);
        (new WpContentSource())->validate($this->profile(['post_types' => ['attachment']]));
    }

    /** Test 3: other statuses with the setting (capability checked in ProfileTest and AdminTest). */
    public function testOtherStatusesWithSetting(): void
    {
        $this->seedStatuses();
        $job = $this->runExport($this->profile(['allow_unpublished' => true, 'statuses' => ['publish', 'draft', 'private']]));
        self::assertSame(
            ['Write an article with the headline: Post publish', 'Write an article with the headline: Post draft', 'Write an article with the headline: Post private'],
            self::titles($this->lines($job))
        );
        self::assertContains('sensitive:allow_unpublished', $job->notes);
    }

    /** Test 4: password-protected posts. */
    public function testPasswordProtected(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'Open']);
        \WpStub::addPost(['ID' => 2, 'post_title' => 'Locked', 'post_password' => 'pw']);
        self::assertCount(1, $this->lines($this->runExport($this->profile())));
        self::assertCount(2, $this->lines($this->runExport($this->profile(['include_password_protected' => true]))));
    }

    /** Test 5: revisions, autosaves, attachments and trash never appear, even if a filter adds them. */
    public function testNeverSelected(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'Real']);
        \WpStub::addPost(['ID' => 2, 'post_type' => 'revision', 'post_status' => 'inherit']);
        \WpStub::addPost(['ID' => 3, 'post_type' => 'attachment', 'post_status' => 'inherit']);
        \WpStub::addPost(['ID' => 4, 'post_status' => 'trash']);
        \WpStub::addPost(['ID' => 5, 'post_type' => 'revision', 'post_status' => 'inherit', 'post_title' => '1-autosave-v1']);
        add_filter('finetune_export_query_args', static function (array $args): array {
            $args['post__in'] = [1, 2, 3, 4, 5];
            return $args;
        });
        $job = $this->runExport($this->profile());
        self::assertSame(['Write an article with the headline: Real'], self::titles($this->lines($job)));
        self::assertSame(4, $job->skipped['not_selectable']);
    }

    /** Tests 6 and 7: date, term, author and length filters, each with its own skip reason. */
    public function testNarrowingFilters(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_date' => '2026-01-01 00:00:00', 'post_title' => 'Start day']);
        \WpStub::addPost(['ID' => 2, 'post_date' => '2026-01-31 23:59:59', 'post_title' => 'End day']);
        \WpStub::addPost(['ID' => 3, 'post_date' => '2025-12-31 23:59:59', 'post_title' => 'Too early']);
        \WpStub::addPost(['ID' => 4, 'post_date' => '2026-01-10 10:00:00', 'post_title' => 'Excluded cat', 'categories' => [9 => 'Ads']]);
        \WpStub::addPost(['ID' => 5, 'post_date' => '2026-01-10 10:00:00', 'post_title' => 'Bad author', 'post_author' => 7]);
        \WpStub::addPost(['ID' => 6, 'post_date' => '2026-01-10 10:00:00', 'post_title' => 'Short', 'post_content' => '<p>Hi</p>']);
        \WpStub::addPost(['ID' => 7, 'post_date' => '2026-01-10 10:00:00', 'post_title' => 'Long', 'post_content' => '<p>' . str_repeat('word ', 100) . '</p>']);
        \WpStub::addPost(['ID' => 8, 'post_date' => '2026-01-10 10:00:00', 'post_title' => 'Untagged', 'tags' => [4 => 'other']]);
        $profile = $this->profile([
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'exclude_categories' => [9],
            'exclude_authors' => [7],
            'exclude_tags' => [4],
            'min_length' => 5,
            'max_length' => 100,
        ]);
        $job = $this->runExport($profile);
        self::assertSame(['Write an article with the headline: Start day', 'Write an article with the headline: End day'], self::titles($this->lines($job)));
        self::assertSame(['out_of_range' => 1, 'excluded_term' => 2, 'excluded_author' => 1, 'too_short' => 1, 'too_long' => 1], $job->skipped);
    }

    public function testIncludeLists(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'In', 'categories' => [3 => 'News'], 'post_author' => 2]);
        \WpStub::addPost(['ID' => 2, 'post_title' => 'Wrong cat', 'categories' => [4 => 'Other'], 'post_author' => 2]);
        \WpStub::addPost(['ID' => 3, 'post_title' => 'Wrong author', 'categories' => [3 => 'News'], 'post_author' => 5]);
        $job = $this->runExport($this->profile(['include_categories' => [3], 'include_authors' => [2]]));
        self::assertSame(['Write an article with the headline: In'], self::titles($this->lines($job)));
        self::assertSame(['excluded_term' => 1, 'excluded_author' => 1], $job->skipped);
    }

    /** Test 8: the query-args filter can add and remove posts. */
    public function testQueryArgsFilter(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'A']);
        \WpStub::addPost(['ID' => 2, 'post_title' => 'B']);
        \WpStub::addPost(['ID' => 3, 'post_title' => 'C', 'post_type' => 'page']);
        add_filter('finetune_export_query_args', static function (array $args): array {
            $args['post__not_in'] = [1];
            $args['post_type'][] = 'page';
            return $args;
        });
        $job = $this->runExport($this->profile(['post_types' => ['post', 'page']]));
        self::assertSame(['Write an article with the headline: B', 'Write an article with the headline: C'], self::titles($this->lines($job)));
    }

    /** Test 9: ascending id order, also across batches. */
    public function testAscendingOrderAcrossBatches(): void
    {
        foreach ([5, 3, 9, 1, 7] as $id) {
            \WpStub::addPost(['ID' => $id, 'post_title' => 'P' . $id]);
        }
        add_filter('finetune_export_batch_size', static fn (): int => 2);
        $job = $this->runExport($this->profile());
        self::assertSame(
            array_map(static fn (int $id): string => 'Write an article with the headline: P' . $id, [1, 3, 5, 7, 9]),
            self::titles($this->lines($job))
        );
        self::assertSame(9, $job->lastPostId);
    }

    public function testPostsWhereFilterAddsIdBound(): void
    {
        $plugin = new Plugin($this->clock, $this->jobs);
        $query = new \WP_Query([Plugin::QUERY_AFTER_ID => 41]);
        self::assertSame(' AND x AND wp_posts.ID > 41', $plugin->filterPostsWhere(' AND x', $query));
        self::assertSame(' AND x', $plugin->filterPostsWhere(' AND x', new \WP_Query([])));
    }

    /** Test 10 and ruling 6: per-post and per-category opt-out. */
    public function testOptOut(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'Keep']);
        \WpStub::addPost(['ID' => 2, 'post_title' => 'Opted', 'meta' => [Plugin::POST_OPTOUT_META => '1']]);
        \WpStub::addPost(['ID' => 3, 'post_title' => 'In opted category', 'categories' => [5 => 'Private letters']]);
        \WpStub::$termMeta[5][Plugin::TERM_OPTOUT_META] = '1';
        // Selected by id through the filter: the opt-out still holds (section 8).
        add_filter('finetune_export_query_args', static function (array $args): array {
            $args['post__in'] = [1, 2, 3];
            return $args;
        });
        $job = $this->runExport($this->profile());
        self::assertSame(['Write an article with the headline: Keep'], self::titles($this->lines($job)));
        self::assertSame(['opted_out' => 1, 'opted_out_category' => 1], $job->skipped);
    }

    /** Test 11: a limit of 3 yields exactly 3 records. */
    public function testLimit(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            \WpStub::addPost(['ID' => $i]);
        }
        add_filter('finetune_export_batch_size', static fn (): int => 2);
        $job = $this->runExport($this->profile(['limit' => 3]));
        self::assertCount(3, $this->lines($job));
        self::assertSame(3, $job->counter('written'));
    }

    /** Test 12: identical cleaned bodies yield one record and one duplicate skip. */
    public function testDuplicates(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'First', 'post_content' => '<p>Same   text.</p>']);
        \WpStub::addPost(['ID' => 2, 'post_title' => 'Second', 'post_content' => '<!-- wp:paragraph --><p>Same text.</p><!-- /wp:paragraph -->']);
        \WpStub::addPost(['ID' => 3, 'post_title' => 'Third', 'post_content' => '<p>Other text.</p>']);
        $job = $this->runExport($this->profile());
        self::assertSame(['Write an article with the headline: First', 'Write an article with the headline: Third'], self::titles($this->lines($job)));
        self::assertSame(['duplicate' => 1], $job->skipped);
    }
}

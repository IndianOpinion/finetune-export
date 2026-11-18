<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Integration;

use IndianOpinion\FinetuneExport\Estimate\HeuristicEstimator;
use IndianOpinion\FinetuneExport\Estimate\TokenEstimator;
use IndianOpinion\FinetuneExport\Pipeline;
use IndianOpinion\FinetuneExport\Redaction\RedactionContext;
use IndianOpinion\FinetuneExport\Redaction\RedactionResult;
use IndianOpinion\FinetuneExport\Redaction\RedactionRule;
use IndianOpinion\FinetuneExport\Tests\TestCase;

final class RecordTest extends TestCase
{
    /** Tests 13 and 14 (record side): default fields; every field selectable. */
    public function testDefaultAndAllFields(): void
    {
        \WpStub::addUser(3, 'Zed Writer');
        \WpStub::addPost([
            'ID' => 1, 'post_title' => 'Rain &amp; sun', 'post_excerpt' => 'Short summary', 'post_author' => 3,
            'post_content' => '<p>Body text.</p>', 'categories' => [2 => 'Weather'], 'tags' => [8 => 'monsoon', 9 => 'heat'],
            'meta' => ['_thumbnail_id' => 50, 'subtitle' => 'A subtitle'],
        ]);
        \WpStub::$postMeta[50]['_wp_attachment_image_alt'] = ['Clouds over a city'];

        $default = $this->pipeline($this->profile())->process(1);
        self::assertSame(['messages' => [
            ['role' => 'user', 'content' => 'Write an article with the headline: Rain & sun'],
            ['role' => 'assistant', 'content' => 'Body text.'],
        ]], $default->record);

        $all = $this->profile([
            'fields' => ['id', 'title', 'excerpt', 'body', 'date', 'modified', 'permalink', 'categories', 'tags', 'author', 'image_alt'],
            'meta_keys' => ['subtitle'],
            'shape' => 'text',
            'redaction' => ['name' => false],
            'templates' => ['text' => "{id}|{title}|{excerpt}|{body}|{date}|{modified}|{permalink}|{categories}|{tags}|{author}|{image_alt}|{meta:subtitle}"],
        ]);
        $record = $this->pipeline($all)->process(1)->record;
        self::assertSame(
            ['text' => '1|Rain & sun|Short summary|Body text.|2026-01-15T10:00:00+00:00|2026-01-16T10:00:00+00:00|https://example.org/|Weather|monsoon, heat|Zed Writer|Clouds over a city|A subtitle'],
            $record
        );
    }

    /** Test 14 (pipeline side): with author and name redaction on, the author field reads [NAME]. */
    public function testAuthorIsRedacted(): void
    {
        \WpStub::addUser(3, 'Zed Writer');
        \WpStub::addPost(['ID' => 1, 'post_author' => 3]);
        $profile = $this->profile(['fields' => ['title', 'body', 'author'], 'templates' => ['user' => 'By {author}']]);
        self::assertTrue($profile->authorWillBeRedacted());
        self::assertSame('By [NAME]', $this->pipeline($profile)->process(1)->record['messages'][0]['content']);
    }

    /** Test 15 (pipeline side): array meta values are skipped and counted. */
    public function testNonScalarMeta(): void
    {
        \WpStub::addPost(['ID' => 1]);
        \WpStub::$postMeta[1]['lines'] = [['a', 'b']];
        $profile = $this->profile(['meta_keys' => ['lines'], 'templates' => ['user' => '{title} {meta:lines}']]);
        $job = $this->runExport($profile);
        self::assertSame(1, $job->counter('meta_not_scalar'));
        self::assertSame('Title 1', $this->lines($job)[0]['messages'][0]['content']);
    }

    /** Test 16: empty fields are omitted unless "keep empty fields" is on. */
    public function testEmptyFields(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'T']);
        $templates = ['user' => "Title: {title}\nSummary: {excerpt}\nTags: {tags}"];
        $omit = $this->pipeline($this->profile(['fields' => ['title', 'body', 'excerpt', 'tags'], 'templates' => $templates]))->process(1);
        self::assertSame('Title: T', $omit->record['messages'][0]['content']);
        $keep = $this->pipeline($this->profile(['fields' => ['title', 'body', 'excerpt', 'tags'], 'templates' => $templates, 'keep_empty_fields' => true]))->process(1);
        self::assertSame("Title: T\nSummary: \nTags:", $keep->record['messages'][0]['content']);
    }

    /** Test 17: names in the title and a meta value are redacted; dates are not. */
    public function testRedactionAcrossFields(): void
    {
        \WpStub::addUser(2, 'Asha Rao');
        \WpStub::addPost(['ID' => 1, 'post_title' => 'Asha Rao wins', 'post_date' => '2026-03-04 05:06:07', 'meta' => ['quote_by' => 'Interview with Asha Rao']]);
        $profile = $this->profile([
            'fields' => ['title', 'body', 'date'],
            'meta_keys' => ['quote_by'],
            'templates' => ['user' => "{title}\n{meta:quote_by}\n{date}"],
        ]);
        self::assertSame("[NAME] wins\nInterview with [NAME]\n2026-03-04T05:06:07+00:00", $this->pipeline($profile)->process(1)->record['messages'][0]['content']);
    }

    /** Test 18: a the_content filter has no effect by default and applies with the option. */
    public function testContentFilters(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_content' => '<p>Story.</p>']);
        add_filter('the_content', static fn (string $c): string => $c . '<div class="share">Share this</div>');
        self::assertSame('Story.', self::assistant($this->pipeline($this->profile())->process(1)->record));
        self::assertSame("Story.\n\nShare this", self::assistant($this->pipeline($this->profile(['apply_content_filters' => true]))->process(1)->record));
    }

    public function testDynamicBlocksRenderedOnlyWithOption(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_content' => '<p>Story.</p><!-- wp:latest-posts /-->']);
        self::assertSame('Story.', self::assistant($this->pipeline($this->profile())->process(1)->record));
        self::assertSame("Story.\n\nRendered latest-posts", self::assistant($this->pipeline($this->profile(['render_dynamic_blocks' => true]))->process(1)->record));
    }

    /** Test 24 (pipeline side): a body over 5 percent U+FFFD is skipped. */
    public function testBadEncodingSkipped(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_content' => "<p>\xFF\xFE\xFD ok</p>"]);
        \WpStub::addPost(['ID' => 2, 'post_content' => '<p>' . str_repeat('fine ', 40) . "\xFF</p>"]);
        $job = $this->runExport($this->profile());
        self::assertSame(['bad_encoding' => 1], $job->skipped);
        self::assertStringContainsString("\u{FFFD}", self::assistant($this->lines($job)[0]));
    }

    /** Tests 26 and 27: shapes and default templates. */
    public function testShapes(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'Headline', 'post_content' => '<p>Body.</p>']);
        self::assertSame(
            ['messages' => [['role' => 'user', 'content' => 'Write an article with the headline: Headline'], ['role' => 'assistant', 'content' => 'Body.']]],
            $this->pipeline($this->profile())->process(1)->record
        );
        self::assertSame(
            ['messages' => [['role' => 'system', 'content' => 'You write news.'], ['role' => 'user', 'content' => 'Write an article with the headline: Headline'], ['role' => 'assistant', 'content' => 'Body.']]],
            $this->pipeline($this->profile(['templates' => ['system' => 'You write news.']]))->process(1)->record
        );
        self::assertSame(
            ['prompt' => 'Write an article with the headline: Headline', 'completion' => 'Body.'],
            $this->pipeline($this->profile(['shape' => 'prompt_completion']))->process(1)->record
        );
        self::assertSame(['text' => "Headline\n\nBody."], $this->pipeline($this->profile(['shape' => 'text']))->process(1)->record);
    }

    /** Test 29: an empty body under the default template is skipped as empty_target. */
    public function testEmptyTarget(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_content' => '<p> </p><script>x</script>']);
        self::assertSame('empty_target', $this->pipeline($this->profile())->process(1)->skip);
    }

    /** Test 30: metadata only when enabled, with the permalink cut. */
    public function testMetadata(): void
    {
        \WpStub::addPost(['ID' => 1]);
        self::assertArrayNotHasKey('metadata', (array) $this->pipeline($this->profile())->process(1)->record);
        add_filter('finetune_export_fields', static function (array $fields): array {
            $fields['permalink'] = 'https://example.org/post/?utm_source=mail&email=a@b.co';
            return $fields;
        });
        $record = $this->pipeline($this->profile(['include_metadata' => true]))->process(1)->record;
        self::assertSame(['post_id' => 1, 'permalink' => 'https://example.org/post/', 'date' => '2026-01-15T10:00:00+00:00'], $record['metadata']);
    }

    /** Test 31: one JSON object per line, unescaped Unicode, \n endings; an unencodable record is skipped. */
    public function testEncoding(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'हिन्दी / 日本', 'post_content' => '<p>नमस्ते https://example.org/a/b</p>']);
        \WpStub::addPost(['ID' => 2, 'post_title' => 'Broken']);
        add_filter('finetune_export_record', static function (array $record, $post): array {
            if ($post->id === 2) {
                $record['bad'] = "\xB1\x31";
            }
            return $record;
        }, 10, 2);
        $exporter = $this->exporter();
        $job = $this->runExport($this->profile(), $exporter);
        $raw = (string) file_get_contents($this->storage()->path((string) $job->fileName('train')));
        self::assertStringEndsWith("}\n", $raw);
        self::assertStringNotContainsString("\r", $raw);
        self::assertSame(1, substr_count($raw, "\n"));
        self::assertStringContainsString('हिन्दी / 日本', $raw);
        self::assertStringContainsString('https://example.org/a/b', $raw);
        self::assertStringNotContainsString('\\u', $raw);
        self::assertSame(['encode_error' => 1], $job->skipped);
    }

    /** Test 32: the record filter reshapes or skips. */
    public function testRecordFilter(): void
    {
        \WpStub::addPost(['ID' => 1]);
        \WpStub::addPost(['ID' => 2]);
        add_filter('finetune_export_record', static function (array $record, $post): ?array {
            return $post->id === 2 ? null : ['input' => 'x', 'output' => self::assistant($record)];
        }, 10, 2);
        $job = $this->runExport($this->profile());
        self::assertSame([['input' => 'x', 'output' => 'Body of post 1.']], $this->lines($job));
        self::assertSame(['filtered' => 1], $job->skipped);
    }

    /** Test 38 (pipeline side): user names and the name-source filter. */
    public function testNameSourcesFromSite(): void
    {
        \WpStub::addUser(4, 'Kiran Desai', [], 'Kirandesai', ['first_name' => 'Kiran', 'last_name' => 'Desai', 'nickname' => 'Kiddo']);
        \WpStub::addPost(['ID' => 1, 'post_content' => '<p>Today Kiran Desai, Kirandesai and Kiddo met Meena Kumari.</p>']);
        add_filter('finetune_export_names', static fn (array $names): array => array_merge($names, ['Meena Kumari']));
        self::assertSame('Today [NAME], [NAME] and [NAME] met [NAME].', self::assistant($this->pipeline($this->profile())->process(1)->record));
        $usersOff = $this->profile(['name_sources' => ['users' => false]]);
        self::assertSame('Today Kiran Desai, Kirandesai and Kiddo met [NAME].', self::assistant($this->pipeline($usersOff)->process(1)->record));
    }

    /** Test 43: redaction applies to the filled template. */
    public function testRedactionOnFilledTemplate(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'Reach me at a@b.co']);
        $profile = $this->profile(['templates' => ['user' => 'Title: {title}. Contact desk@paper.example for more.']]);
        self::assertSame('Title: Reach me at [EMAIL]. Contact [EMAIL] for more.', $this->pipeline($profile)->process(1)->record['messages'][0]['content']);
    }

    /** Tests 46 and 47 (job side): report counts without removed text; a throwing rule degrades the job. */
    public function testReportAndDegradation(): void
    {
        \WpStub::addUser(2, 'Asha Rao');
        \WpStub::addPost(['ID' => 1, 'post_content' => '<p>Asha Rao at a@b.co or +91 98765 43210.</p>']);
        \WpStub::addPost(['ID' => 2, 'post_content' => '<p>Mail c@d.co.</p>']);
        add_filter('finetune_export_redaction_rules', static function (array $rules): array {
            $rules[] = new class implements RedactionRule {
                public function type(): string
                {
                    return 'custom';
                }

                public function apply(string $text, RedactionContext $context): RedactionResult
                {
                    throw new \RuntimeException('custom rule broke');
                }
            };
            return $rules;
        });
        $job = $this->runExport($this->profile());
        self::assertSame(['email' => 2, 'phone' => 1, 'name' => 1], $job->redaction);
        self::assertSame([1 => 3, 2 => 1], $job->topRedacted);
        self::assertTrue($job->degraded());
        self::assertContains('rule_disabled:custom', $job->notes);
        $stored = json_encode($job->toArray());
        self::assertStringNotContainsString('Asha', (string) $stored);
        self::assertStringNotContainsString('a@b.co', (string) $stored);
        self::assertSame('[NAME] at [EMAIL] or [PHONE].', self::assistant($this->lines($job)[0]));
        $errors = array_filter($this->logs, static fn (array $l): bool => $l[0] === 'error' && str_contains($l[1], 'redaction rule disabled'));
        self::assertCount(1, $errors);
    }

    /** Test 45 (job side): a type turned off is recorded as a warning on the job. */
    public function testRedactionOffRecorded(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_content' => '<p>Call 98765 43210.</p>']);
        $job = $this->runExport($this->profile(['redaction' => ['phone' => false]]));
        self::assertContains('redaction_off:phone', $job->notes);
        self::assertSame('Call 98765 43210.', self::assistant($this->lines($job)[0]));
    }

    /** Test 48: a regex limit skips the record; nothing unredacted is written. */
    public function testRegexLimitSkipsRecord(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_content' => '<p>trap ' . str_repeat('a.', 400) . '@</p>']);
        \WpStub::addPost(['ID' => 2, 'post_content' => '<p>fine</p>']);
        $backtrack = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '50');
        try {
            $job = $this->runExport($this->profile());
        } finally {
            ini_set('pcre.backtrack_limit', (string) $backtrack);
            ini_set('pcre.jit', (string) $jit);
        }
        self::assertSame(['redaction_error' => 1], $job->skipped);
        self::assertCount(1, $this->lines($job));
        self::assertStringNotContainsString('trap', (string) file_get_contents($this->storage()->path((string) $job->fileName('train'))));
    }

    /** Test 54: over-limit records skip by default; truncate cuts at a paragraph break. */
    public function testTokenLimit(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'T', 'post_content' => '<p>' . str_repeat('word ', 20) . '</p><p>' . str_repeat('more ', 200) . '</p>']);
        $skip = $this->runExport($this->profile(['max_tokens' => 60]));
        self::assertSame(['over_token_limit' => 1], $skip->skipped);
        $cut = $this->runExport($this->profile(['max_tokens' => 60, 'over_limit' => 'truncate']));
        self::assertSame(1, $cut->counter('truncated'));
        self::assertSame(trim(str_repeat('word ', 20)), self::assistant($this->lines($cut)[0]));
        self::assertLessThanOrEqual(60, Pipeline::target($this->lines($cut)[0], 'chat') !== null ? (new HeuristicEstimator())->estimate($this->lines($cut)[0], 'chat') : 0);
    }

    /** Test 55: the estimate of a redacted record equals the estimate of its written line. */
    public function testEstimateMatchesWrittenLine(): void
    {
        \WpStub::addUser(2, 'Asha Rao');
        \WpStub::addPost(['ID' => 1, 'post_content' => '<p>Asha Rao wrote from a@b.co in नमस्ते.</p>']);
        $outcome = $this->pipeline($this->profile())->process(1);
        $decoded = json_decode((string) $outcome->line, true);
        self::assertSame((new HeuristicEstimator())->estimate($decoded, 'chat'), $outcome->tokens);
        $job = $this->runExport($this->profile());
        self::assertSame([$outcome->tokens => 1], $job->stats);
    }

    /** Test 57: a custom estimator is used; a throwing one falls back and logs once. */
    public function testEstimatorFilter(): void
    {
        \WpStub::addPost(['ID' => 1]);
        \WpStub::addPost(['ID' => 2]);
        add_filter('finetune_export_token_estimator', static fn (): TokenEstimator => new class implements TokenEstimator {
            public function estimate(array $record, string $shape): int
            {
                return 42;
            }
        });
        self::assertSame([42 => 2], $this->runExport($this->profile())->stats);

        remove_all_filters('finetune_export_token_estimator');
        add_filter('finetune_export_token_estimator', static fn (): TokenEstimator => new class implements TokenEstimator {
            public function estimate(array $record, string $shape): int
            {
                throw new \RuntimeException('no tokeniser');
            }
        });
        $job = $this->runExport($this->profile());
        self::assertContains('estimator_fallback', $job->notes);
        self::assertSame(2, array_sum($job->stats));
        $warnings = array_filter($this->logs, static fn (array $l): bool => str_contains($l[1], 'token estimator failed'));
        self::assertCount(1, $warnings);
    }

    /** Test 22 (job side): the NFC note is recorded once when intl is missing. */
    public function testNfcNoteOnce(): void
    {
        \WpStub::addPost(['ID' => 1]);
        \WpStub::addPost(['ID' => 2]);
        add_filter('finetune_export_batch_size', static fn (): int => 1);
        $exporter = $this->exporter(null, null, new \IndianOpinion\FinetuneExport\Cleaner(false));
        $job = $this->runExport($this->profile(), $exporter);
        self::assertSame(1, count(array_keys($job->notes, 'nfc_unavailable', true)));
    }
}

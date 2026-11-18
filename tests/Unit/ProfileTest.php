<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Unit;

use IndianOpinion\FinetuneExport\InvalidProfile;
use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Profiles;
use IndianOpinion\FinetuneExport\PermissionDenied;
use IndianOpinion\FinetuneExport\Tests\TestCase;

final class ProfileTest extends TestCase
{
    public function testDefaultsMatchSectionSeven(): void
    {
        $profile = Profile::fromArray([]);
        self::assertSame(['post'], $profile->list('post_types'));
        self::assertSame(['publish'], $profile->list('statuses'));
        self::assertFalse($profile->bool('include_password_protected'));
        self::assertSame(['title', 'body'], $profile->fields());
        self::assertSame('text', $profile->format());
        self::assertSame('chat', $profile->shape());
        self::assertFalse($profile->bool('include_metadata'));
        foreach (Profile::REDACTION_TYPES as $type) {
            self::assertTrue($profile->redactionOn($type), $type);
        }
        self::assertFalse($profile->bool('pseudonyms'));
        self::assertNull($profile->optionalInt('max_tokens'));
        self::assertSame('skip', $profile->get('over_limit'));
        self::assertSame(0, $profile->get('validation_percent'));
        self::assertFalse($profile->bool('compress'));
        self::assertSame([], $profile->sensitiveChoices());
    }

    /** Test 3: other statuses need the setting, and the setting needs the sensitive capability. */
    public function testUnpublishedStatusesNeedTheSetting(): void
    {
        try {
            Profile::fromArray(['statuses' => ['publish', 'draft']]);
            self::fail('Expected InvalidProfile');
        } catch (InvalidProfile $e) {
            self::assertSame('statuses', $e->field);
        }
        $profile = Profile::fromArray(['statuses' => ['publish', 'draft'], 'allow_unpublished' => '1']);
        self::assertContains('allow_unpublished', $profile->sensitiveChoices());
        $this->expectException(PermissionDenied::class);
        Profiles::assertAllowed($profile, false);
    }

    public function testSensitiveProfileAcceptedWithCapability(): void
    {
        $profile = Profile::fromArray(['statuses' => ['draft'], 'allow_unpublished' => true, 'include_password_protected' => true]);
        Profiles::assertAllowed($profile, true);
        self::assertSame(['allow_unpublished', 'include_password_protected'], $profile->sensitiveChoices());
    }

    /** Test 15: underscore meta keys are refused at save. */
    public function testUnderscoreMetaKeyRefused(): void
    {
        $this->expectException(InvalidProfile::class);
        $this->expectExceptionMessage('_secret');
        Profile::fromArray(['meta_keys' => "subtitle\n_secret"]);
    }

    /** Test 28: a placeholder for an unselected field fails and names it. */
    public function testPlaceholderForUnselectedFieldNamesIt(): void
    {
        try {
            Profile::fromArray(['templates' => ['user' => 'By {author}: {title}']]);
            self::fail('Expected InvalidProfile');
        } catch (InvalidProfile $e) {
            self::assertSame('templates.user', $e->field);
            self::assertStringContainsString('{author}', $e->getMessage());
        }
        $ok = Profile::fromArray(['fields' => ['title', 'body', 'author'], 'templates' => ['user' => 'By {author}: {title}']]);
        self::assertSame('By {author}: {title}', $ok->activeTemplates()['user']);
    }

    public function testUnknownPlaceholderAndMetaPlaceholder(): void
    {
        try {
            Profile::fromArray(['templates' => ['assistant' => '{bodyy}']]);
            self::fail('Expected InvalidProfile');
        } catch (InvalidProfile $e) {
            self::assertStringContainsString('{bodyy}', $e->getMessage());
        }
        try {
            Profile::fromArray(['templates' => ['assistant' => '{meta:subtitle}']]);
            self::fail('Expected InvalidProfile');
        } catch (InvalidProfile $e) {
            self::assertStringContainsString('{meta:subtitle}', $e->getMessage());
        }
        $ok = Profile::fromArray(['meta_keys' => ['subtitle'], 'templates' => ['assistant' => '{meta:subtitle} {body}']]);
        self::assertSame(['subtitle'], $ok->metaKeys());
    }

    /** Test 50 (profile side): turning a type off is a sensitive choice. */
    public function testRedactionOffIsSensitive(): void
    {
        $profile = Profile::fromArray(['redaction' => ['phone' => '0']]);
        self::assertSame(['phone'], $profile->redactionOff());
        self::assertSame(['redaction_off:phone'], $profile->sensitiveChoices());
    }

    public function testValidationOfNumbersDatesAndShapes(): void
    {
        foreach (
            [
                ['date_from' => '2026-02-30'],
                ['date_from' => '2026-03-01', 'date_to' => '2026-02-01'],
                ['min_length' => '10', 'max_length' => '5'],
                ['limit' => '0'],
                ['validation_percent' => '60'],
                ['shape' => 'jsonl'],
                ['format' => 'pdf'],
                ['id' => 'Bad Id'],
                ['fields' => ['title', 'body', 'password']],
                ['templates' => ['assistant' => '']],
            ] as $bad
        ) {
            try {
                Profile::fromArray($bad);
                self::fail('Expected InvalidProfile for ' . json_encode($bad));
            } catch (InvalidProfile $e) {
                self::assertNotSame('', $e->field);
            }
        }
    }

    public function testRoundTrip(): void
    {
        $profile = Profile::fromArray(['id' => 'news', 'name' => 'News', 'shape' => 'text', 'operator_names' => "Asha Rao\nVikram"]);
        $again = Profile::fromArray($profile->toArray());
        self::assertSame($profile->toArray(), $again->toArray());
        self::assertSame(['Asha Rao', 'Vikram'], $again->list('operator_names'));
    }
}

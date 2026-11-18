<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Tests\Unit;

use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Redaction\AddressRule;
use IndianOpinion\FinetuneExport\Redaction\EmailRule;
use IndianOpinion\FinetuneExport\Redaction\IpRule;
use IndianOpinion\FinetuneExport\Redaction\NameRule;
use IndianOpinion\FinetuneExport\Redaction\PhoneRule;
use IndianOpinion\FinetuneExport\Redaction\RedactionContext;
use IndianOpinion\FinetuneExport\Redaction\RedactionResult;
use IndianOpinion\FinetuneExport\Redaction\RedactionRule;
use IndianOpinion\FinetuneExport\Redaction\Redactor;
use IndianOpinion\FinetuneExport\Redaction\UrlRule;
use IndianOpinion\FinetuneExport\RegexLimitExceeded;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedactionTest extends TestCase
{
    /**
     * @param list<RedactionRule> $rules
     */
    private function redact(string $text, array $rules, bool $pseudonyms = false, array $placeholders = []): RedactionResult
    {
        $context = new RedactionContext(array_merge(Profile::DEFAULT_PLACEHOLDERS, $placeholders), $pseudonyms);
        return (new Redactor($rules))->redact($text, $context);
    }

    /**
     * @return list<RedactionRule>
     */
    private function all(array $names = [], array $operator = [], array $allow = []): array
    {
        return [
            new UrlRule(), new EmailRule(), new IpRule(), new PhoneRule(), new AddressRule(),
            new NameRule($names, $operator, NameRule::HONORIFICS, NameRule::STOPLIST, $allow),
        ];
    }

    /** Test 33. */
    #[DataProvider('emails')]
    public function testEmails(string $input): void
    {
        $result = $this->redact('Write to ' . $input . ' today.', [new EmailRule()]);
        self::assertSame('Write to [EMAIL] today.', $result->text);
        self::assertSame(['email' => 1], $result->counts);
    }

    /** @return array<string, array{string}> */
    public static function emails(): array
    {
        return [
            'plain' => ['asha.rao+news@example.co.uk'],
            'word form' => ['asha at example dot com'],
            'bracket form' => ['asha [at] example [dot] com'],
            'paren form' => ['asha(at)example.com'],
            'upper case words' => ['asha AT example DOT org'],
        ];
    }

    public function testEmailRuleLeavesOrdinaryAt(): void
    {
        $text = 'Look at example.com and meet at noon.';
        self::assertSame($text, $this->redact($text, [new EmailRule()])->text);
    }

    /** Test 34. */
    #[DataProvider('phones')]
    public function testPhones(string $input): void
    {
        self::assertSame('Call [PHONE] now.', $this->redact('Call ' . $input . ' now.', [new PhoneRule()])->text);
    }

    /** @return array<string, array{string}> */
    public static function phones(): array
    {
        return [
            'international spaced' => ['+91 98765 43210'],
            'international uk' => ['+44 (0)20 7946 0958'],
            'international hyphen' => ['+1-202-555-0143'],
            'national brackets' => ['(020) 7946 0958'],
            'national hyphen' => ['98765-43210'],
            'national dots' => ['020.7946.0958'],
            'national plain' => ['9876543210'],
        ];
    }

    #[DataProvider('notPhones')]
    public function testNotPhones(string $text): void
    {
        self::assertSame($text, $this->redact($text, [new PhoneRule()])->text);
    }

    /** @return array<string, array{string}> */
    public static function notPhones(): array
    {
        return [
            'years' => ['Between 1998 and 2024 2025 2026 sales grew.'],
            'year range' => ['The 2019-2020 season.'],
            'date' => ['On 2024-01-15 and 15.01.2024 it rained.'],
            'time' => ['At 10:30 or 22:15:00.'],
            'money' => ['It cost Rs 1234567890 and $9876543210 in total.'],
            'rupee sign' => ['Budget ₹ 4500000000 approved.'],
            'percentage' => ['Growth of 1234567890% is absurd.'],
            'isbn' => ['See ISBN 0-306-40615-2 and ISBN 978-3-16-148410-0.'],
            'doi' => ['doi:10.1000/182 and https://doi.org/10.1038/nphys1170'],
            'short number' => ['Room 12345 and pin 110024.'],
        ];
    }

    /** Test 35. */
    public function testUrls(): void
    {
        $text = 'Mail <a href="mailto:asha@example.com">me</a>, call tel:+919876543210, see https://user:pw@example.org/path/page?email=a@b.com&id=7#frag. Or www.example.org/x?ref=abc.';
        $result = $this->redact($text, [new UrlRule(), new EmailRule()]);
        self::assertSame(
            'Mail <a href="[EMAIL]">me</a>, call [PHONE], see https://example.org/path/page. Or www.example.org/x.',
            $result->text
        );
        self::assertSame(['email' => 1, 'phone' => 1, 'url' => 2], $result->counts);
        self::assertSame('https://example.org/2026/01/post/', UrlRule::cut('https://example.org/2026/01/post/?utm_source=x'));
    }

    /** Test 36. */
    public function testAddressesAndPostcodes(): void
    {
        $rules = [new AddressRule()];
        self::assertSame('Visit [ADDRESS] for tea.', $this->redact('Visit 221B Baker Street, London NW1 6XE for tea.', $rules)->text);
        self::assertSame('Office: [ADDRESS].', $this->redact('Office: 14 Lajpat Nagar, New Delhi 110024.', $rules)->text);
        self::assertSame('At [ADDRESS] today.', $this->redact('At 12 MG Road today.', $rules)->text);
        self::assertSame('Post to [POSTCODE] please.', $this->redact('Post to SW1A 1AA please.', $rules)->text);
        self::assertSame('PIN [POSTCODE] and Pincode: [POSTCODE].', $this->redact('PIN 110024 and Pincode: 400 001.', $rules)->text);
        self::assertSame('Albany, NY [POSTCODE] office.', $this->redact('Albany, NY 12207-1234 office.', $rules)->text);
        $bare = 'Population 110024 and 54321 voters, code 400001.';
        self::assertSame($bare, $this->redact($bare, $rules)->text);
        $counts = $this->redact('221B Baker Street is far. SW1A 1AA.', $rules)->counts;
        self::assertSame(['address' => 1, 'postcode' => 1], $counts);
    }

    public function testStreetTypesAreConfigurable(): void
    {
        $text = 'Meet at 5 Gandhi Maidan.';
        self::assertSame($text, $this->redact($text, [new AddressRule()])->text);
        self::assertSame('Meet at [ADDRESS].', $this->redact($text, [new AddressRule(['Maidan'])])->text);
    }

    /** Test 37. */
    public function testIpAddresses(): void
    {
        $result = $this->redact('From 192.168.1.10, 10.0.0.1. and 2001:db8::8a2e:370:7334 or ::1 at 10:30:00 on v1.2.3.', [new IpRule()]);
        self::assertSame('From [IP], [IP]. and [IP] or [IP] at 10:30:00 on v1.2.3.', $result->text);
        self::assertSame(['ip' => 4], $result->counts);
    }

    /** Test 38 (rule level): each name source. */
    public function testNameSources(): void
    {
        $listed = new NameRule(['Asha Rao'], [], []);
        self::assertSame('Met [NAME] there.', $this->redact('Met Asha Rao there.', [$listed])->text);
        $operator = new NameRule([], ['Kavya'], []);
        self::assertSame('Met [NAME] there.', $this->redact('Met Kavya there.', [$operator])->text);
        $honorific = new NameRule([], [], NameRule::HONORIFICS);
        self::assertSame('Met Dr [NAME] and Shri [NAME].', $this->redact('Met Dr Meera Iyer and Shri Ram Prasad Sharma.', [$honorific])->text);
        self::assertSame('Met Mr. [NAME] today.', $this->redact('Met Mr. Smith today.', [$honorific])->text);
        $none = new NameRule([], [], []);
        self::assertSame('Met Asha Rao there.', $this->redact('Met Asha Rao there.', [$none])->text);
    }

    /** Test 39: whole phrases, longest first, one placeholder. */
    public function testLongestPhraseWins(): void
    {
        $rule = new NameRule(['Asha', 'Asha Rao', 'Asha Rao Iyer'], [], []);
        $result = $this->redact('Then Asha Rao Iyer spoke, and later Asha Rao.', [$rule]);
        self::assertSame('Then [NAME] spoke, and later [NAME].', $result->text);
        self::assertSame(['name' => 2], $result->counts);
        // Case-sensitive, whole words only.
        self::assertSame('Ashara and asha rao.', $this->redact('Ashara and asha rao.', [$rule])->text);
    }

    /** Test 40: single-word limits. */
    public function testSingleWordLimits(): void
    {
        $rule = new NameRule(['Vikram', 'Ram', 'Grace', 'meera'], ['Om'], []);
        self::assertSame(
            'Vikram arrived. Then [NAME] left with Ram, Grace and meera. [NAME] stayed.',
            $this->redact('Vikram arrived. Then Vikram left with Ram, Grace and meera. Om stayed.', [$rule])->text
        );
        self::assertSame("- Vikram\n- [NAME]", $this->redact("- Vikram\n- Om", [$rule])->text);
        self::assertTrue(NameRule::sentenceInitial('He said. "'));
        self::assertFalse(NameRule::sentenceInitial('He met'));
    }

    /** Test 41: an allowlisted phrase survives even inside a longer match. */
    public function testAllowlist(): void
    {
        $rule = new NameRule(['Mohandas Gandhi', 'Gandhi Road Trust'], [], NameRule::HONORIFICS, NameRule::STOPLIST, ['Gandhi Road', 'Mahatma Gandhi']);
        self::assertSame(
            'Visit Gandhi Road Trust office; Shri Mahatma Gandhi spoke; [NAME] too.',
            $this->redact('Visit Gandhi Road Trust office; Shri Mahatma Gandhi spoke; Mohandas Gandhi too.', [$rule])->text
        );
    }

    /** Test 42: custom placeholders and consistent pseudonyms. */
    public function testPlaceholdersAndPseudonyms(): void
    {
        $rules = [new EmailRule(), new NameRule(['Asha Rao', 'Vikram Singh'], [], [])];
        $custom = $this->redact('Asha Rao wrote to a@b.co.', $rules, false, ['name' => '<person>', 'email' => '<mail>']);
        self::assertSame('<person> wrote to <mail>.', $custom->text);
        $numbered = $this->redact('Asha Rao met Vikram Singh. Asha Rao left.', $rules, true);
        self::assertSame('[NAME_1] met [NAME_2]. [NAME_1] left.', $numbered->text);
        self::assertSame('<person>_3', RedactionContext::numbered('<person>', 3));
    }

    /** Test 44: rule order and no re-matching of placeholders. */
    public function testRuleOrder(): void
    {
        $result = $this->redact('Write mailto:asha@example.com or see https://example.org/u/asha@example.com?x=1', $this->all());
        self::assertSame('Write [EMAIL] or see https://example.org/u/[EMAIL]', $result->text);
        self::assertSame(2, $result->counts['email']);
        // A placeholder made of capitals is never treated as a name, even if listed.
        $named = $this->redact('Contact a@b.co please.', $this->all(['EMAIL'], ['EMAIL']));
        self::assertSame('Contact [EMAIL] please.', $named->text);
        self::assertArrayNotHasKey('name', $named->counts);
    }

    public function testMarkersInInputAreStripped(): void
    {
        $result = $this->redact("Odd \u{E000}\u{E010}\u{E001} text", $this->all());
        self::assertSame('Odd  text', $result->text);
    }

    /** Test 52 (rule level): a throwing rule is disabled and reported once; others apply. */
    public function testThrowingRuleIsDisabled(): void
    {
        $calls = [];
        $bad = new class implements RedactionRule {
            public function type(): string
            {
                return 'custom';
            }

            public function apply(string $text, RedactionContext $context): RedactionResult
            {
                throw new \LogicException('boom');
            }
        };
        $redactor = new Redactor([$bad, new EmailRule()], function (string $type) use (&$calls): void {
            $calls[] = $type;
        });
        $first = $redactor->redact('a@b.co', new RedactionContext());
        $second = $redactor->redact('c@d.co', new RedactionContext());
        self::assertSame('[EMAIL]', $first->text);
        self::assertSame('[EMAIL]', $second->text);
        self::assertSame(['custom'], $calls);
        self::assertTrue($redactor->degraded());
    }

    /** Test 53 (rule level): a regex limit raises RegexLimitExceeded, never returns raw text. */
    public function testRegexLimitRaises(): void
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '50');
        try {
            $this->expectException(RegexLimitExceeded::class);
            $this->redact(str_repeat('a.', 400) . '@', [new EmailRule()]);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $backtrack);
            ini_set('pcre.jit', (string) $jit);
        }
    }

    public function testNameListCap(): void
    {
        $names = [];
        for ($i = 0; $i < 30; $i++) {
            $names[] = 'Person Number' . $i;
        }
        $rule = new NameRule($names, [], [], [], [], 10);
        self::assertTrue($rule->truncated());
        self::assertFalse((new NameRule($names))->truncated());
    }

    public function testEntriesThatCannotMatchAsWordsAreIgnored(): void
    {
        $rule = new NameRule(['john_smith', 'J. R. Tolkien'], [], []);
        self::assertSame('john smith read [NAME].', $this->redact('john smith read J. R. Tolkien.', [$rule])->text);
    }
}

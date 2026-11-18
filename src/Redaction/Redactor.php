<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

use IndianOpinion\FinetuneExport\RegexLimitExceeded;

/**
 * Runs redaction rules in order over a text (PRD section 4.6).
 *
 * A rule that throws anything other than RegexLimitExceeded is disabled for
 * the rest of the job and reported through the degraded callback (case 52).
 * RegexLimitExceeded propagates so the caller skips the record (case 53).
 */
final class Redactor
{
    /** @var list<RedactionRule> */
    private array $rules;
    /** @var array<string, string> rule type => error message */
    private array $disabled = [];
    /** @var (callable(string, \Throwable): void)|null */
    private $onDegraded;

    /**
     * @param list<RedactionRule>                     $rules
     * @param (callable(string, \Throwable): void)|null $onDegraded Called once per disabled rule.
     */
    public function __construct(array $rules, ?callable $onDegraded = null)
    {
        $this->rules = $rules;
        $this->onDegraded = $onDegraded;
    }

    public function redact(string $text, RedactionContext $context): RedactionResult
    {
        $before = $context->counts();
        $text = self::stripMarkers($text);
        foreach ($this->rules as $index => $rule) {
            $key = $index . ':' . $rule->type();
            if (isset($this->disabled[$key])) {
                continue;
            }
            try {
                $result = $rule->apply($text, $context);
            } catch (RegexLimitExceeded $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->disabled[$key] = $e->getMessage();
                if ($this->onDegraded !== null) {
                    ($this->onDegraded)($rule->type(), $e);
                }
                continue;
            }
            $text = $result->text;
        }
        $counts = [];
        foreach ($context->counts() as $type => $n) {
            $delta = $n - ($before[$type] ?? 0);
            if ($delta > 0) {
                $counts[$type] = $delta;
            }
        }
        return new RedactionResult($context->restore($text), $counts);
    }

    public function degraded(): bool
    {
        return $this->disabled !== [];
    }

    /**
     * @return array<string, string>
     */
    public function disabledRules(): array
    {
        return $this->disabled;
    }

    /**
     * @return list<RedactionRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public static function stripMarkers(string $text): string
    {
        $result = preg_replace('/[\x{E000}-\x{E01F}]/u', '', $text);
        if ($result === null) {
            throw new RegexLimitExceeded('Input is not valid UTF-8 or hit a regex limit.');
        }
        return $result;
    }

    /**
     * preg_replace_callback that raises RegexLimitExceeded on failure, for rules.
     *
     * @param callable(array<int|string, string>): string $callback
     */
    public static function replaceCallback(string $pattern, callable $callback, string $text): string
    {
        $result = preg_replace_callback($pattern, $callback, $text);
        if ($result === null) {
            throw new RegexLimitExceeded('Redaction pattern failed: ' . preg_last_error_msg());
        }
        return $result;
    }
}

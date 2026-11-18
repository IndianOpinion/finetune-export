<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Support;

/**
 * Log lines with the prefix `[finetune-export]` (PRD section 11).
 *
 * A site routes them to its own logger with the `finetune_export_log`
 * filter: return true from it to stop the default error_log line. Callers
 * never pass post text, redacted spans or whole tokens.
 */
final class Logger
{
    /** @var array<string, true> */
    private array $once = [];

    /**
     * @param array<string, scalar|null> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Logs a message only the first time this key is seen by this logger.
     *
     * @param array<string, scalar|null> $context
     */
    public function once(string $key, string $level, string $message, array $context = []): void
    {
        if (isset($this->once[$key])) {
            return;
        }
        $this->once[$key] = true;
        $this->log($level, $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $handled = function_exists('apply_filters')
            ? apply_filters('finetune_export_log', false, $level, $message, $context)
            : false;
        if ($handled === true) {
            return;
        }
        $suffix = '';
        foreach ($context as $key => $value) {
            $suffix .= ' ' . $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }
        error_log('[finetune-export] ' . $level . ': ' . $message . $suffix);
    }

    /**
     * Cuts a link token to its first 6 characters for logs (section 8).
     */
    public static function tokenPrefix(string $token): string
    {
        return substr($token, 0, 6) . '...';
    }
}

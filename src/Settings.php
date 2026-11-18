<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * Site settings (PRD section 7) in one option. A constant named
 * FINETUNE_EXPORT_<SETTING> wins over the stored value and makes the field
 * read-only on the screen.
 */
final class Settings
{
    public const OPTION = 'finetune_export_settings';
    public const PROFILES_OPTION = 'finetune_export_profiles';
    public const DEFAULT_PROFILE_OPTION = 'finetune_export_default_profile';
    public const SECRET_OPTION = 'finetune_export_secret';
    public const CANARY_OPTION = 'finetune_export_canary';
    public const COUNTS_OPTION = 'finetune_export_counts';
    public const DB_VERSION_OPTION = 'finetune_export_db_version';
    public const ALL_OPTIONS = [
        self::OPTION, self::PROFILES_OPTION, self::DEFAULT_PROFILE_OPTION, self::SECRET_OPTION,
        self::CANARY_OPTION, self::COUNTS_OPTION, self::DB_VERSION_OPTION, Jobs\OptionLock::OPTION,
    ];

    public const LINK_TTL_MIN = 5;
    public const LINK_TTL_DEFAULT = 60;
    public const PREVIEW_MAX = 50;

    /** Setting => constant suffix. */
    private const CONSTANTS = [
        'retention_days' => 'RETENTION_DAYS',
        'link_ttl_minutes' => 'LINK_TTL_MINUTES',
        'email_link' => 'EMAIL_LINK',
        'preview_size' => 'PREVIEW_SIZE',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // Ruling 1: keep files until the operator deletes them (0 days).
            'retention_days' => 0,
            'link_ttl_minutes' => self::LINK_TTL_DEFAULT,
            'email_link' => false,
            'preview_size' => 5,
            'dir_ack_path' => null,
            'dir_ack_by' => 0,
            'dir_ack_at' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $values = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        foreach (self::CONSTANTS as $key => $suffix) {
            if (defined('FINETUNE_EXPORT_' . $suffix)) {
                $values[$key] = constant('FINETUNE_EXPORT_' . $suffix);
            }
        }
        return self::sanitise($values);
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * True when a constant fixes this setting.
     */
    public function locked(string $key): bool
    {
        return isset(self::CONSTANTS[$key]) && defined('FINETUNE_EXPORT_' . self::CONSTANTS[$key]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(array $changes): void
    {
        $stored = get_option(self::OPTION, []);
        $values = array_merge(self::defaults(), is_array($stored) ? $stored : [], $changes);
        update_option(self::OPTION, self::sanitise($values), false);
    }

    public function retentionDays(): int
    {
        return (int) $this->get('retention_days');
    }

    /**
     * Link lifetime in seconds, clamped to 5 minutes .. the filterable maximum (case 69).
     */
    public function linkTtl(?int $minutes = null): int
    {
        $max = (int) apply_filters('finetune_export_link_ttl_max', 7 * 86400);
        $max = max(self::LINK_TTL_MIN * 60, $max);
        $seconds = ($minutes ?? (int) $this->get('link_ttl_minutes')) * 60;
        return max(self::LINK_TTL_MIN * 60, min($max, $seconds));
    }

    public function previewSize(?int $requested = null): int
    {
        $size = $requested ?? (int) $this->get('preview_size');
        return max(1, min(self::PREVIEW_MAX, $size));
    }

    public function acknowledgedPath(): ?string
    {
        $path = $this->get('dir_ack_path');
        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * The per-site link secret, created on first use (autoload off).
     */
    public static function secret(): string
    {
        $secret = get_option(self::SECRET_OPTION);
        if (!is_string($secret) || strlen($secret) < 64) {
            $secret = bin2hex(random_bytes(32));
            update_option(self::SECRET_OPTION, $secret, false);
        }
        return $secret;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function sanitise(array $values): array
    {
        $out = self::defaults();
        $out['retention_days'] = max(0, min(3650, (int) $values['retention_days']));
        $out['link_ttl_minutes'] = max(self::LINK_TTL_MIN, min(7 * 1440, (int) $values['link_ttl_minutes']));
        $out['email_link'] = (bool) $values['email_link'];
        $out['preview_size'] = max(1, min(self::PREVIEW_MAX, (int) $values['preview_size']));
        $path = $values['dir_ack_path'] ?? null;
        $out['dir_ack_path'] = is_string($path) && $path !== '' ? $path : null;
        $out['dir_ack_by'] = (int) ($values['dir_ack_by'] ?? 0);
        $out['dir_ack_at'] = (int) ($values['dir_ack_at'] ?? 0);
        return $out;
    }
}

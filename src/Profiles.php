<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * Saved profiles in one non-autoloaded option.
 */
final class Profiles
{
    /**
     * @return array<string, Profile>
     */
    public function all(): array
    {
        $stored = get_option(Settings::PROFILES_OPTION, []);
        $out = [];
        if (is_array($stored)) {
            foreach ($stored as $data) {
                if (!is_array($data)) {
                    continue;
                }
                try {
                    $profile = Profile::fromArray($data);
                } catch (InvalidProfile $e) {
                    continue;
                }
                $out[$profile->id()] = $profile;
            }
        }
        if ($out === []) {
            $default = Profile::fromArray(Profile::defaults());
            $out[$default->id()] = $default;
        }
        ksort($out);
        return $out;
    }

    public function get(?string $id = null): Profile
    {
        $all = $this->all();
        $id = $id ?? $this->defaultId();
        if (!isset($all[$id])) {
            throw new InvalidProfile('id', 'no profile with id "' . $id . '"');
        }
        return $all[$id];
    }

    public function defaultId(): string
    {
        $id = get_option(Settings::DEFAULT_PROFILE_OPTION, 'default');
        $all = $this->all();
        return is_string($id) && isset($all[$id]) ? $id : (string) array_key_first($all);
    }

    /**
     * Saves a profile. Settings that need the sensitive capability are
     * refused without it (cases 3, 4 and 50).
     */
    public function save(Profile $profile, bool $canSensitive): void
    {
        self::assertAllowed($profile, $canSensitive);
        $stored = get_option(Settings::PROFILES_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $stored[$profile->id()] = $profile->toArray();
        update_option(Settings::PROFILES_OPTION, $stored, false);
    }

    public function delete(string $id): void
    {
        $stored = get_option(Settings::PROFILES_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        unset($stored[$id]);
        update_option(Settings::PROFILES_OPTION, $stored, false);
    }

    public function setDefault(string $id): void
    {
        $this->get($id);
        update_option(Settings::DEFAULT_PROFILE_OPTION, $id, false);
    }

    /**
     * @throws PermissionDenied
     */
    public static function assertAllowed(Profile $profile, bool $canSensitive): void
    {
        $sensitive = $profile->sensitiveChoices();
        if ($sensitive !== [] && !$canSensitive) {
            throw new PermissionDenied(
                'These settings need the finetune_export_sensitive capability: ' . implode(', ', $sensitive)
            );
        }
    }
}

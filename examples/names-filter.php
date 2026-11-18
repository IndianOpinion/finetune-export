<?php

/**
 * Feeds names from a site's own register of people into name redaction
 * (source d), and keeps a few public names with the allowlist in the
 * profile instead. Drop into a must-use plugin.
 */

declare(strict_types=1);

add_filter('finetune_export_names', static function (array $names): array {
    $register = get_option('my_site_people_register', []);
    foreach (is_array($register) ? $register : [] as $person) {
        if (is_string($person) && $person !== '') {
            $names[] = $person;
        }
    }
    return $names;
});

// Add a street type used locally.
add_filter('finetune_export_street_types', static function (array $types): array {
    $types[] = 'Maidan';
    return $types;
});

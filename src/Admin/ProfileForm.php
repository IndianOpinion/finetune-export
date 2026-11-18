<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Admin;

use IndianOpinion\FinetuneExport\Content\WpContentSource;
use IndianOpinion\FinetuneExport\Profile;

/**
 * The profile editor. Settings that need the sensitive capability are
 * disabled for users without it; the server checks again on save.
 */
final class ProfileForm
{
    private const FIELD_LABELS = [
        'id' => 'Post id', 'title' => 'Title', 'excerpt' => 'Excerpt', 'body' => 'Body',
        'date' => 'Published date', 'modified' => 'Modified date', 'permalink' => 'Permalink',
        'categories' => 'Categories', 'tags' => 'Tags', 'author' => 'Author display name',
        'image_alt' => 'Featured image alternative text',
    ];

    /**
     * Reads the posted profile array. Values are validated by Profile::fromArray.
     *
     * @return array<string, mixed>
     */
    public static function fromPost(): array
    {
        $raw = isset($_POST['profile']) && is_array($_POST['profile']) ? wp_unslash($_POST['profile']) : [];
        return is_array($raw) ? self::clean($raw) : [];
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, mixed>
     */
    private static function clean(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                $out[$key] = self::clean($value);
            } elseif (is_scalar($value)) {
                // Templates and name lists keep their line breaks; Profile validates them.
                $out[$key] = in_array($key, ['system', 'user', 'assistant', 'prompt', 'completion', 'text', 'operator_names', 'allowlist', 'meta_keys'], true)
                    ? sanitize_textarea_field((string) $value)
                    : sanitize_text_field((string) $value);
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(array $data, bool $canSensitive, string $previewUrl): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('finetune_export_save_profile');
        echo '<input type="hidden" name="action" value="finetune_export_save_profile">';
        echo '<table class="form-table">';
        $this->text('id', __('Profile id', 'finetune-export'), (string) $data['id'], 'lowercase letters, digits, hyphens');
        $this->text('name', __('Name', 'finetune-export'), (string) $data['name']);

        $this->checkboxes('post_types', __('Post types', 'finetune-export'), WpContentSource::offeredPostTypes(), (array) $data['post_types']);
        $this->checkbox('allow_unpublished', __('Allow unpublished content', 'finetune-export'), (bool) $data['allow_unpublished'], !$canSensitive, __('Needs the sensitive capability. Unpublished posts may hold text nobody meant to publish.', 'finetune-export'));
        $statuses = array_combine(Profile::STATUSES, Profile::STATUSES);
        $this->checkboxes('statuses', __('Statuses', 'finetune-export'), $statuses, (array) $data['statuses'], !$canSensitive);
        $this->checkbox('include_password_protected', __('Include password-protected posts', 'finetune-export'), (bool) $data['include_password_protected'], !$canSensitive);
        $this->text('date_from', __('Published from (YYYY-MM-DD, site time)', 'finetune-export'), (string) ($data['date_from'] ?? ''));
        $this->text('date_to', __('Published to (inclusive)', 'finetune-export'), (string) ($data['date_to'] ?? ''));
        foreach (['include_categories', 'exclude_categories', 'include_tags', 'exclude_tags', 'include_authors', 'exclude_authors'] as $key) {
            $this->text($key, ucfirst(str_replace('_', ' ', $key)) . ' ' . __('(ids, comma separated)', 'finetune-export'), implode(',', (array) $data[$key]));
        }
        $this->text('min_length', __('Minimum body length in characters', 'finetune-export'), (string) ($data['min_length'] ?? ''));
        $this->text('max_length', __('Maximum body length in characters', 'finetune-export'), (string) ($data['max_length'] ?? ''));
        $this->text('limit', __('Maximum number of records', 'finetune-export'), (string) ($data['limit'] ?? ''));

        $this->checkboxes('fields', __('Fields', 'finetune-export'), self::FIELD_LABELS, (array) $data['fields']);
        $this->textarea('meta_keys', __('Post meta keys (one per line, no leading underscore)', 'finetune-export'), implode("\n", (array) $data['meta_keys']));
        $this->checkbox('keep_empty_fields', __('Keep empty fields', 'finetune-export'), (bool) $data['keep_empty_fields']);
        $this->select('format', __('Text format', 'finetune-export'), array_combine(Profile::FORMATS, Profile::FORMATS), (string) $data['format']);
        $this->checkbox('apply_content_filters', __('Apply content filters from other plugins', 'finetune-export'), (bool) $data['apply_content_filters']);
        $this->checkbox('render_dynamic_blocks', __('Render dynamic blocks', 'finetune-export'), (bool) $data['render_dynamic_blocks']);
        $this->checkbox('keep_image_alt', __('Keep image alt text inline', 'finetune-export'), (bool) $data['keep_image_alt']);

        $this->select('shape', __('Record shape', 'finetune-export'), ['chat' => 'chat', 'prompt_completion' => 'prompt and completion', 'text' => 'text'], (string) $data['shape']);
        foreach ((array) $data['templates'] as $key => $template) {
            $this->textarea('templates][' . $key, sprintf(__('Template: %s', 'finetune-export'), $key), (string) $template, 'Placeholders: {title} {excerpt} {body} {date} {modified} {id} {categories} {tags} {author} {permalink} {image_alt} {meta:key}');
        }
        $this->checkbox('include_metadata', __('Include metadata (post id, permalink, date)', 'finetune-export'), (bool) $data['include_metadata']);

        echo '<tr><th colspan="2"><h3>' . esc_html__('Redaction', 'finetune-export') . '</h3><p class="description">' . esc_html(AdminScreen::RESPONSIBILITY) . '</p></th></tr>';
        foreach (Profile::REDACTION_TYPES as $type) {
            $this->checkbox('redaction][' . $type, sprintf(__('Redact %s', 'finetune-export'), $type), (bool) $data['redaction'][$type], !$canSensitive, __('Turning this off needs the sensitive capability.', 'finetune-export'), true);
        }
        foreach (Profile::PLACEHOLDER_TYPES as $type) {
            $this->text('placeholders][' . $type, sprintf(__('Placeholder for %s', 'finetune-export'), $type), (string) $data['placeholders'][$type]);
        }
        foreach (['users' => __('Names of site users', 'finetune-export'), 'operator' => __('My list of names', 'finetune-export'), 'honorifics' => __('Honorific followed by capitalised words', 'finetune-export'), 'filter' => __('Names from the name-source filter', 'finetune-export')] as $source => $label) {
            $this->checkbox('name_sources][' . $source, $label, (bool) $data['name_sources'][$source], !$canSensitive, __('Turning this off needs the sensitive capability.', 'finetune-export'), true);
        }
        $this->textarea('operator_names', __('My list of names (one per line, always redacted)', 'finetune-export'), implode("\n", (array) $data['operator_names']));
        $this->textarea('allowlist', __('Allowlist: names and phrases to keep (one per line)', 'finetune-export'), implode("\n", (array) $data['allowlist']));
        $this->checkbox('pseudonyms', __('Consistent pseudonyms ([NAME_1], [NAME_2] per record)', 'finetune-export'), (bool) $data['pseudonyms']);

        echo '<tr><th colspan="2"><h3>' . esc_html__('Limits and output', 'finetune-export') . '</h3></th></tr>';
        $this->text('max_tokens', __('Maximum estimated tokens per record', 'finetune-export'), (string) ($data['max_tokens'] ?? ''));
        $this->select('over_limit', __('Over the limit', 'finetune-export'), ['skip' => 'skip', 'truncate' => 'truncate at a paragraph break'], (string) $data['over_limit']);
        $this->text('validation_percent', __('Validation split in percent (0 is off)', 'finetune-export'), (string) $data['validation_percent']);
        $this->checkbox('compress', __('Compress with gzip', 'finetune-export'), (bool) $data['compress']);
        echo '</table><p>';
        submit_button(__('Save profile', 'finetune-export'), 'primary', 'submit', false);
        echo ' ';
        // Preview posts the same, unsaved form to the Preview tab (case 89).
        wp_nonce_field('finetune_export_preview', '_wpnonce_preview', false);
        printf(
            '<button type="submit" class="button" name="finetune_export_preview" value="1" formaction="%s">%s</button>',
            esc_url($previewUrl),
            esc_html__('Preview these settings', 'finetune-export')
        );
        echo '</p></form>';
    }

    private function text(string $name, string $label, string $value, string $hint = ''): void
    {
        printf(
            '<tr><th><label>%s</label></th><td><input type="text" class="regular-text" name="profile[%s]" value="%s">%s</td></tr>',
            esc_html($label),
            esc_attr($name),
            esc_attr($value),
            $hint !== '' ? ' <span class="description">' . esc_html($hint) . '</span>' : ''
        );
    }

    private function textarea(string $name, string $label, string $value, string $hint = ''): void
    {
        printf(
            '<tr><th><label>%s</label></th><td><textarea class="large-text code" rows="3" name="profile[%s]">%s</textarea>%s</td></tr>',
            esc_html($label),
            esc_attr($name),
            esc_textarea($value),
            $hint !== '' ? '<p class="description">' . esc_html($hint) . '</p>' : ''
        );
    }

    private function checkbox(string $name, string $label, bool $checked, bool $disabled = false, string $hint = '', bool $hiddenZero = false): void
    {
        $hidden = $hiddenZero || $disabled
            ? sprintf('<input type="hidden" name="profile[%s]" value="%s">', esc_attr($name), $disabled ? ($checked ? '1' : '0') : '0')
            : '';
        printf(
            '<tr><th>%s</th><td>%s<label><input type="checkbox" name="profile[%s]" value="1" %s %s> %s</label>%s</td></tr>',
            esc_html($label),
            $hidden,
            esc_attr($name),
            checked($checked, true, false),
            disabled($disabled, true, false),
            esc_html($label),
            $hint !== '' ? '<p class="description">' . esc_html($hint) . '</p>' : ''
        );
    }

    /**
     * @param array<string, string> $options
     * @param array<mixed>          $selected
     */
    private function checkboxes(string $name, string $label, array $options, array $selected, bool $disabled = false): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td>';
        foreach ($options as $value => $text) {
            $on = in_array($value, $selected, true);
            if ($disabled && $on) {
                printf('<input type="hidden" name="profile[%s][]" value="%s">', esc_attr($name), esc_attr((string) $value));
            }
            printf(
                '<label style="margin-right:1em"><input type="checkbox" name="profile[%s][]" value="%s" %s %s> %s</label>',
                esc_attr($name),
                esc_attr((string) $value),
                checked($on, true, false),
                disabled($disabled, true, false),
                esc_html($text)
            );
        }
        echo '</td></tr>';
    }

    /**
     * @param array<string, string> $options
     */
    private function select(string $name, string $label, array $options, string $current): void
    {
        printf('<tr><th>%s</th><td><select name="profile[%s]">', esc_html($label), esc_attr($name));
        foreach ($options as $value => $text) {
            printf('<option value="%s" %s>%s</option>', esc_attr((string) $value), selected($current, $value, false), esc_html($text));
        }
        echo '</select></td></tr>';
    }
}

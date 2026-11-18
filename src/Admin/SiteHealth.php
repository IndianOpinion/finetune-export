<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Admin;

use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Storage\Canary;
use IndianOpinion\FinetuneExport\UnsafeDirectory;

/**
 * One Site Health test: directory safe, last canary result, retention (section 11).
 */
final class SiteHealth
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function register(array $tests): array
    {
        $tests['direct']['finetune_export_directory'] = [
            'label' => __('Fine-tune export directory', 'finetune-export'),
            'test' => [$this, 'test'],
        ];
        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    public function test(): array
    {
        $result = [
            'label' => __('The fine-tune export directory is protected', 'finetune-export'),
            'status' => 'good',
            'badge' => ['label' => __('Privacy', 'finetune-export'), 'color' => 'blue'],
            'description' => '',
            'test' => 'finetune_export_directory',
        ];
        $lines = [];
        try {
            $dir = $this->plugin->storage()->resolve();
            $lines[] = sprintf(__('Directory: %s.', 'finetune-export'), $dir->path);
            if ($dir->insideWebRoot) {
                $result['status'] = 'recommended';
                $result['label'] = __('The fine-tune export directory is inside the web root', 'finetune-export');
                $lines[] = __('It is inside the web root by acknowledgement. Moving it outside is safer.', 'finetune-export');
            }
        } catch (UnsafeDirectory $e) {
            $result['status'] = 'critical';
            $result['label'] = __('No safe fine-tune export directory', 'finetune-export');
            $lines[] = $e->getMessage();
        }
        $last = Canary::last();
        $lines[] = sprintf(__('Last self-check: %s.', 'finetune-export'), $last['result']);
        if ($last['result'] === Canary::SERVED) {
            $result['status'] = 'critical';
            $result['label'] = __('The fine-tune export directory is served over HTTP', 'finetune-export');
        } elseif ($last['result'] === Canary::UNKNOWN && $result['status'] === 'good') {
            $result['status'] = 'recommended';
        }
        $days = $this->plugin->settings()->retentionDays();
        $lines[] = $days > 0
            ? sprintf(__('Retention: files are removed %d days after their job ends.', 'finetune-export'), $days)
            : __('Retention is off: files are kept until you delete them.', 'finetune-export');
        $result['description'] = '<p>' . implode('</p><p>', array_map('esc_html', $lines)) . '</p>';
        return $result;
    }
}

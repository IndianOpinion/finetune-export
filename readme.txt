=== Fine-tune Export ===
Contributors: indianopinion
Tags: export, jsonl, fine-tuning, privacy, redaction
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Export published posts as fine-tuning JSONL with best-effort redaction, a local token estimate and protected, expiring downloads.

== Description ==

Written from scratch; not derived from any third-party plugin.

Redaction is best-effort. It will miss some personal data. The export, and what you do with it, is your responsibility under the data-protection law that applies to you.

* Published posts only by default; other statuses need a setting and a stronger capability.
* Three record shapes: chat, prompt and completion, plain text, filled from generic templates you edit.
* Cleans block markup, shortcodes, scripts and embeds.
* Redacts URLs' query strings, emails, IP addresses, phone numbers, addresses, postcodes and names, on by default.
* Preview and count without writing anything.
* Local token estimate. No model calls, no uploads, no network calls except a self-check to your own site.
* Files go to a protected directory outside the web root. If none is safe, nothing runs.
* Downloads only through a signed, expiring link for a signed-in user.
* Files are removed only by your retention setting or your own delete, and each removal is logged.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` and activate it.
2. Optional but best: add `define('FINETUNE_EXPORT_DIR', '/path/outside/web/root');` to `wp-config.php`.
3. Go to Tools > Fine-tune export.

== Frequently Asked Questions ==

= Does it call any AI service? =

No. It makes no model call and uploads nothing.

= Why does my download link return 404? =

It expired, was revoked, belongs to another user, or the file was deleted. Create a new one on the Jobs tab.

= What happens on uninstall? =

Options, the job tables, the opt-out switches and the capabilities are removed. Export files are never removed by uninstall. Delete them first with the Delete files button or `wp finetune-export delete <job> --yes`. Remaining files stay in your export directory (`FINETUNE_EXPORT_DIR`, or `finetune-exports` one level above the WordPress root).

== Changelog ==

= 0.1.0 =
* First release.

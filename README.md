# finetune-export

A WordPress plugin that exports your published posts as JSONL for fine-tuning, with field selection, redaction of names and addresses, a sample preview and a local token estimate. It exists because the easy ways out of WordPress (database dumps, the XML export, files in uploads) leak drafts, personal data and private files.

Written from scratch; not derived from any third-party plugin.

Redaction is best-effort. It will miss some personal data. The export, and what you do with it, is your responsibility under the data-protection law that applies to you.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

By default only published posts are exported and redaction is on. The plugin makes no model calls and uploads nothing. Files are written to a protected directory (the plugin refuses to run if none is safe) and reached only through an expiring link for a signed-in user. Files are removed only by the retention setting or by an explicit operator delete, and each removal is logged.

**Measured result:** on the site this came from, private export archives written under uploads were reachable by direct URL through the media mirror; writing to a protected directory with a signed-in download route closed it.

## Install

Requires WordPress 6.4+, PHP 8.1+ with `dom`, `json` and `mbstring`. `intl` (Unicode NFC) and `zlib` (gzip output) are optional.

**Without Composer:** download the release zip, unzip it into `wp-content/plugins/finetune-export/`, activate it. The plugin loads its classes with `autoload.php`; nothing else is needed.

**With Composer:**

```sh
composer require indianopinion/finetune-export
```

The package type is `wordpress-plugin`: with `composer/installers` in your project it lands in your plugins directory. The plugin has no runtime Composer dependencies.

**As a must-use plugin:** copy the plugin folder to `wp-content/mu-plugins/finetune-export/` and copy `examples/mu-loader.php` to `wp-content/mu-plugins/finetune-export-loader.php`. The loader requires `autoload.php`, registers the plugin and runs the activation once (tables, capabilities, daily event).

## Quick start

1. Choose where files go. Best: a directory outside the web root, in `wp-config.php`:
   ```php
   define('FINETUNE_EXPORT_DIR', '/srv/private/finetune-exports');
   ```
   Without it the plugin uses `finetune-exports` one level above the WordPress root, if that parent is writable. If no safe directory exists, nothing runs.
2. Tools > Fine-tune export > Profiles: pick fields, shape and templates. Press **Preview these settings** to see the first records after cleaning and redaction. Nothing is written.
3. Press **Run export**. The job runs in batches through WP-Cron. Watch it on the Jobs tab.
4. On the Jobs tab press **Create train download link**. The link works only for you, while signed in, for 60 minutes by default.
5. When you are done, press **Delete files**.

From WP-CLI:

```sh
wp finetune-export check-directory
wp finetune-export preview --limit=3
wp finetune-export count
wp finetune-export run --foreground
wp finetune-export link 12 --user=1 --ttl=30
wp finetune-export delete 12 --yes
```

## Configuration

Site settings (Settings tab). A constant `FINETUNE_EXPORT_<NAME>` in `wp-config.php` wins and makes the field read-only.

| Setting | Default | Constant | Note |
|---|---|---|---|
| Export directory | `finetune-exports` above ABSPATH | `FINETUNE_EXPORT_DIR` | Or the `finetune_export_directory` filter. Never inside uploads. |
| Retention in days | 0 (keep until you delete) | `FINETUNE_EXPORT_RETENTION_DAYS` | Counted from the end of the job |
| Link lifetime | 60 minutes | `FINETUNE_EXPORT_LINK_TTL_MINUTES` | 5 minutes to 7 days (filterable maximum) |
| Email me the link | off | `FINETUNE_EXPORT_EMAIL_LINK` | Sends only to your own account address |
| Preview size | 5 | `FINETUNE_EXPORT_PREVIEW_SIZE` | At most 50 |
| Directory inside the web root | not acknowledged | | Needs `finetune_export_sensitive`; void when the path changes |

Profile settings (Profiles tab, or `wp finetune-export profiles import`):

| Setting | Default | Note |
|---|---|---|
| Post types | `post` | Public types only |
| Statuses | `publish` | Others need "allow unpublished content" and `finetune_export_sensitive` |
| Password-protected posts | excluded | Including them needs `finetune_export_sensitive` |
| Date range, categories, tags, authors | none | Include and exclude lists; dates inclusive, site time |
| Minimum and maximum body length | none | Characters after cleaning |
| Record limit | none | |
| Fields | title, body | Also id, excerpt, date, modified, permalink, categories, tags, author, image_alt, allowlisted meta keys |
| Keep empty fields | off | Off drops template lines whose placeholders are all empty |
| Text format | `text` | `text`, `markdown` or `html` (small tag allowlist) |
| Apply content filters / render dynamic blocks / keep image alt text | off | |
| Shape | `chat` | `chat`, `prompt_completion`, `text` |
| Templates | `Write an article with the headline: {title}` and `{body}` | Generic; replace them |
| Include metadata | off | Adds post id, permalink, date |
| Redaction, each type | on | url, email, ip, phone, address, name. Turning one off needs `finetune_export_sensitive` |
| Placeholders | `[NAME]`, `[EMAIL]`, `[PHONE]`, `[ADDRESS]`, `[POSTCODE]`, `[IP]` | |
| Name sources | users, my list, honorifics, filter | Users include display, first, last, nick names and logins |
| Allowlist | empty | Names and phrases never replaced |
| Consistent pseudonyms | off | `[NAME_1]`, `[NAME_2]` per record |
| Maximum tokens per record | none | Policy `skip` or `truncate` (at a paragraph break) |
| Validation split | 0 percent | Stable split by job seed and post id |
| Compress | off | gzip |

Per post: the **Exclude from training exports** switch in the editor. Per category: the same switch on the category screen. Both are shown only to holders of `finetune_export_run`.

### Capabilities

- `finetune_export_run`: preview, count, run, download, delete your own jobs. Administrators get it on activation.
- `finetune_export_sensitive`: unpublished or password-protected posts, turning off redaction, the web root acknowledgement, acting on other users' jobs. Administrators on a single site; super admins only on multisite.

### Hooks

| Filter | Receives | Default |
|---|---|---|
| `finetune_export_query_args` | query arguments, profile | published posts of the profile's types |
| `finetune_export_fields` | field map, post data, profile | |
| `finetune_export_clean_text` | cleaned text, field name, post data | unchanged |
| `finetune_export_redaction_rules` | list of rules, profile | built-in rules in order |
| `finetune_export_names` | list of names, profile | empty |
| `finetune_export_name_stoplist` | list of words | shipped list |
| `finetune_export_honorifics` | list of words | shipped list |
| `finetune_export_street_types` | list of words | shipped list |
| `finetune_export_record` | record array, post data, profile | unchanged; return null to skip |
| `finetune_export_token_estimator` | `TokenEstimator` | `HeuristicEstimator` |
| `finetune_export_directory` | path or null | null |
| `finetune_export_batch_size` | int | 100 |
| `finetune_export_link_ttl_max` | seconds | 604800 |
| `finetune_export_log_download_ip` | bool | false |
| `finetune_export_strip_unknown_shortcodes` | bool | true |
| `finetune_export_log` | handled, level, message, context | false (writes to `error_log`) |

"Post data" is an `IndianOpinion\FinetuneExport\Content\PostData` object; `$post->id` gives the post id.

Actions: `finetune_export_job_started`, `finetune_export_job_completed`, `finetune_export_job_failed` (job id, counters), `finetune_export_file_removed` (job id, reason), `finetune_export_downloaded` (job id, user id, outcome).

REST: `GET|HEAD /wp-json/finetune-export/v1/download/<token>`, `POST /finetune-export/v1/preview`, `GET /finetune-export/v1/jobs` and `/jobs/<id>` (no file contents).

## How it works

1. **Select.** Posts are read in ascending id order through `get_posts`, in batches. Every narrowing rule that drops a post counts a reason (`out_of_range`, `excluded_term`, `excluded_author`, `too_short`, `too_long`, `opted_out`, `opted_out_category`, `duplicate`, and so on). Revisions, attachments, trash and autosaves are never selected.
2. **Clean.** Block comments and shortcode tags go, enclosed shortcode text stays, scripts, iframes, forms and similar elements go with their content, entities are decoded, whitespace and invisible characters are normalised.
3. **Fill templates, then redact** in a fixed order: URLs, emails, IPs, phones, addresses and postcodes, names. Placeholders are protected markers, so a later rule never matches an earlier placeholder.
4. **Estimate** tokens locally (see below), apply the token limit, encode one JSON object per line.
5. **Write** batch by batch to a `.part` file (0600) in the protected directory (0700, with deny files). On completion it is renamed. A dead batch is cut back to the last good offset and resumed.
6. **Download** through a signed link: 83 characters, no file extension, HMAC-SHA256 over job, user, expiry and a nonce, keyed by the site salts and a per-site secret. Anonymous requests get 401 before any lookup; every other refusal, including an expired link, is the same 404.

The **token estimate**: ASCII letter and digit runs count `ceil(length / 4)`, other ASCII punctuation 1, whitespace 0, Han, Kana and Hangul 1 per character, every other character `ceil(bytes / 2)`, plus 4 per chat message or 3 per record. It can be off by 25 percent or more, most for non-Latin scripts. Measure it against your model's tokeniser with `php tools/calibrate.php` (offline, see the script header) and plug in your own estimator if you need better.

If the directory is inside the web root (acknowledged), a **self-check** on save and once a day writes a random canary file and asks for it over HTTP through the site's URL. If it is served, jobs and downloads stop until the check passes. A network error is reported as "unknown", never as safe.

## Limitations

- Redaction is lists and patterns. It misses names not on a list and not after an honorific, unusual address and phone formats, and personal data in images. It also replaces some text that is not personal. Review the preview and the redaction report.
- Single-word names from site users need 4 or more letters, a capital, and not to start a sentence; this trades recall for fewer false hits.
- The token estimate is advisory.
- No multi-turn chat records in 0.1.0.
- Downloads have no byte ranges or resume.
- Tested up to 200,000 posts in design; the user list for redaction is capped at 50,000 names.

## Uninstall

Uninstall removes the options, the two job tables, the per-post and per-category opt-out meta, and the capabilities. **It never removes export files**, because the job history that records removals goes with the tables. Delete files first with **Delete files** or `wp finetune-export delete <job> --yes`. Any files left stay in your export directory (`FINETUNE_EXPORT_DIR`, or `finetune-exports` one level above the WordPress root, with a `site-<id>` subdirectory on multisite).

## FAQ

**Does it send anything to a model or provider?** No. It makes no model call and uploads nothing. The only outbound request is the self-check to your own site.

**Why does my link return 404?** It has expired, been revoked, belongs to another user, the file was deleted, or you lost the capability. These look the same on purpose. Create a new link on the Jobs tab.

**Why not write to uploads?** Uploads are often copied to object storage or a CDN that serves them without asking the web server, so a deny rule does not protect them. Uploads are refused even with the acknowledgement.

**Can I export drafts?** Yes, with "allow unpublished content" and the `finetune_export_sensitive` capability. The screen warns while it is on.

**How do I keep a public figure's name?** Add it to the profile's allowlist.

## Licence

MIT. See [LICENSE](LICENSE).

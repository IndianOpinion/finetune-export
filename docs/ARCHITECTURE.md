# finetune-export architecture

This document describes how the plugin is built and records every decision
taken while building it from `docs/PRD.md`, including the rulings on the
PRD's open questions. Case numbers refer to PRD section 4.

## Modules

| Module | Role |
|---|---|
| `Plugin` | Wires hooks, builds services once per request, cron handlers, activation and deactivation |
| `Profile`, `Profiles` | Immutable, validated profile; saved profiles in one non-autoloaded option |
| `Settings` | Site settings, constants override, link secret |
| `Content\WpContentSource` | Reads posts through core functions only; enforces never-selected types and statuses |
| `Cleaner`, `CleanOptions` | Pure PHP HTML to text, Markdown or HTML subset (DOMDocument) |
| `Redaction\*` | `Redactor`, `RedactionContext` (protected markers, pseudonyms, counts), one class per rule, `RedactorFactory` |
| `Estimate\*` | `TokenEstimator`, `HeuristicEstimator`, `Stats`, `Calibration` |
| `Pipeline` | One post to one record or one skip reason; shared by preview, count and export |
| `Exporter` | Jobs: start, batch step, cancel, resume, stall detection, removal, retention |
| `Jobs\*` | `Job` entity, `JobRepository` (wpdb and in-memory), `OptionLock` |
| `Storage\Storage`, `Storage\Canary` | Protected directory, file I/O, the only two removal calls, web root self-check |
| `Links\*` | `LinkSigner` (tokens), `LinkService` (issue, revoke, email) |
| `Rest\*` | REST routes and the download handler, which writes through a `ResponseSink` |
| `Admin\*` | Tools screen, profile form, opt-out switches, Site Health test |
| `Cli\Command` | WP-CLI commands |
| `Uninstaller` | Database clean-up on uninstall |

Everything except the thin WordPress adapters is plain PHP and is unit tested
directly. WordPress functions are replaced in tests by `tests/stubs/`.

## Data flow

```
profile --> WpContentSource.nextIds (ascending, batch) --> Pipeline.process(id)
   selection skips --> Cleaner (body, fields) --> fields filter --> templates
   --> Redactor (url, email, ip, phone, address, name) --> record filter
   --> estimator --> token limit --> json_encode --> duplicate check
--> Exporter appends the batch to export-<job>-<random>.jsonl[.gz].part
--> on completion: rename, state complete
--> LinkService.issue --> /wp-json/finetune-export/v1/download/<token>
--> DownloadHandler: checks, streams in 1 MB chunks, ends the request
```

## Rulings on the open questions

1. **Retention default: keep until the operator deletes (0 days).** A file is
   never removed without an explicit operator choice. The screen shows "kept
   until deleted" next to every file and Site Health says retention is off.
2. **User logins are redacted too.** They go through the same single-word
   limits as other user names (4 or more letters, starting with a capital,
   not sentence-initial, not on the stoplist), so lowercase logins never
   cause hits. Logins with characters that cannot form words (for example
   `john_smith`) are ignored.
3. **An expired link answers 404, identical to an unknown one.** This replaces
   the 410 in case 74 and test 68. Nobody learns that a token was once valid.
   The download history still records the outcome `expired`.
4. **Calibration: an offline script, `tools/calibrate.php`.** It compares the
   estimator with reference counts per script, either from a JSONL file of
   `{"text", "tokens"}` lines or from a tokeniser callable the operator
   supplies in a PHP file. It refuses URLs and makes no network call. No
   tokeniser ships with the plugin. This build did not measure the error
   against a real tokeniser; the operator measures it for the model they use.
5. **No system temp fallback.** If no candidate directory is acceptable the
   plugin fails closed.
6. **Per-category opt-out as well as per-post.** A term meta switch on
   categories; posts in such a category are skipped with the reason
   `opted_out_category`. Uninstall removes the term meta.
7. **No multi-turn records in 0.1.0.**
8. **No CSV of redaction counts.** Counts per type and the ten posts with the
   most replacements are shown on the Jobs tab, in `jobs show` and in the
   jobs REST route.

## Other decisions

- **Directory resolution takes the first candidate that is set.** If the
  constant is defined and refused, the filter and default are not tried.
  An operator who set a path expects that path; silently writing elsewhere
  would surprise them.
- **Web root test.** A path inside ABSPATH, WP_CONTENT_DIR (the whole
  content directory counts as public), the home directory when WordPress
  lives in a subdirectory of the site (worked out from the home and site
  URLs), or the document root PHP reports (a filesystem root is ignored) needs the acknowledgement, stored with
  the normalised path and void on any change. Symbolic links are resolved on
  the longest existing prefix, so a link into uploads is refused. Relative
  paths and `..` segments are refused.
- **Multisite** uses `site-<blog id>` under the directory on every site.
- **Lock.** The lock is a per-batch mutex with an owner token in one
  non-autoloaded row of the options table, written with SQL that MySQL
  makes atomic: a plain INSERT, which fails on the unique option name, and
  a compare-and-swap UPDATE on the value this request read. The lock is read
  from the database, never from the object cache. `add_option` is not used:
  it sends INSERT ... ON DUPLICATE KEY UPDATE, so two requests racing past
  its existence check both get a truthy result (verified against MySQL 8.0
  in review, where eight racing processes then always produced exactly one
  holder). It expires
  after 10 minutes, not 30: a batch takes seconds, and the watchdog cron event
  (15 minutes after a batch starts) must be able to take over the lock of a
  batch that died, so case 81 resumes on its own. "One job at a time" is kept
  by job state: a queued job does not start while another is running, and
  queued jobs run in id order. A job with no progress for 30 minutes is still
  marked stalled (case 84) and the screen offers resume and cancel.
- **Resume.** Offsets and the last post id are saved together after each
  batch. A batch writes its records in one append, so a crash leaves at most
  a tail that the next run cuts with `ftruncate`.
- **Duplicates** use a second table, `finetune_export_hashes` (job id, hash,
  post id), instead of a growing list in the job row. The hash is SHA-1 of
  the target text (assistant message, completion or text) after cleaning and
  redaction, which is the body under the default templates. A hash maps to
  the first post that produced it, so re-running a batch after a crash never
  marks a post as a duplicate of itself. Rows are cleared when the job ends.
- **Job table** holds kind, state, creator and timestamps as columns and the
  rest (profile snapshot, counters, stats, report, files, links, history,
  downloads) as JSON. No post text is stored. History keeps the last 500
  entries, downloads the last 200.
- **Token statistics** are kept as a map from estimate to record count, so
  the median and 95th percentile (nearest rank) are exact without keeping one
  number per post.
- **Estimator input.** The decoded string values of the record are counted;
  chat `role` values are left out because the per-message overhead covers
  them. Metadata strings count, since they are written.
- **Empty fields (case 17)** are handled in templates: without "keep empty
  fields", a template line whose placeholders all rendered empty is dropped,
  and a non-target chat message that renders empty is omitted. An empty
  target still skips the record (`empty_target`).
- **Redaction markers.** Rules insert protected markers built from
  private-use code points U+E000 to U+E01F; the redactor strips those code
  points from input first, then restores markers to visible placeholders at
  the end. Custom rules should use `RedactionContext::placeholder()`.
- **Names** are matched by word with hash lookups, not one large regular
  expression, so 50,000 names stay fast. The allowlist is found first and no
  name may overlap an allowlisted word. Honorific matches keep the honorific
  ("Dr [NAME]"). Pseudonyms number distinct names in order of discovery.
- **URLs.** Query string, fragment and user information are removed. The
  permalink loses its query too, so plain permalinks (`?p=123`) lose the id;
  use metadata `post_id` to trace records.
- **Zero-width characters.** U+200B, U+2060 and U+FEFF are removed; ZWJ and
  ZWNJ stay because they are spelling in Indic scripts, Persian and emoji.
- **Shortcodes.** By default any lowercase `[tag]` is stripped, which also
  catches tags left by inactive plugins; the filter
  `finetune_export_strip_unknown_shortcodes` limits it to registered tags.
- **Cleaning failures.** A PCRE limit in cleaning is treated like one in
  redaction: the record is skipped as `redaction_error`.
- **Download order.** Malformed 404, foreign Origin 404, anonymous 401, no
  capability 404, unsafe directory or served canary 503, then signature,
  user, expiry, revocation, job and file checks, all 404 with one body.
- **Token format.** 30-byte payload (version, job, user, expiry, file kind,
  16-byte nonce) plus a 32-byte HMAC, base64url: 83 characters, no dot. The
  key is HMAC-SHA256 of a fixed label under the auth, secure auth and logged
  in salts plus a 64-hex-character per-site secret.
- **Validation file** has its own link (file kind in the token).
- **Canary answers.** Status 200 with the canary content is `served`. Only a
  4xx answer is `safe`. A redirect (it may lead to the file over another
  scheme or host), a server error, a 200 without the canary, or a network
  error is `unknown`, which keeps the warning and lets work continue
  (failure table). The canary URL comes from the deepest web root that
  holds the directory.
- **Name sources.** Switching any name source off, while name redaction is
  on, needs the sensitive capability like turning a redaction type off:
  with every source off, name redaction would be off by another route.
- **Deleting files** is allowed only for a job in a final state
  (complete, cancelled, failed). A stalled job is cancelled first, since a
  resume would write into the removed file.
- **Canary served mid-job** fails the job with `directory_unsafe`; the
  operator reruns it after fixing the server.
- **Count and preview** need no directory: they write nothing.
- **Deleting a job record** is allowed only after its files are gone, so no
  file becomes "unknown". Changing another user's job needs the sensitive
  capability.
- **WP-CLI without `--user`** is trusted like the shell it runs in; with
  `--user`, capabilities apply. `link` always needs a user with the run
  capability.
- **Callbacks receive `PostData`**, not `WP_Post`; `$post->id` gives the id.
- **Required extension `dom`** for cleaning; it is on in standard PHP builds.
- **Uninstall** also removes the capabilities and scheduled events; it never
  touches files.

## Testing

PHPUnit 10 runs against `tests/stubs/` (functions, classes, a small `wpdb`
that understands the plugin's queries). Every case in PRD section 12 maps to
a test; the file removal guarantee (case 94) is also checked by scanning the
source. PHPStan runs at level 8 with the WordPress stubs.

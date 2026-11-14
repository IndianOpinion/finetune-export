# finetune-export: product requirements

Status: draft for review. Audience: the builder, the code reviewer and anyone
deciding whether to install the plugin. This document describes behaviour,
not code. Where it says "must", an acceptance test in section 12 checks it.
Names of classes, hooks, options and commands here are proposals for the
public API of this plugin; the builder may refine them and must record any
change in `docs/ARCHITECTURE.md`.

Written from scratch; not derived from any third-party plugin.

## 1. Problem

A site with years of its own writing is a natural fine-tuning set for a
house model. Getting that writing out of WordPress in a usable shape is
harder than it looks, and the easy ways are unsafe:

- A raw database dump or the core XML export carries drafts, private posts,
  password-protected posts, revisions, author email addresses and comment
  data. None of that belongs in a training file.
- Post bodies hold block markup, shortcodes, scripts and embed code. A model
  trained on that learns markup, not prose.
- Posts name people and quote addresses, phone numbers and email addresses.
  A model can memorise and repeat them. Fine-tuning providers also keep
  uploaded files for a time, so personal data leaves the site's control.
- Nobody knows the size of the set until it is uploaded and billed. Records
  that exceed a provider's context limit fail late or are silently cut.
- The usual place a plugin writes a file is the uploads directory. That
  directory is public by design, and on many sites it is copied to object
  storage or a CDN that serves it without ever asking the web server. A
  "private" export written there is public to anyone who has the URL.

The last point is measured, not theoretical: on the site this plugin came
from, personal-data export archives written by WordPress core to the uploads
tree were reachable by direct URL through the media mirror, which bypassed
every web server rule. Moving them to a protected directory with a signed-in
download route closed it.

finetune-export turns published posts into JSONL records in a chosen shape,
redacts names and addresses by default, shows a sample before anything is
written, estimates the token count locally, and writes the file to a
protected directory that is reached only through an expiring, signed-in
download link.

## 2. Goals and non-goals

Goals:

1. Export posts as JSONL in three shapes: chat messages, prompt and
   completion, and plain text (section 4.5).
2. Let the operator choose which fields go into each record and how they map
   into the shape, with generic templates and no built-in editorial rules.
3. Select published posts only by default. Other statuses need an explicit
   setting and a stronger capability.
4. Redact personal data (names, email addresses, phone numbers, postal
   addresses, IP addresses) by default, best-effort, with a per-type switch,
   an allowlist and a report of counts.
5. Show a sample preview of the first records, after cleaning and redaction,
   without writing any file.
6. Estimate tokens locally per record and for the whole export, with no
   network call and no model call of any kind.
7. Write export files to a protected directory outside the public web root,
   fail closed when no safe directory exists, and serve them only through an
   expiring download link bound to the signed-in user who asked for it.
8. Remove export files only when the operator's retention setting says so, or
   when the operator deletes one by hand, and record every removal.
9. Run large exports in bounded batches in the background, resumable, with
   WP-CLI commands for everything the admin screen does.

Non-goals:

1. Calling any model, tokeniser service or fine-tuning API. The plugin never
   uploads anything anywhere. The operator takes the file to the provider.
2. Training, evaluating or hosting a model.
3. Exporting comments, users, orders, form entries or any data other than
   posts of the selected post types and the fields in section 4.3.
4. Guaranteed anonymisation. Redaction is pattern and list based and will
   miss things (section 8). It is not a substitute for a human review or for
   the operator's own data-protection assessment.
5. Named-entity recognition with a statistical model. Name redaction uses
   lists and patterns only.
6. Writing provider-specific envelopes beyond the three generic shapes. A
   filter lets a site reshape each record.
7. Integration with any third-party importer, SEO or page-builder plugin.
   Content is read through core functions only.
8. Byte-range or resumable downloads.

## 3. Users and setting

- **Who installs it:** the operator of a WordPress site, usually a technical
  editor or developer, preparing a training set once in a while. Not a daily
  tool.
- **Who downloads:** the same person. Only users with the plugin's capability
  (section 5.6) see the screen or receive links.
- **Who integrates:** a developer who adds a custom field, reshapes records
  through filters, plugs in a better token estimator or name source, or runs
  exports from WP-CLI in a script.
- **Where it runs:** inside WordPress, single site or multisite (per site), on
  shared hosting or a dedicated server, behind page caches and CDNs, with the
  uploads directory possibly mirrored to object storage. WP-Cron may be
  driven by page loads or by a system cron.
- **Scale:** designed for up to 200,000 posts and export files up to 2 GB.
  Above that the builder documents limits.

## 4. Behaviour

### 4.1 Definitions

- **Profile:** a saved set of export settings: selection, fields, shape,
  templates, redaction and limits. A site may keep several. One profile is
  the default.
- **Record:** one JSON object on one line of the output, built from one post.
- **Job:** one run of a profile that produces one export file. A job has an
  id, a state, counters, the profile snapshot it used and the file it wrote.
- **Export file:** the JSONL output of one job, optionally gzip-compressed,
  plus an optional validation split file from the same job.
- **Protected directory:** the directory the plugin writes export files to
  (section 4.8).
- **Download link:** a URL that carries a signed, expiring token for one file
  and one user (section 4.9).
- **Redaction placeholder:** the text that replaces a redacted span, for
  example `[NAME]`.
- **Token estimate:** the local estimate of model tokens in a record
  (section 4.7).

### 4.2 Selecting posts

1. By default a job selects posts of type `post` with status `publish` only.
2. The operator may choose other public post types. Non-public post types are
   not offered.
3. Statuses other than `publish` (`private`, `draft`, `pending`, `future`)
   are offered only when the setting "allow unpublished content" is on. That
   setting is off by default, needs the stronger capability (section 5.6),
   and the screen shows a warning while it is on.
4. Password-protected posts are always excluded unless the profile sets
   "include password-protected posts", which also needs the stronger
   capability. The default is excluded.
5. Revisions, autosaves, attachments, menu items and trashed posts are never
   selected, whatever the settings.
6. Posts can be narrowed by date range (published date, inclusive, site time
   zone), category and tag (include and exclude lists, by term id), author
   (include and exclude), and a minimum and maximum body length in
   characters after cleaning.
7. A post excluded by a filter in rule 6 is counted in the job's "skipped"
   counters with the reason (`too_short`, `too_long`, `excluded_term`,
   `excluded_author`, `out_of_range`), never silently dropped.
8. A site may add or remove posts through the selection filter
   (section 5.5). The filter receives query arguments, not SQL.
9. Posts are read in ascending post id order so a resumed job continues from
   the last id written (section 4.10).
10. Posts flagged by a per-post "exclude from training exports" switch in the
    editor (a post meta set through a checkbox in the document settings
    panel) are skipped with reason `opted_out`. The switch is shown only to
    users with the plugin's capability.
11. A limit on the number of records (default none) stops selection once
    reached.
12. Exact duplicate bodies (same hash after cleaning and redaction) are
    written once. Later copies are skipped with reason `duplicate`.

### 4.3 Fields

13. The fields available for a record are: post id, title, excerpt, body,
    published date (ISO 8601 with offset), modified date, permalink,
    categories (names), tags (names), author display name, featured image
    alternative text, and any post meta key on the operator's allowlist.
14. Default fields: title and body only.
15. Author display name is off by default. When it is on and name redaction
    is on, the author name is redacted like any other name, and the screen
    warns that the field will read `[NAME]`.
16. Post meta keys are allowlisted by exact key. Keys starting with an
    underscore are refused, since they are WordPress internal or private by
    convention. Only scalar values are exported; arrays and objects are
    skipped and counted under `meta_not_scalar`.
17. A field that is empty for a post is omitted from that record, not written
    as an empty string, unless the profile sets "keep empty fields".
18. Every text field passes through the same cleaning (section 4.4) and the
    same redaction (section 4.6) before it is placed in the record. Ids and
    dates are not redacted. Permalinks are redacted only by the URL rule in
    case 40.

### 4.4 Cleaning text

19. The body is rendered without running `the_content` filters from other
    plugins by default, because those filters add share buttons, ads, related
    posts and tracking markup. The profile may turn "apply content filters"
    on.
20. Block comments are removed. Dynamic blocks are dropped unless the profile
    turns "render dynamic blocks" on.
21. Shortcodes are stripped with their tags. The text between an enclosing
    shortcode's tags is kept.
22. `script`, `style`, `iframe`, `noscript`, `form`, `object`, `embed` and
    `svg` elements are removed with their content.
23. The output format of text fields is chosen per profile: `text` (default),
    `markdown` or `html`. `text` keeps paragraph breaks as a blank line and
    list items as lines starting with "- ". `markdown` keeps headings, lists,
    links, emphasis and block quotes. `html` keeps a small allowlist of tags
    (p, h2 to h6, ul, ol, li, blockquote, em, strong, a with href only) and
    strips every attribute except `href`.
24. HTML entities are decoded. Text is normalised to Unicode NFC when the
    `intl` extension is present; otherwise it is left as is and the job
    notes `nfc_unavailable` once.
25. Runs of whitespace are collapsed, non-breaking spaces become spaces, zero
    width characters and control characters other than newline and tab are
    removed. Leading and trailing whitespace is trimmed.
26. Invalid UTF-8 is repaired by replacing bad sequences with U+FFFD. A record
    whose body is more than 5 percent replacement characters is skipped with
    reason `bad_encoding`.
27. Images in the body are removed. Their alternative text is kept only when
    the profile turns "keep image alt text inline" on.

### 4.5 Record shapes and templates

28. Shape `chat`: each record is
    `{"messages":[{"role":"system","content":...},{"role":"user","content":...},{"role":"assistant","content":...}]}`.
    The system message is omitted when its template is empty.
29. Shape `prompt_completion`: each record is
    `{"prompt":...,"completion":...}`.
30. Shape `text`: each record is `{"text":...}`.
31. Each message or key is filled from a template. A template is plain text
    with placeholders in braces, one per field: `{title}`, `{excerpt}`,
    `{body}`, `{date}`, `{categories}`, `{tags}`, `{author}`, `{permalink}`,
    `{image_alt}` and `{meta:<key>}`.
32. Default templates: chat has an empty system message, the user message
    `Write an article with the headline: {title}` and the assistant message
    `{body}`. Prompt and completion use the same two. Text uses
    `{title}\n\n{body}`. These are generic placeholders, not house prompts;
    the operator is expected to replace them.
33. A placeholder for a field that is not selected, or not known, is an error
    when the profile is saved, with the placeholder named. A profile with
    such an error cannot run.
34. A placeholder whose field is empty for one post renders as an empty
    string. If that leaves the assistant message, the completion or the text
    empty, the record is skipped with reason `empty_target`.
35. When "include metadata" is on, each record gets a `metadata` object with
    post id, permalink and published date, for tracing a record back. It is
    off by default, because some providers reject unknown keys.
36. Records are encoded with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`
    and `JSON_THROW_ON_ERROR`, one per line, `\n` line endings, no trailing
    comma, no array wrapper. A record that fails to encode is skipped with
    reason `encode_error`, never written half-way.
37. A site can reshape or drop each record through the record filter
    (section 5.5). Returning `null` skips it with reason `filtered`.

### 4.6 Redaction

Redaction is on by default for every type below. It is best-effort: it is a
set of patterns and lists, it will miss some personal data and will
sometimes replace text that is not personal. The README and the screen say
so in those words.

38. **Email addresses** are replaced with `[EMAIL]`, including common
    obfuscations: `name at example dot com`, `name [at] example [dot] com`,
    `name(at)example.com`.
39. **Phone numbers** are replaced with `[PHONE]`: international numbers with
    a leading `+` and 8 to 15 digits, and national numbers of 10 to 11 digits
    with spaces, dots, hyphens or brackets as separators. Runs of digits that
    look like years (4 digits), dates, times, money amounts with a currency
    sign, percentages, or ISBN and DOI forms are not touched.
40. **URLs** carrying personal data are cut back: a `mailto:` or `tel:` link
    becomes the matching placeholder, and query strings on any URL are
    removed (they often hold emails, ids or tracking tokens). The scheme,
    host and path stay. This rule also applies to the permalink field.
41. **Postal addresses** are replaced with `[ADDRESS]` when they match a
    street pattern: a house number followed by one to five words and a street
    type word (Street, St, Road, Rd, Lane, Avenue, Marg, Nagar, Colony, and
    others in a filterable list), optionally followed by a locality and a
    postcode. **Postcodes** alone are replaced with `[POSTCODE]` for UK
    postcodes, six-digit postal index numbers when introduced by a postcode
    keyword (for example "PIN" or "Pincode"), and US ZIP codes when preceded
    by a two-letter state code. Bare five or six digit numbers are not
    touched.
42. **IP addresses** (IPv4 and IPv6) are replaced with `[IP]`.
43. **Names** are replaced with `[NAME]`. Name redaction uses these sources,
    in this order, each a switch in the profile:
    a. the display name, first name, last name and nickname of every user on
       the site (on by default);
    b. names the operator lists, one per line (on, empty by default);
    c. a pattern for an honorific followed by one to three capitalised words,
       for example `Mr`, `Mrs`, `Ms`, `Dr`, `Prof`, `Shri`, `Smt` (the list is
       filterable) (on by default);
    d. names supplied by the name-source filter (section 5.5), for a site that
       has its own register of people.
44. Names of two or more words are matched as whole phrases, case-sensitively,
    on Unicode word boundaries, longest first. "Asha Rao" is replaced as one
    `[NAME]`, not two.
45. A single-word name is matched only when it is at least 4 letters, starts
    with a capital, is not the first word of a sentence, and is not on the
    common-word stoplist (shipped, filterable; for example "May", "Will",
    "Grace", "Mark", "Bill"). This trades recall for fewer false hits; the
    operator list in 43b is exempt from these limits.
46. An allowlist of names and phrases to keep (public figures, the site's own
    name, place names that look like names) is applied before any name rule.
    An allowlisted phrase is never replaced, even inside a longer match.
47. Each placeholder text is configurable per type. The default placeholders
    are `[NAME]`, `[EMAIL]`, `[PHONE]`, `[ADDRESS]`, `[POSTCODE]` and `[IP]`.
    The option "consistent pseudonyms" replaces names with `[NAME_1]`,
    `[NAME_2]` and so on, numbered per record, so a model still sees that two
    mentions are the same person. It is off by default.
48. Redaction runs on cleaned text, after the template is filled, so the
    title inside a prompt and the title field are treated the same way.
49. The order of rules is fixed: URLs, emails, IPs, phones, addresses and
    postcodes, then names. Earlier placeholders are never matched by later
    rules.
50. Every redaction type has its own switch. Turning any of them off needs the
    stronger capability and shows a warning on the screen and in the job
    record.
51. The job keeps a redaction report: counts per type for the whole job and
    the ten records with the most replacements (by post id). It never stores
    the text that was removed.
52. A site may add its own rules or replace a rule through the redaction
    filter (section 5.5). A rule that throws is disabled for the rest of the
    job, logged once, and the job is marked `redaction_degraded`; the job
    carries on with the other rules. It does not fail open silently: the
    screen shows the degraded state on that job and on the download row.
53. A regular expression that fails (backtrack or recursion limit) on one
    field causes that record to be skipped with reason `redaction_error`,
    never written unredacted.

### 4.7 Token estimate

54. The estimate is local and deterministic. It makes no network call and
    loads no model or tokeniser file.
55. The default estimator counts, over the final JSON text of the record's
    string values (not keys or punctuation of the envelope):
    a. each run of ASCII letters and digits as `ceil(length / 4)` tokens;
    b. each ASCII punctuation or symbol character as 1 token;
    c. whitespace as 0 tokens;
    d. each other Unicode code point as 1 token for Han, Hiragana, Katakana
       and Hangul, and `ceil(utf8_bytes / 2)` tokens per code point for every
       other script (Devanagari, Bengali, Tamil, Arabic, Cyrillic and so on),
       because tokenisers split these scripts finely.
    It then adds 4 tokens per message for the chat shape and 3 per record
    otherwise, for role and separator overhead.
56. The estimate is labelled as an estimate everywhere it is shown. The
    screen says it can be off by 25 percent or more, most for non-Latin
    scripts.
57. A site may plug in its own estimator through the estimator filter
    (section 5.5), for example a local tokeniser library. The plugin never
    downloads one.
58. The job reports: total records, total estimated tokens, mean, median,
    95th percentile and maximum per record, and a histogram in ten buckets.
59. A maximum tokens per record (default none) is applied with a policy:
    `skip` (default, reason `over_token_limit`) or `truncate`, which cuts the
    target text (assistant message, completion or text) at the last paragraph
    break that fits and appends nothing. Truncated records are counted.
60. The estimate is computed after redaction, so it matches what is written.

### 4.8 Protected directory

61. The export directory is chosen in this order:
    a. the path in the constant `FINETUNE_EXPORT_DIR`, if defined;
    b. the path in the directory filter (section 5.5);
    c. a directory named `finetune-exports` next to the WordPress root, one
       level above `ABSPATH`, if its parent is writable.
62. A candidate is refused if it is inside the uploads base directory, inside
    `WP_CONTENT_DIR`'s public subtrees, or inside `ABSPATH`, unless the
    operator has ticked "I understand this directory is inside the web root
    and I have blocked it in the web server". That acknowledgement is stored
    with the resolved path and is void when the path changes.
63. A path inside the uploads base directory is refused always, even with the
    acknowledgement, because uploads are commonly mirrored to object storage
    and served without the web server.
64. If no candidate is acceptable, the plugin refuses to run any job and the
    screen says why and how to set `FINETUNE_EXPORT_DIR`. It never falls back
    to any other directory. This is fail closed.
65. The plugin creates the directory with mode 0700 if missing, and writes
    `index.php` (empty), `.htaccess` (deny all) and `web.config` (deny all)
    into it as a second line of defence, even outside the web root.
66. Every export file is created with mode 0600 (set through the umask before
    creation, then checked; a mismatch is logged).
67. File names are `export-<job id>-<random>.jsonl` (or `.jsonl.gz`), where
    the random part is 32 characters from a secure random source. The name
    never contains the site name, a user name or a post title.
68. When the directory is inside the web root (acknowledged case), the plugin
    runs a self-check on save and once a day: it writes a canary file with a
    random name and requests it over HTTP through the site's own URL. If the
    canary is served (status 200 and the canary content), the plugin stops
    all downloads and jobs, shows an admin notice to holders of the
    capability, and logs at error level, until the check passes. The canary
    is removed after each check. A network error during the check is reported
    as "unknown", not as safe, and keeps the warning on screen.

### 4.9 Download links

69. A download link is issued for one file to one user, from the admin screen
    or WP-CLI. Its default lifetime is 60 minutes, configurable from 5
    minutes to 7 days.
70. The link has the form `<rest base>/finetune-export/v1/download/<token>`.
    It never ends in `.jsonl`, `.gz` or any file extension, because many web
    servers answer static extensions themselves before PHP runs.
71. The token is a signed value carrying the job id, the user id, the expiry
    and a random nonce, signed with HMAC-SHA256 under a key derived from the
    site's authentication salts and a per-site secret the plugin generates on
    activation. Signature checks use a constant-time comparison.
72. Issuing a link stores its nonce with the job, so a link can be revoked.
    "Revoke all links" on a job invalidates every link for that file.
73. A download is a plain navigation, so it carries no REST nonce. The route
    validates the logged-in cookie itself, including its session token,
    when the REST layer reports no user.
74. Request handling order: malformed token, then 404. Request with an
    `Origin` header that is not the site's own origin, then 404. No signed-in
    user, then 401, before any lookup. Bad signature, wrong user, revoked
    nonce, unknown job, or file missing, then 404, with the same body, so a
    valid token cannot be told apart from an invalid one. Valid signature for
    this user but expired, then 410 with the message "This link has expired.
    Create a new one on the export screen." User without the capability at
    the time of download, then 404.
75. A successful download streams the file with: `Content-Type:
    application/x-ndjson` (or `application/gzip`),
    `Content-Disposition: attachment; filename="finetune-export-<date>.jsonl"`
    (generic name), `Content-Length`, `Cache-Control: private, no-store,
    max-age=0`, `X-Accel-Expires: 0`, `X-Content-Type-Options: nosniff`,
    `X-Robots-Tag: noindex, nofollow` and `Referrer-Policy: no-referrer`. Any
    CORS or `Link` header added earlier in the request is removed. Output
    buffers are cleared and the file is streamed in chunks, never read whole
    into memory. The request ends inside the handler, before the REST layer
    serialises a response.
76. `HEAD` returns the same headers and no body.
77. Every download, success or refusal past the 401 stage, is recorded with
    time, user id, job id and outcome. The record holds no IP address unless
    the site enables it through a filter.
78. A link is never sent by email by default. The option "email me the link"
    exists, off by default, and sends only to the requesting user's own
    address.

### 4.10 Jobs

79. A job starts from the screen ("Run export") or WP-CLI. It snapshots the
    profile, so later edits do not change a running job.
80. A job runs in batches of 100 posts by default (filterable), each batch in
    its own WP-Cron event or WP-CLI loop step, with a time budget of 20
    seconds per batch in web context. It writes to a `.part` file and renames
    it on completion, so a partial file is never offered for download.
81. A job records the last post id written after each batch. If a batch dies
    (fatal error, timeout), the next run of the scheduler resumes from that
    id. Records already written are not duplicated: the `.part` file is
    truncated back to the byte offset recorded with the last good batch.
82. Only one job runs at a time per site, guarded by a lock with an expiry.
    A second request is queued, not run in parallel.
83. A job can be cancelled. Cancelling stops after the current batch and
    keeps the `.part` file until the operator deletes it or retention removes
    it; it is listed as `cancelled` and cannot be downloaded.
84. A job that has made no progress for 30 minutes is marked `stalled` and
    the screen offers "resume" and "cancel".
85. Job states: `queued`, `running`, `complete`, `cancelled`, `stalled`,
    `failed`. A `failed` job keeps its error message and counters.
86. When "validation split" is set to a percentage (default 0, off), records
    are assigned to the training or validation file by a hash of the post id
    and a per-job seed, so the split is stable across re-runs with the same
    seed and needs no second pass.
87. When "compress" is on (off by default), files are written gzip-compressed
    as they are produced.
88. Disk space is checked before each batch. If free space falls below the
    larger of 100 MB and twice the size written so far, the job stops as
    `failed` with reason `disk_space` and keeps what it wrote.

### 4.11 Preview and dry run

89. "Preview" builds the first 5 records (configurable, at most 50) of the
    current, unsaved profile settings and shows them on screen. It writes no
    file and starts no job.
90. Each preview record shows the final JSON line, pretty-printed for
    reading, with every placeholder highlighted and a count of replacements
    per type. It does not show the original text of redacted spans.
91. The preview also shows, for those records, the token estimate of each
    and the skip reasons of any posts passed over to reach them.
92. "Count" runs selection, cleaning, redaction and estimation over the whole
    selection without writing records, and reports the job statistics of
    case 58 and the skip counters. On large sites it runs as a job of kind
    `count` with the same batching, and its result is kept with the profile.
93. Preview and count need the same capability as running an export.

### 4.12 Retention and removal

94. Export files are removed only by: (a) the retention setting, or (b) the
    operator pressing "Delete" on a job, or the WP-CLI delete command. Nothing
    else in the plugin removes a file.
95. The retention setting is a number of days, default 14, or "keep until I
    delete". It is shown on the screen next to every file, as "removed on
    <date>" or "kept until deleted".
96. A daily event removes files older than the retention period, counted from
    job completion. Each removal is recorded in the job history with the time
    and the reason `retention`. A failed removal is recorded and retried the
    next day, and the screen shows it.
97. Retention only touches files whose names match the plugin's own pattern
    and that are recorded against a job. Unknown files in the directory are
    listed on the screen as "not created by this plugin" and never removed.
98. A file that disappears for any other reason is shown as "missing, not
    removed by this plugin" with the time it was noticed. The job record is
    kept.
99. Removing a file revokes every link to it.
100. Job records (settings snapshot, counters, report, history) are kept after
     the file is gone, for audit. They hold no post text. The operator can
     delete job records explicitly.
101. Deactivation removes scheduled events and nothing else.
102. Uninstall removes options, the job table and the per-post opt-out meta.
     It removes export files only when the setting "delete export files on
     uninstall" is on (off by default). Otherwise it leaves them, and the
     uninstall notice in the README and readme.txt says where they are.

### 4.13 Failure modes

| Failure | What happens | Open or closed |
|---|---|---|
| No acceptable directory | No jobs, no downloads, notice on screen | Closed: never write into a public path |
| Canary served over HTTP | Jobs and downloads stop, error notice | Closed |
| Canary check network error | Shown as "unknown", warning stays | Closed for trust, open for work: jobs continue, warning stays |
| Redaction rule throws | Rule disabled for the job, job marked degraded | Visible degradation, never silent |
| Regex limit hit on a field | Record skipped (`redaction_error`) | Closed: never write unredacted |
| Record fails to encode | Record skipped (`encode_error`) | Closed per record |
| Batch dies | Resume from last good offset | Recovers |
| Disk low | Job fails with `disk_space` | Closed |
| Lock held by a dead job | Lock expires after 30 minutes, job marked stalled | Recovers |
| Signed-in user loses capability | Download refused (404) | Closed |
| Salts rotated | All existing links become invalid (404) | Closed |
| Estimator filter throws | Default estimator used, logged once | Open: estimates are advisory |

## 5. Public interface

Namespace `IndianOpinion\FinetuneExport`. Hook prefix `finetune_export_`.
All names here are proposals.

### 5.1 Classes

- `Exporter`: runs a profile over a selection. Methods: `preview(Profile,
  int $limit): PreviewResult`, `count(Profile): Stats`, `start(Profile):
  Job`, `step(Job): Job`, `cancel(Job): Job`.
- `Profile`: immutable value object. Built from an array with `fromArray()`,
  validated on construction; throws `InvalidProfile` naming the field.
- `Redactor`: `redact(string $text, RedactionContext $ctx): RedactionResult`,
  where the result holds the new text and counts per type. Rules implement
  `RedactionRule` with `type(): string` and `apply(string, RedactionContext):
  RedactionResult`.
- `TokenEstimator` interface with `estimate(array $record, string $shape):
  int`. Default implementation `HeuristicEstimator` (case 55).
- `Cleaner`: `clean(string $html, string $format, CleanOptions): string`.
- `Storage`: resolves and checks the protected directory, creates files,
  removes files, reports unknown and missing files.
- `LinkSigner`: `issue(int $jobId, int $userId, int $ttl): string`,
  `verify(string $token, int $userId): LinkCheck`.
- Exceptions: `InvalidProfile`, `UnsafeDirectory`, `JobLocked`,
  `ExportFailed`, all extending `FinetuneExportException`.

### 5.2 Admin screen

Tools > Fine-tune export. Tabs: Profiles, Preview, Jobs, Settings. Every form
uses a nonce and a capability check. The per-post opt-out checkbox lives in
the block editor document panel and the classic editor publish box.

### 5.3 REST routes

- `GET|HEAD /finetune-export/v1/download/<token>`: case 74 and 75.
- `POST /finetune-export/v1/preview`: body is a profile array; returns preview
  records. Requires capability and a REST nonce.
- `GET /finetune-export/v1/jobs` and `GET /finetune-export/v1/jobs/<id>`:
  status, counters and stats. Never file contents.

### 5.4 Actions fired

- `finetune_export_job_started`, `finetune_export_job_completed`,
  `finetune_export_job_failed` (job id, counters).
- `finetune_export_file_removed` (job id, reason: `retention` or `operator`).
- `finetune_export_downloaded` (job id, user id, outcome).

### 5.5 Filters

| Filter | Receives | Default |
|---|---|---|
| `finetune_export_query_args` | query arguments, profile | case 1 to 6 |
| `finetune_export_fields` | field map for one post, post, profile | case 13 |
| `finetune_export_clean_text` | cleaned text, field name, post | unchanged |
| `finetune_export_redaction_rules` | list of rules, profile | case 38 to 43 |
| `finetune_export_names` | list of names, profile | empty |
| `finetune_export_name_stoplist` | list of words | shipped list |
| `finetune_export_honorifics` | list of words | shipped list |
| `finetune_export_street_types` | list of words | shipped list |
| `finetune_export_record` | record array or null, post, profile | unchanged |
| `finetune_export_token_estimator` | `TokenEstimator` | `HeuristicEstimator` |
| `finetune_export_directory` | path or null | null |
| `finetune_export_batch_size` | int | 100 |
| `finetune_export_link_ttl_max` | seconds | 604800 |
| `finetune_export_log_download_ip` | bool | false |

### 5.6 Capabilities

- `finetune_export_run`: preview, count, run, download, delete own jobs.
  Granted to administrators on activation.
- `finetune_export_sensitive`: include unpublished or password-protected
  posts, turn off any redaction type, change the directory acknowledgement.
  Granted to administrators on activation; on multisite, super admins only.

### 5.7 WP-CLI

- `wp finetune-export profiles list|show <id>|import <file>|export <id>`
- `wp finetune-export preview [--profile=<id>] [--limit=5]`
- `wp finetune-export count [--profile=<id>]`
- `wp finetune-export run [--profile=<id>] [--foreground]`: foreground runs
  every batch in the current process.
- `wp finetune-export jobs list|show <id>|cancel <id>|resume <id>`
- `wp finetune-export link <job id> [--user=<id>] [--ttl=<minutes>]`
- `wp finetune-export delete <job id> [--yes]`
- `wp finetune-export check-directory`

`link` refuses `--user` for a user without `finetune_export_run`. No command
prints file contents to standard output.

## 6. Data

### 6.1 What is stored

- Options: profiles, settings, the per-site link secret (autoload off), the
  directory acknowledgement with its path, the last canary result.
- One custom table for jobs: id, kind (`export` or `count`), state, profile
  snapshot (JSON), created by, timestamps, last post id, byte offset,
  counters, stats, redaction report, file name, file size, link nonces,
  history (removals, downloads, errors).
- Post meta: the per-post opt-out flag.
- Files: export files in the protected directory.

No post text is stored anywhere except in export files and, transiently, in
memory during a batch.

### 6.2 Retention

Files: section 4.12. Job records: kept until the operator deletes them.
Download history inside a job: last 200 entries per job.

### 6.3 Uninstall

Case 102. On multisite, uninstall runs per site.

## 7. Configuration and sensible defaults

| Setting | Default | Note |
|---|---|---|
| Post types | `post` | public types only |
| Statuses | `publish` | others need `finetune_export_sensitive` |
| Password-protected | excluded | |
| Fields | title, body | |
| Text format | `text` | |
| Shape | `chat` | |
| Templates | case 32 | generic |
| Include metadata | off | |
| Redaction, every type | on | turning off needs `finetune_export_sensitive` |
| Name sources | users, operator list, honorifics | |
| Consistent pseudonyms | off | |
| Max tokens per record | none | |
| Over-limit policy | `skip` | |
| Validation split | 0 | |
| Compress | off | |
| Batch size | 100 | filter |
| Link lifetime | 60 minutes | 5 minutes to 7 days |
| Email me the link | off | |
| Retention | 14 days | or "keep until I delete" |
| Delete files on uninstall | off | |
| Preview size | 5 | at most 50 |

Settings may also be set by constants named `FINETUNE_EXPORT_<SETTING>` in
`wp-config.php`; a constant wins and the field is shown read-only.

## 8. Security and privacy

- The operator is responsible for the export under data-protection law. The
  plugin helps but does not decide whether processing is lawful, whether a
  data protection impact assessment is needed, or whether a provider's terms
  are acceptable. The README must say, in its first section: "Redaction is
  best-effort. It will miss some personal data. The export, and what you do
  with it, is your responsibility under the data-protection law that applies
  to you." The screen shows the same text above the Run button. The README
  also carries, in its first section, the line "Written from scratch; not
  derived from any third-party plugin."
- No outbound network call is made, except the self-check of case 68, which
  requests the site's own URL. No model is called, no file is uploaded.
- Every admin action checks a capability and a nonce. REST routes other than
  download need a REST nonce. Every output is escaped.
- The download route follows case 74 and 75. Responses are never cacheable.
  Only a signed-in request can get a 200, so page caches that bypass on the
  logged-in cookie never store a file.
- Tokens are signed and expiring; secrets are compared in constant time.
- Files are 0600 in a 0700 directory, outside the web root by default.
- Logs never contain post text, redacted spans or tokens; links are logged
  with the token cut to its first 6 characters.
- No `eval`, no shell commands, no `@` error suppression; file operations
  check their results and raise typed errors.
- The per-post opt-out is honoured even when the post is selected by id.

## 9. Performance budget

- Preview of 5 records: under 2 seconds on a site with 50,000 posts.
- A batch of 100 average posts (1,000 words each) with all redaction on:
  under 5 seconds and under 64 MB peak memory over the WordPress baseline.
- Memory does not grow with the number of posts: posts are loaded per batch
  with caches cleared between batches, and output is appended, not built in
  memory.
- The user list for name redaction is loaded once per job, compiled into
  one matcher, and capped at 50,000 names; above that the job notes
  `name_list_truncated` and the operator is told.
- Download streaming uses chunks of 1 MB or less.
- No work runs on front-end page loads except the WP-Cron spawn WordPress
  already does.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later. Works as a normal plugin or a
  must-use plugin (with a loader stub).
- Extensions: `json` and `mbstring` required; `intl` optional (case 24);
  `zlib` required only for compression.
- Web servers: Apache, nginx, LiteSpeed, IIS. The download route works with
  plain and pretty permalinks.
- Page caches and CDNs: the download response is private and no-store; the
  route is only answered with 200 to logged-in requests.
- Uploads mirrored to object storage: unaffected, since nothing is written to
  uploads (case 63).
- Multisite: per site; the directory gets a subdirectory per site id.
- Classic and block editors both show the opt-out switch.

## 11. Observability

- The Jobs tab lists each job with state, progress (posts done of total),
  counters, skip reasons, redaction report, token stats, file size, the
  retention date and the download history.
- A banner shows the directory state: path, inside or outside web root,
  writable, last canary result.
- Log lines go through `error_log` with the prefix `[finetune-export]` at
  levels info, warning and error, filterable to a site's own logger. One line
  per job start, completion, failure, removal, refusal past 401, and
  degradation. No post text.
- Site Health gets one test: directory safe, last canary result, and
  whether retention is off.
- WP-CLI `jobs show` prints the same data as the screen.

## 12. Acceptance tests

Each test names the cases it covers. Tests run under PHPUnit with a stub
layer for WordPress.

1. (1) A default job over posts in every status exports only published posts.
2. (2) Only public post types are offered.
3. (3) Statuses other than publish are refused without the setting and the
   sensitive capability, and accepted with both.
4. (4) A password-protected post is excluded by default and included only
   with the setting and capability.
5. (5) Revisions, autosaves, attachments and trashed posts never appear.
6. (6) Date, term, author and length filters select the expected posts; the
   date range is inclusive in site time.
7. (7) Each filter exclusion increments its own skip reason.
8. (8) The query-args filter can add and remove posts.
9. (9) Posts are processed in ascending id order.
10. (10) An opted-out post is skipped with `opted_out`.
11. (11) A limit of 3 yields exactly 3 records.
12. (12) Two posts with identical cleaned bodies yield one record and one
    `duplicate` skip.
13. (13, 14) Default records hold title and body only; every listed field can
    be selected.
14. (15) With author on and name redaction on, the author field reads `[NAME]`
    and the screen shows the warning.
15. (16) A meta key starting with `_` is refused at save; an array value is
    skipped and counted.
16. (17) Empty fields are omitted unless "keep empty fields" is on.
17. (18) A name in the title and a name in a meta value are both redacted;
    dates are not.
18. (19) A `the_content` filter adding a share button has no effect by
    default and applies when the option is on.
19. (20, 21) Block comments and shortcodes are removed; enclosed shortcode
    text is kept.
20. (22) Script, style, iframe and the other listed elements vanish with
    their content.
21. (23) The same post produces the expected `text`, `markdown` and `html`
    outputs; `html` keeps only allowlisted tags and `href`.
22. (24) Entities are decoded; NFC applied when `intl` exists; the note is
    recorded once otherwise.
23. (25) Whitespace, zero-width and control characters are normalised.
24. (26) Invalid UTF-8 is repaired; a body over 5 percent U+FFFD is skipped.
25. (27) Images are removed; alt text kept inline only with the option.
26. (28, 29, 30) Each shape produces the exact JSON structure; an empty system
    template omits the system message.
27. (31, 32) Default templates render the expected strings.
28. (33) A placeholder for an unselected field fails validation and names it.
29. (34) A post with an empty body under the default template is skipped with
    `empty_target`.
30. (35) Metadata appears only when enabled.
31. (36) Output is one valid JSON object per line, Unicode unescaped, `\n`
    endings; an unencodable record is skipped, not half-written.
32. (37) The record filter can reshape a record and returning null skips it.
33. (38) Plain and obfuscated email forms become `[EMAIL]`.
34. (39) International and national phone numbers become `[PHONE]`; years,
    dates, times, money, percentages, ISBN and DOI stay unchanged.
35. (40) mailto and tel links become placeholders; query strings are removed
    from URLs and from the permalink.
36. (41) Street addresses become `[ADDRESS]`; UK postcodes, keyword-led PIN
    codes and state-led ZIP codes become `[POSTCODE]`; bare numbers stay.
37. (42) IPv4 and IPv6 addresses become `[IP]`.
38. (43) Each name source, switched alone, redacts its names; the name-source
    filter adds names.
39. (44) A two-word name is replaced as one placeholder, longest match wins.
40. (45) Single-word rules: short, lowercase, sentence-initial and stoplist
    words are kept; operator-listed single names are always redacted.
41. (46) An allowlisted phrase survives even inside a longer candidate match.
42. (47) Custom placeholders apply; consistent pseudonyms number names per
    record.
43. (48) Redaction applies to the filled template.
44. (49) Rule order holds: an email inside a URL becomes `[EMAIL]` once and
    placeholders are not re-matched.
45. (50) Turning off a type needs the sensitive capability and records a
    warning on the job.
46. (51) The redaction report has correct counts and contains no removed
    text.
47. (52) A throwing custom rule is disabled, the job is marked degraded and
    the other rules still apply.
48. (53) A pathological input that hits the regex limit skips the record
    with `redaction_error`; no unredacted text is written.
49. (54) The estimator makes no HTTP request (the HTTP API stub fails the
    test on any call).
50. (55) Known strings in English, Hindi, Japanese and mixed text give the
    exact documented counts; chat adds per-message overhead.
51. (56) The screen and CLI label the figure as an estimate.
52. (57) A custom estimator from the filter is used; a throwing one falls
    back to the default and logs once.
53. (58) Stats (mean, median, p95, max, histogram) are correct for a known
    set.
54. (59) Over-limit records are skipped by default; truncate cuts at a
    paragraph break and counts the record.
55. (60) The estimate of a redacted record equals the estimate of its written
    line.
56. (61) Directory resolution follows constant, filter, default in that
    order.
57. (62) A path inside ABSPATH is refused without acknowledgement and
    accepted with it; changing the path voids it.
58. (63) A path inside uploads is refused even with acknowledgement.
59. (64) With no acceptable directory, run, preview-to-file and download all
    refuse with the documented message and nothing is written anywhere.
60. (65, 66) The directory is 0700 with deny files; each export file is 0600.
61. (67) File names match the pattern, carry 32 random characters and no
    site, user or title text.
62. (68) A served canary stops jobs and downloads; a network error reports
    "unknown"; the canary is removed after the check.
63. (69) Link lifetime defaults to 60 minutes and is clamped to its range.
64. (70) The link has no file extension.
65. (71) A tampered token fails; comparison is constant time (the code uses
    `hash_equals`).
66. (72) Revoking links on a job invalidates an issued link.
67. (73) A request with only the logged-in cookie, no REST nonce, is
    authenticated.
68. (74) Each refusal path returns the documented status; the bodies of the
    404 cases are identical; anonymous gets 401 with no lookup performed;
    expired gets 410; a user who lost the capability gets 404.
69. (75) A successful download sends every listed header, no CORS or Link
    header, and streams without loading the file into memory.
70. (76) HEAD returns headers and an empty body.
71. (77) Downloads are recorded without IP by default, with IP when the
    filter allows it.
72. (78) No email is sent by default; with the option, only to the
    requester.
73. (79) Editing a profile during a job does not change the job's output.
74. (80) A job writes `.part` and renames on completion; a `.part` file
    cannot be downloaded.
75. (81) A job killed mid-batch resumes with no duplicated or missing
    records.
76. (82) A second job request is queued while one runs.
77. (83) A cancelled job stops after the current batch and cannot be
    downloaded.
78. (84) A job with no progress for 30 minutes is marked stalled.
79. (85) Every state transition is reachable and recorded.
80. (86) The validation split is stable for the same seed and close to the
    requested percentage.
81. (87) Compressed output decompresses to the same lines as uncompressed.
82. (88) Low free space fails the job with `disk_space` and keeps written
    output.
83. (89) Preview writes no file and creates no job.
84. (90) Preview highlights placeholders and never shows removed text.
85. (91) Preview shows per-record estimates and skip reasons.
86. (92) Count reports stats without writing an export file.
87. (93) Preview and count refuse users without the capability.
88. (94) No code path other than retention and operator delete removes a
    file (grep test over the source for file removal calls, plus behaviour
    tests).
89. (95) The screen shows the removal date or "kept until deleted".
90. (96) Retention removes only files past the period, records each removal,
    and retries a failed one the next day.
91. (97) An unknown file in the directory is listed and never removed.
92. (98) A file deleted outside the plugin shows as missing; the job record
    stays.
93. (99) Removing a file revokes its links.
94. (100) Job records survive file removal and contain no post text.
95. (101) Deactivation clears scheduled events only.
96. (102) Uninstall removes options, table and opt-out meta; files stay
    unless the setting is on.
97. (Failure table) Each row of section 4.13 has a test that reproduces the
    failure and asserts the outcome.

## 13. Open questions

To be answered by the builder in `docs/ARCHITECTURE.md`.

1. Default retention: 14 days, or "keep until I delete"? 14 days limits how
   long personal data sits on disk; "keep" matches the rule that files are
   never removed without an explicit operator choice. Record which, and why.
2. Should name redaction from site users include user logins, or only
   display, first, last and nickname? Logins are often not real names and
   cause false hits.
3. Should expired links answer 410 (helpful to the owner) or 404 like every
   other refusal (no information to anyone)? This PRD says 410 only after a
   valid signature for the same signed-in user; confirm or change.
4. How should the default estimator be calibrated? Pick a small public text
   set, measure against at least one public tokeniser offline during
   development, and record the error per script in ARCHITECTURE.md. No
   tokeniser ships with the plugin.
5. Should the directory default (case 61c) also try a path under the system
   temporary directory, given it is often cleared on reboot? This PRD says
   no.
6. Is a per-post opt-out meta enough, or should there also be a per-term
   opt-out (for example, a whole category marked "never train")?
7. Should the chat shape allow multi-turn records built from a post's
   sections (heading as user turn, section as assistant turn)? Out of scope
   for 0.1.0 unless cheap.
8. Should the screen offer a downloadable redaction report (counts only) as
   a separate CSV, for an operator's records of processing?

## 14. Lessons from production

Written as rules. They come from serving private export archives from a live
WordPress site.

1. Never write a private file under the uploads directory. Uploads are often
   mirrored to object storage or a CDN and served without the web server, so
   web server deny rules do not protect them.
2. Fail closed on the directory. If the safe directory is missing or not
   writable, refuse. Never fall back to a public path to "make it work".
3. Set restrictive permissions twice: a private directory, and private files
   inside it. Check the result instead of assuming it.
4. Never give a download URL a file extension. Static-file rules in the web
   server answer `.zip`, `.jsonl` and `.gz` paths before PHP runs, so the
   route returns a 404 that has nothing to do with your code.
5. A download link opened from an email or a redirect is a plain navigation
   with no REST nonce. Validate the logged-in cookie, including its session
   token, yourself, or every real user is treated as anonymous.
6. Answer 401 before any lookup, so anonymous callers learn nothing about
   which tokens exist.
7. Make "unknown", "not yours" and "file gone" indistinguishable: same
   status, same body.
8. Refuse any request whose `Origin` is not the site's own, and strip CORS
   headers. A cross-origin script with the user's cookie must never read an
   export.
9. End the request inside the handler, after streaming. If the REST layer
   serialises a response afterwards it adds headers you did not intend.
10. Send `Cache-Control: private, no-store` and the proxy-cache bypass
    header on every file response, and make sure only logged-in requests can
    get a 200, so no cache layer ever stores a file.
11. Put only the random part of a file name in a link, never a path, a user
    name or a site name. Validate its exact character set and length before
    using it to build a path.
12. A clean-up job must touch only what it created and recorded. Files it
    did not create, or files in an old location, are the owner's call, never
    the job's.
13. Record every removal and every download. "The file is gone and nobody
    knows why" is a privacy incident report you cannot write.

# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- Export of published posts as JSONL in three shapes: chat, prompt and completion, plain text.
- Profiles with selection (types, statuses, dates, terms, authors, lengths, limit), fields, templates and output format.
- Per-post and per-category opt-out switches.
- Cleaning of block markup, shortcodes, scripts and embeds into text, Markdown or a small HTML subset.
- Best-effort redaction of URLs, emails, IP addresses, phone numbers, postal addresses, postcodes and names, with placeholders, an allowlist, consistent pseudonyms and a counts-only report.
- Local token estimate with statistics, a token limit (skip or truncate) and an offline calibration script in `tools/`.
- Preview and count that write no file.
- Protected export directory that fails closed, 0700 with deny files, 0600 files, and a web root self-check.
- Signed, expiring, per-user download links on a REST route without file extensions.
- Batched, resumable background jobs with cancel, stall detection, validation split and gzip output.
- Retention setting (default: keep until deleted) and operator delete, each recorded in the job history.
- WP-CLI commands, REST routes, Site Health test.

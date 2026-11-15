# finetune-export

Exports posts as JSONL for fine-tuning with field selection, redaction of names and addresses, a sample preview and a token count.

Written from scratch; not derived from any third-party plugin.

Redaction is best-effort. It will miss some personal data. The export, and what you do with it, is your responsibility under the data-protection law that applies to you.

By default only published posts are exported and redaction is on. The plugin makes no model calls and uploads nothing. Files are written to a protected directory (the plugin refuses to run if none is safe) and reached only through an expiring link for a signed-in user. Files are removed only by the retention setting or by an explicit operator delete, and each removal is logged.

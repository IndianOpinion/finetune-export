# Examples

| File | What it shows | How to run |
|---|---|---|
| `redact-standalone.php` | Cleaning, redaction and the token estimate without WordPress | `php examples/redact-standalone.php` |
| `profile-chat.json` | A profile for the chat shape | `wp finetune-export profiles import examples/profile-chat.json` |
| `custom-estimator.php` | Plugging in your own local estimator | Copy into a must-use plugin |
| `custom-redaction-rule.php` | Adding a site-specific redaction rule | Copy into a must-use plugin |
| `names-filter.php` | Feeding names from your own register, adding a street type | Copy into a must-use plugin |
| `mu-loader.php` | Loading the plugin as a must-use plugin | See the main README |
| `calibration/` | Input files for `tools/calibrate.php` | `php tools/calibrate.php --corpus=examples/calibration/texts.jsonl --tokeniser=examples/calibration/word-tokeniser.php` |

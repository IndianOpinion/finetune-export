# Contributing

Thank you for helping. This plugin handles personal data, so changes are
reviewed with care.

## Setup

```sh
git clone https://github.com/IndianOpinion/finetune-export.git
cd finetune-export
composer install
```

PHP 8.1 or later with `dom`, `json` and `mbstring`. `intl` and `zlib` are
optional; some tests skip parts without them.

## Tests and checks

```sh
composer test   # PHPUnit, against the stub layer in tests/stubs (no WordPress needed)
composer stan   # PHPStan, level 8
composer cs     # PHPCS, PSR-12
composer check  # all three
```

CI runs the same on PHP 8.1, 8.2 and 8.3. Write code that works on 8.1: no
readonly classes, no DNF types, no constants in traits.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file.
- Typed exceptions; never `@` error suppression; no `eval`; no shell calls.
- Escape every output on admin screens; nonce and capability on every action.
- No em dashes in code, docs or messages.
- Logs never contain post text, redacted spans or whole tokens.

## Pull requests

- One change per pull request, with a test for new behaviour.
- Update `README.md` and `CHANGELOG.md` when behaviour or options change.
- Test fixtures use invented names, addresses and numbers only.
- A DCO sign-off is not required.

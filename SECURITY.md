# Security policy

## Reporting a problem

Write to **hello@indianopinion.org** with "finetune-export security" in the
subject. Please do not open a public issue for a security problem.

Include the plugin version, what you did, what happened and, if you can, a
minimal way to reproduce it. Do not send real export files or personal data.

## What to expect

- We acknowledge a report within 3 working days.
- We send a first assessment within 10 working days.
- We aim to release a fix within 30 days of confirming a problem, and credit
  you in the CHANGELOG unless you ask us not to.

## Supported versions

Only the latest 0.x release gets security fixes.

## Areas we care about most

- Export files reachable without the signed, expiring download link.
- The download route answering anything but 404 to a link that is not yours.
- Personal data surviving redaction in a case the README says is covered.
- Any network call other than the web root self-check.

# Security Policy

## Supported versions

Only the latest release of `master` is actively maintained and receives security
fixes. There are no separate LTS branches — apply fixes by updating to the
latest commit/tag.

## Reporting a vulnerability

This tool processes **chat exports you trust** (your own Telegram exports) and
writes output only to the local `var/` folder. Still, if you find a security
issue — e.g. in HTML output escaping, path handling, or dependency advisories —
please report it privately instead of opening a public issue:

- Email: **molodtsov.sasha@gmail.com**
- Telegram: **[@okopok](https://t.me/okopok)**

Please include:

- affected version (commit hash or tag);
- steps to reproduce;
- impact and, if known, a suggested fix.

You will receive a response as soon as possible (usually within a few days).
Please do not share the details publicly until the issue is resolved.

## What to expect

- `composer audit` / `composer update` is the recommended way to track
  dependency advisories.
- Reports are handled by the maintainer; credited acknowledgments can be added
  in the changelog if you wish.
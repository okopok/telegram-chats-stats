# Contributing to Telegram Chat Stats

Thanks for considering contributing! This is a small personal tool, but good habits
make it better for everyone. Please take a moment to read this document.

## Code of Conduct

By participating in this project you agree to abide by the
[Code of Conduct](CODE_OF_CONDUCT.md).

## How to report bugs

Open an [issue](https://github.com/okopok/telegram-chats-stats/issues/new)
and include:

- the command you ran (with options);
- the PHP version (`php -v`);
- expected vs actual behavior;
- if possible — a minimal export folder that reproduces the problem (or a
  description of its structure: export generation, markup type old/new).

## Feature requests

Open an issue with a clear description of the statistic/feature you want and
where the data would come from in the export markup. Good ideas so far: anything
Telegram stores in the HTML export but the tool does not show yet.

## Development workflow

1. Fork the repository and create a branch: `git checkout -b feature/my-feature`.
2. Make your changes. Keep the project's conventions:
   - comments, handler descriptions, templates and docs are **in Russian**
     (except the user-facing README/usage, which are bilingual);
   - handlers are stateless pure functions: `key()`, `description()`,
     `template()`, `handle(MessageCollection): array`;
   - new aggregations go into `MessageCollection::aggregate()` (single pass) —
     see `docs/development.md` (in Russian) for the full guide;
   - **documentation is part of the change**: update the relevant `docs/` sections.
3. Run a fast check: `php bin/chatstats generate <path-to-export> --key=check --limit=2000`
   (there are no automated tests; verification = generated HTML).
4. Commit with a clear message and open a pull request.

## Project layout (short version)

```
bin/chatstats                 — executable CLI entry point
src/Console/GenerateCommand   — CLI options, handler registry (order = panel order)
src/Messages/                 — export parser, chunked cache, single-pass aggregator
src/Stats/                    — statistic handlers (one panel each)
src/Entity/                   — plain DTOs (serialized natively for the cache)
src/templates/default/        — Twig templates (index + handlers/)
config/                       — user names, nicknames, stop-words
docs/                         — architecture, usage, development (RU + EN usage)
```

## Getting help

Ask questions in issues or in Telegram: [@okopok](https://t.me/okopok).
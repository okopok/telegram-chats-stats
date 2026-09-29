# Telegram Chat Stats

[![License: GPL-3.0](https://img.shields.io/badge/license-GPL--3.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.4-8892BF.svg)](composer.json)

Personal PHP CLI tool that parses **Telegram Desktop HTML chat exports** and generates
**one static HTML page of chat statistics** per chat. No framework, no web server,
no database, no tests required — just PHP and your export folder.

[Читать на русском](README.ru.md)

---

## Features

- One command, one static page: works offline, no tracking, no server.
- **27 collapsible statistic panels**:
  - totals (messages, characters, users, dates, reply matrix);
  - per-user breakdowns (messages, characters, average length, activity by weekday/hour);
  - media by type and user, popular words (with word cloud) and unique words;
  - **reactions** (top emojis, per type, reaction leaders, top reacted messages);
  - **edits**, **forwards** (by user and source), **top websites** from link previews;
  - **media weight** (documents size, voice/video duration), **polls**;
  - **reply speed** (median response time), **chronotypes** (night owl / morning lark);
  - **activity heatmap** (weekday × hour), **streak days**, **last activity**;
  - **self-replies**, **longest messages**.
- **Dashboard** with KPI cards and **fun facts** on top of the page.
- **Light/dark theme** toggle (saved in `localStorage`), responsive layout.
- Memory-friendly: messages are streamed chunk-by-chunk, peak memory does not
  depend on chat size (a 1.1M-message chat takes ~350 MB on first full run,
  repeat runs ~40 MB with result cache).
- Supports **two generations of Telegram Desktop export markup** (old and 2025+),
  including Russian dates like `11 декабря 2022, 22:51:24`.
- `--nicks` mode: show `@nickname` links from a manual mapping (exports don't contain usernames).

## Requirements

- PHP >= 8.4 (with `ext-mbstring`) **or** Docker (see below — no PHP on host needed)
- Composer (only for the non-Docker installation)

## Installation

```bash
git clone https://github.com/okopok/telegram-chats-stats.git
cd telegram-chats-stats
composer install
```

How to get the export: Telegram Desktop → open a chat → ⋮ → *Export chat history* → check
HTML, download the resulting folder.

## Usage with Docker (no local PHP required)

If you have Docker but don't want to install PHP and dependencies on your host:

```bash
# Build the image once
docker build -t chatstats .

# Generate the page (export folder is mounted read-only, output goes to ./var/html)
docker run --rm \
  -v /path/to/export:/export:ro \
  -v $(pwd)/var:/app/var \
  chatstats generate /export --key=my-chat
```

- The only required mount is the export folder (read-only into `/export`).
  Mount `./var` if you want the output HTML and caches to persist on the host;
  without it the result stays inside the container (`var/html/<key>.html`).
- All CLI options work as usual: `--nicks`, `--limit`, `--no-cache`, etc.
  (see the table below).
- `docker run --rm chatstats list` prints available commands and help.

## Usage

```bash
php bin/chatstats generate <export-folder> [options]
```

The only required argument is the folder with the exported chat. The result is written to
`var/html/<key>.html`.

### Options

| Option | Description |
|---|---|
| `<export-folder>` | Folder with the HTML chat export (required). Missing folder → error, **exit 1**. |
| `--key=<key>` | Output file name: `var/html/<key>.html`. Default: export folder name. |
| `--title=<title>` | Page title. Default: the key. |
| `--no-cache` | Do not read or write the message cache. |
| `--rebuild` | Force rebuild of the message cache. |
| `--limit=<N>` | Process only the first **N** messages — fast check runs on huge chats. Uses a separate cache (`-limit-N` suffix), the full cache is kept. Prints a warning that the run is a check. |
| `--nicks` | Show `@nickname` links instead of names (mapping: `config/nicks.php`, filled in manually — exports have no usernames). HTML parts get clickable links, canvas charts get nicknames without links. |
| `--debug` | Handler timings table (Stopwatch) + Twig without auto-reload. |

### Examples

```bash
# Simple run
php bin/chatstats generate ~/Downloads/ChatExport_2026-09-01

# Custom file name and page title
php bin/chatstats generate ~/Downloads/ChatExport_2026-09-01 --key=family --title="Family chat"

# Fast regression check after code changes: first 2000 messages only
php bin/chatstats generate ~/Downloads/ChatExport_2026-09-01 --key=check --limit=2000

# Nicknames with profile links instead of names
php bin/chatstats generate ~/Downloads/ChatExport_2026-09-01 --nicks
```

## How it works / Performance

- The parser streams messages from the export files (generator) and caches them as
  native `serialize` chunks (5000 messages each) in `var/cache/parsers/<signature>-v3/`.
- All statistics are aggregated in **one pass** over the messages
  (`MessageCollection` lazy single-pass aggregator).
- The result of all handlers is cached in `var/cache/results/<sig>.ser`: repeat runs
  don't re-read messages at all (~40 MB even on million-message chats).
- Caches invalidate automatically: message cache on export changes (file names + mtimes),
  result cache on code/config changes (mtime of `src/` and `config/`).
- `--limit` runs use a separate cache so they never touch the full one.

See [docs/architecture.md](docs/architecture.md) for details.

## Documentation

| Document | Language |
|---|---|
| [Installation & usage](docs/usage.en.md) | English |
| [Установка и использование](docs/usage.md) | Русский |
| [Architecture](docs/architecture.md) | Русский |
| [Development](docs/development.md) — how to add new statistics | Русский |

## Configuration

- `config/users.php` — map «nickname → real name» applied while parsing.
- `config/nicks.php` — map «name → Telegram nickname (without @)» for `--nicks`.
- `config/words.php` — stop words and aliases for the word analysis.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Report bugs and suggest features via
[GitHub Issues](https://github.com/okopok/telegram-chats-stats/issues).

## Security

See [SECURITY.md](SECURITY.md).

## License

[GPL-3.0-or-later](LICENSE) © Molodtsov Aleksandr ([@okopok](https://t.me/okopok) on Telegram).
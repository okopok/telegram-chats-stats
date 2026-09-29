# Installation & Usage

## Requirements

- **PHP >= 8.4**
- **ext-mbstring** extension
- Composer

## Installation

```
composer install
```

`vendor/`, `var/` and `public/data` are in `.gitignore` — they are working artifacts,
not part of the repository.

## Running with Docker (no PHP on the host)

If you don't have PHP installed but do have Docker:

```bash
# Build the image (once)
docker build -t chatstats .

# Generate: export folder is mounted read-only, output goes to ./var/html
docker run --rm \
  -v /path/to/export:/export:ro \
  -v $(pwd)/var:/app/var \
  chatstats generate /export --key=my-chat
```

Notes:

- Inside the container the code lives in `/app`, entry point — `php /app/bin/chatstats`.
- One mount is required: the export folder (read-only into `/export`) — it becomes
  the command argument.
- **Mounting `./var`** keeps both the HTML output and the caches (messages & results)
  on the host: repeat runs inside the container are as fast as on the host.
  Without this mount the result stays inside the container and is lost after `--rm`.
- All CLI options work as usual (see below): `--limit=2000` for quick checks,
  `--nicks`, `--no-cache`, `--debug`, etc.
- `docker run --rm chatstats list` — list of commands; `--help` — options help.
- The image is built on PHP 8.4 CLI (matching `composer.json`), dependencies are
  installed during the build — nothing needs to be installed on the host.

## The `generate` command

```
php bin/chatstats generate <export-folder> [--key=<key>] [--title=<title>]
                            [--no-cache] [--rebuild] [--limit=<N>] [--nicks] [--debug]
```

### Arguments and options

| Parameter | Description |
|---|---|
| `<export-folder>` | Required argument — the folder with the HTML chat export from Telegram Desktop. If the folder does not exist → error and **exit 1**. |
| `--key=<key>` | Output file name: `var/html/<key>.html`. Defaults to the export folder name. |
| `--title=<title>` | Page title. Defaults to the key. |
| `--no-cache` | Do not read or write the message cache. |
| `--rebuild` | Force rebuild of the message cache. |
| `--limit=<N>` | Process only the first **N** messages — for quick checks on huge chats (seconds instead of minutes). The limited run uses its own cache (signature with the `-limit-N` suffix); the full cache is never overwritten. A warning is printed that the run is a check run. Recommended for regression testing after code changes. |
| `--nicks` | Show user nicknames with profile links (`https://t.me/<nickname>`) instead of names. Nicknames come from `config/nicks.php` (map «name → nickname», filled in manually — exports don't contain usernames). HTML parts get clickable links, canvas charts get plain nicknames (Chart.js doesn't support links). Without the flag the behavior is unchanged. |
| `--debug` | Handler timings table (Stopwatch) and Twig without auto-reload. |

### Output

- The only artifact is a static page: `var/html/<key>.html`.
- On success the console prints the file path and the number of panels:
  `Done: var/html/<key>.html (27 panels)`.
- The page starts with a KPI dashboard (messages, users, days, media, reactions,
  characters) and fun facts; below — 27 collapsible statistic panels.
  The theme (light/dark) is toggled by the button in the top-right corner
  and is remembered in the browser.

### About the cache

On the first run, messages are parsed streamingly and written in chunks (of 5000)
to `var/cache/parsers/<signature>-v3/00000.ser...` (`-v3` is the cache format version:
it changes when the tool is updated, so an old cache without new data fields is never
reused). The cache is rebuilt automatically whenever the export folder changes
(signature = file names + mtimes). Handler results are cached in
`var/cache/results/<sig>.ser` — repeat runs don't read messages and don't
recalculate statistics (memory ~20–40 MB even on chats with a million messages);
the result cache is invalidated when code or configs change. Details —
[Architecture](architecture.md#память-и-кэши) (in Russian).

> **Memory**: messages are never held fully in memory — one chunk is read at a time,
> so peak memory does not depend on chat size (a full run over 1.1M messages — ~350 MB;
> repeat runs — ~20–40 MB).

## How to get an export

Telegram Desktop → open the chat → ⋮ (menu) → *Export chat history* → check HTML
in the export options → download the resulting folder.

## Examples

```bash
# Simple run by export folder
php bin/chatstats generate ~/Downloads/ChatExport_2026-09-01

# Custom file name and page title
php bin/chatstats generate ~/Downloads/ChatExport_2026-09-01 --key=family --title="Family chat"

# Full rebuild without cache, with timings
php bin/chatstats generate ~/Downloads/ChatExport_2026-09-01 --no-cache --debug

# Quick check after code changes: only the first 2000 messages
php bin/chatstats generate ~/Downloads/ChatExport_2026-09-01 --key=check --limit=2000

# Nicknames with profile links instead of names (mapping: config/nicks.php)
php bin/chatstats generate ~/Downloads/ChatExport_2026-09-01 --nicks
```

## Verification after changes

There are no automated tests; verification = run the generation and visually check
the generated HTML (panels, values, absence of errors). Use `--limit=<N>` for quick
regression runs.
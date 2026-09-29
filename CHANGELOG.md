# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Dockerfile` + `.dockerignore`: run the tool without installing PHP/dependencies
  on the host (`docker build -t chatstats . && docker run --rm -v <export>:/export:ro -v $(pwd)/var:/app/var chatstats generate /export`).

## [1.0.0] — 2026-09-29

Initial open-source release.

### Added

- Parser supports **two generations of Telegram Desktop export markup**
  (old and 2025+): `photo_wrap`/`sticker_wrap`/`animated_wrap`/`video_file_wrap`
  outside `div.media_wrap`, `media_file`, `media_location`, GIF/Video detection
  by `title`; Russian dates (`11 декабря 2022, 22:51:24`) via `DateHelper`.
- New message attributes: **reactions** (emoji + count), **edited** flag,
  **forwarded from** source, **webpage preview** site/title.
- Media details: document name/size, voice and video duration, poll question/answers.
- 14 new statistic panels (27 total):
  - reactions: top emojis, by message type, reaction leaders, top reacted messages;
  - edits; forwards (by user and source); top websites;
  - media weight (documents, voice/video duration); polls;
  - reply speed (median response time); chronotypes; activity heatmap (weekday × hour);
  - streak days (chat and per user); last activity; self-replies; longest messages.
- Dashboard with KPI cards and **fun facts** on top of the page.
- **Light/dark theme** toggle (persisted in `localStorage`), responsive layout,
  custom CSS — Bootstrap/jQuery removed (own accordion JS, Chart.js from CDN).
- Word cloud block in the popular words panel; SVG communication graph
  (who replies to whom) in the reply matrix panel.
- `--limit=<N>` option: process only first N messages with a separate cache,
  for fast regression runs on huge chats.
- `--nicks` option: show `@nickname` profile links instead of names
  (mapping in `config/nicks.php`).
- `--rebuild`, `--debug` (Stopwatch timings) options; `--no-cache` behavior kept.

### Changed

- **Memory**: messages are never held fully in memory — parsed/cached/aggregated
  in streaming chunks of 5000 (`CachedMessageSource`), single-pass lazy aggregator
  `MessageCollection::aggregate()`. Peak memory no longer depends on chat size
  (1.1M-message chat: ~350 MB full run, ~40 MB repeat runs with result cache).
- Message cache: native `serialize` chunks in
  `var/cache/parsers/<signature>-v3/` (format version suffix `-v3`);
  `reply_to_message` is not serialized.
- Result cache: `var/cache/results/<sig>.ser` — repeat runs don't re-read messages
  and don't execute handlers; invalidated by mtime of `src/` and `config/`.
- Removed JMS serializer and `ReplyLinker`; `WordsAnalyzer` works on plain arrays.
- Docs updated (architecture, usage RU/EN, development) and README rewritten
  (English + Russian).

## Prior history

This project started as a private personal tool (2020); the changes above are the
state of the codebase at the first public release. Older commits, if any, were
not part of a tagged release.

[Unreleased]: https://github.com/okopok/telegram-chats-stats/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/okopok/telegram-chats-stats/releases/tag/v1.0.0
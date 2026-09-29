# Редизайн chatStats Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перевести chatStats на PHP 8.5 и чистую архитектуру (CLI на symfony/console, слой запросов `MessageCollection`, чистые обработчики, кэш с инвалидацией и без рекурсивной сериализации), сохранив контракты данных с шаблонами и порядок обработчиков.

**Architecture:** Источники (`MessageSource`) отдают `MessageCollection` (value-object с агрегациями и релинком реплаев); `Engine` последовательно вызывает чистые `StatHandler`-функции; `HtmlRenderer` рендерит Twig и пишет файл; композиция в `GenerateCommand` (symfony/console), конфиги в `config/*.php`.

**Tech Stack:** PHP 8.5, symfony/console 7, symfony/dom-crawler 7, twig 3, jms/serializer 3.30, tightenco/collect 9, symfony/stopwatch 7.

**Spec:** `docs/superpowers/specs/2026-09-29-chatstats-redesign-design.md`

## Global Constraints

- PHP `>=8.4` (на машине 8.5.11); синтаксис современный PHP 8, сущности остаются публичными DTO (нужны JMS).
- Тестов нет (договорённость): верификация = запуск + сверка с `var/html/baseline-sts.html`.
- Комментарии, `getDescription()` и шаблоны — на русском.
- Контракты данных с шаблонами обработчиков не меняются (те же структуры, что перечислены в спеке §7).
- Порядок обработчиков на странице — как в текущем `public/functions.php` (§ 4 спеки: FirstMessage, CountTotal, TotalStrlen, MedianByDate, CountTotalByDate, CountTotalByUser, StrlenByUser, UserMedianMessageLength, CountTotalUsers, CountRepliesByUser, CountUsersByDayNHours, CountByTypeAndUser, PopularWords).
- Полностью убираются `str/str` и `symfony/var-dumper`; `autoload.files` из composer удаляется.
- Старый код (до Task 1 шаг 2) генерирует базлайн, после `composer update` он больше не запускается — сверка идёт только с сохранённой страницей.
- Реальные данные: `public/data/sts` (166 МБ, 246 HTML), выход — `var/html/<key>.html`, кэш — `var/cache/parsers/<signature>.json`.

## Review Focus

Эти входные классы спека подразумевает, но не фиксирует явно; каждый закреплён проверочным шагом в своей задаче:

1. Несуществующая директория `<dir>` → сообщение об ошибке и exit-код 1 (а не молчаливый успех) — Task 4.
2. Пустой чат (нет сообщений) → `firstByDate(): ?Message`, обработчик не падает — Task 4/5.
3. `medianStrlenByUser`: сортировка по числам, «9» не больше «10» — Task 6.
4. Кэш: изменение файла экспорта меняет сигнатуру и пересобирает кэш; выключение `--no-cache` не пишет кэш — Task 9.
5. Кэш хранит только `reply_to_id`, но после загрузки реплики линкуются (панель countRepliesByUser не пустая) — Task 9.

---

### Task 1: Базлайн старого кода и обновление зависимостей

**Files:**
- Create: `var/html/baseline-sts.html` (артефакт)
- Modify: `composer.json`, `composer.lock` (через composer update)

**Interfaces:**
- Produces: базлайновая страница для всех дальнейших сверок.

- [ ] **Step 1: Оценить данные**

Run: `du -sh public/data/sts && ls public/data/sts/*.html | wc -l`
Expected: ~166M и ~246 файлов (данные на месте, прогоны реалистичны).

- [ ] **Step 2: Сгенерировать базлайн старым кодом**

Run: `php -d memory_limit=1G -r 'require "vendor/autoload.php"; makeData(getcwd()."/public/data/sts", "Статистика по чатику", "sts", true, true, false, new Symfony\Component\Stopwatch\Stopwatch());'` затем `cp var/html/sts.html var/html/baseline-sts.html`
Expected: `var/html/baseline-sts.html` создан, содержит панели-аккордеоны (grep `accordion-group`). Если старый код на PHP 8.5 падает — повторить внутри `docker run --rm -v "$PWD":/app -w /app php:7.4-cli php -d memory_limit=1G -r '...'`.
Timeout: 300s.

- [ ] **Step 3: Переписать `composer.json`**

require: `php >=8.4`, `symfony/console ^7.0`, `symfony/dom-crawler ^7.0`, `symfony/stopwatch ^7.0`, `jms/serializer ^3.30`, `tightenco/collect ^9.0`, `twig/twig ^3.0`. Удалить `str/str`, `symfony/var-dumper`, блок `autoload.files` (оставить только `psr-4: ChatStats\ => src`), удалить `require-dev`.

- [ ] **Step 4: Обновить зависимости**

Run: `composer update`
Expected: установка завершена без ошибок (допустимы deprecation-warnings пакетов, не PHP-фаталы).

- [ ] **Step 5: Коммит**

```bash
git add composer.json composer.lock
git commit -m "chore: актуализация зависимостей под PHP 8.5 (symfony 7, без str/str и var-dumper)"
```

---

### Task 2: UserHelper на mb_* и config/users.php

**Files:**
- Create: `config/users.php`
- Modify: `src/UserHelper.php` (полностью)

**Interfaces:**
- Produces: `UserHelper::norm(string $username, array $namesMap): string` — срезает ` via @...` (через `mb_strrpos`/`mb_substr`) и последовательно применяет замены из карты `nick => имя`.
- `config/users.php` возвращает `['Саша Майонез' => 'Александр Кузнецов', 'Таня 🐠 Кондратова' => 'Таня Кондратова', 'Катя 🌿 Хмелевская' => 'Катя Хмелевская', 'Серёга' => 'Сергей Болдин']`.

- [ ] **Step 1: Создать `config/users.php`** — карта из текущего `UserHelper::norm()` (список выше, без `str/str`).

- [ ] **Step 2: Переписать `src/UserHelper.php`**

`final class UserHelper` со статическим `norm(string $username, array $namesMap): string`. Реализация: найти позицию ` via @` через `mb_strrpos`, отрезать хвост, затем `str_replace` по карте, `trim`. Без `Str\Str`.

- [ ] **Step 3: Проверить**

Run: `php -r 'require "vendor/autoload.php"; $map = require "config/users.php"; var_dump(ChatStats\UserHelper::norm("Саша Майонез via @channel", $map) === "Александр Кузнецов", ChatStats\UserHelper::norm("Обычный юзер via @x", $map) === "Обычный юзер");'`
Expected: `bool(true) bool(true)`.

- [ ] **Step 4: Коммит**

```bash
git add config/users.php src/UserHelper.php
git commit -m "refactor: UserHelper на mb_* с картой имён из config/users.php"
```

---

### Task 3: Слой Messages (MessageSource, ReplyLinker, MessageCollection, ExportMessageSource) и DateHelper

**Files:**
- Create: `src/Messages/MessageSource.php`, `src/Messages/ReplyLinker.php`, `src/Messages/MessageCollection.php`, `src/Messages/ExportMessageSource.php`
- Modify: `src/DateHelper.php` (добавить `getMonthName`)

**Interfaces:**
- Produces:
  - `interface MessageSource { public function getMessages(): MessageCollection; }`
  - `final class ReplyLinker { public static function link(Collection $messages): void; }` — проставляет `reply_to_message` по ключам коллекции из `reply_to_id` (логика текущего `normalizeReplies`).
  - `final readonly class MessageCollection { public function __construct(Collection $messages); public function count(): int; public function firstByDate(): ?Message; public function usernames(): array; public function countByUser(): array; public function strlenByUser(): array; public function medianStrlenByUser(): array; public function countByDate(string $format): array; public function medianByDate(string $format): float; public function countByUserDayHour(): array; public function countByType(): array; public function countByTypeAndUser(): array; public function repliesMatrix(): array; public function messages(): Collection; }` — конструктор вызывает `ReplyLinker::link()`; каждый метод возвращает структуру из спеки §6/§7 (идентичную текущим обработчикам).
  - `final class ExportMessageSource implements MessageSource { public function __construct(string $dir, array $namesMap); }` — natsort файлов, пропуск подпапок/`.DS_Store`, dom-crawler, стек-сообщения, декларативная карта медиа (§5 спеки: порядок photo, sticker, video, voice, location, audio, poll, document, animation, contact; photo/sticker — `div.media_photo` с проверкой title), парсинг `Contact` (`.media_contact`), `reply_to_id` без релинка, `UserHelper::norm` с картой; без `Str\Str` и VarDumper.
  - `DateHelper::getMonthName(string $num): string` — карта `'01' => 'Январь' … '12' => 'Декабрь'`.

- [ ] **Step 1: Создать `src/Messages/MessageSource.php`** — интерфейс (сигнатура выше).

- [ ] **Step 2: Создать `src/Messages/ReplyLinker.php`** — перенос `normalizeReplies()`: собрать `reply_to_id => [message_id…]`, проставить `reply_to_message` по ключам коллекции.

- [ ] **Step 3: Создать `src/Messages/MessageCollection.php`** — методы из интерфейс-блока; тела — текущая логика соответствующих обработчиков (CountTotal/FirstMessage/CountTotalUsers/CountTotalByUser/StrlenByUser/UserMedianMessageLength/MedianByDate/CountTotalByDate/CountUsersByDayNHours/CountByTypeAndUser/CountRepliesByUser), но:
  - `firstByDate()` — через `sortBy('date')->first()` с проверкой `isNotEmpty()` → `?Message`;
  - `medianStrlenByUser()` — `round(strlen / count)` как числа, `sortDesc` по значению;
  - `countByDayN...` — `countByUserDayHour()` со структурой текущего CountUsersByDayNHours (hash = md5(username), total, dayOfWeek, dayOfWeekByHour с часами 0–23);
  - `repliesMatrix()` — структура текущего CountRepliesByUser (матрица с arsort внутри).

- [ ] **Step 4: Добавить `DateHelper::getMonthName()`** — карта месяцев из switch в CountTotalByDate.

- [ ] **Step 5: Создать `src/Messages/ExportMessageSource.php`** — перенос ExportParser по интерфейс-блоку; карта медиа:

```php
private const MEDIA_SELECTORS = [
    ['selector' => 'div.media_photo', 'field' => 'photo', 'titleContains' => 'photo'],
    ['selector' => 'div.media_photo', 'field' => 'sticker', 'titleContains' => 'sticker'],
    ['selector' => '.media_video', 'field' => 'video'],
    ['selector' => '.media_voice_message', 'field' => 'voice'],
    ['selector' => '.media_live_location', 'field' => 'location'],
    ['selector' => '.media_audio_file', 'field' => 'audio'],
    ['selector' => '.media_poll', 'field' => 'poll'],
    ['selector' => '.media_document', 'field' => 'document'],
    ['selector' => '.media_animation', 'field' => 'animation'],
    ['selector' => '.media_contact', 'field' => 'contact'],
];
```

- [ ] **Step 6: Проверить соответствие базлайну**

Run: `php -d memory_limit=1G -r 'require "vendor/autoload.php"; $s = new ChatStats\Messages\ExportMessageSource(getcwd()."/public/data/sts", require "config/users.php"); $m = $s->getMessages(); var_dump($m->count()); var_dump(array_slice($m->countByUser(), 0, 3, true)); var_dump(array_slice($m->repliesMatrix(), 0, 2, true)); var_dump($m->countByType()); var_dump(array_slice($m->medianStrlenByUser(), 0, 3, true)); var_dump($m->firstByDate()?->text);'`
Expected: числа совпадают с цифрами базлайна (`var/html/baseline-sts.html`): общий счёт, топ пользователей, типы медиа. `firstByDate()` — текст первого сообщения из базлайна.
Timeout: 300s.

- [ ] **Step 7: Коммит**

```bash
git add src/Messages src/DateHelper.php
git commit -m "feat: слой Messages — MessageCollection, ReplyLinker, ExportMessageSource, DateHelper.getMonthName"
```

---

### Task 4: Engine, HtmlRenderer, CLI, первые обработчики

**Files:**
- Create: `src/Engine.php`, `src/Renderer/HtmlRenderer.php`, `src/Stats/StatHandler.php`, `src/Stats/AbstractStatHandler.php`, `src/Stats/FirstMessageHandler.php`, `src/Stats/CountTotalHandler.php`, `src/Console/GenerateCommand.php`, `src/Application.php`, `bin/chatstats`
- Modify: `src/templates/default/index.twig` (полностью, по контракту ниже)

**Interfaces:**
- Produces:
  - `interface StatHandler { public function key(): string; public function description(): string; public function template(): string; public function handle(MessageCollection $messages): array; }`
  - `abstract class AbstractStatHandler implements StatHandler { final public function template(): string { return $this->key() . '.twig'; } }`
  - `final class Engine { public function __construct(MessageCollection $messages, array $handlers, ?Stopwatch $stopwatch = null); /** @return array<string,array> */ public function calculate(): array; }` — Stopwatch: секция `calc`, событие `handler <key>` на каждого.
  - `final class HtmlRenderer { public function __construct(Environment $twig); public function render(string $outputFile, string $title, array $results): void; }` — `results` = `[key => ['description'=>…,'data'=>…,'template'=>…]]`, рендер + `file_put_contents`.
  - `FirstMessageHandler::key()` = `firstMessage`, description «Самое первое сообщение», data: `['message'=>…,'datetime'=>int,'from'=>…]` (при пустом чате — `['message'=>'','datetime'=>0,'from'=>'']`).
  - `CountTotalHandler::key()` = `countTotal`, description «Общее количество сообщений», data: `['count'=>int]`.
  - `GenerateCommand` (имя `generate`): аргумент `dir`, опции `--key`, `--title`, `--no-cache`, `--rebuild`, `--debug`. Композиция: `ExportMessageSource` (или уже готовый `CachedMessageSource` из Task 9), Twig (`FilesystemLoader('src/templates/default')`, `cache => var/twig/cache`, `auto_reload => !$debug`, фильтр `md5`), Engine, HtmlRenderer. Регистрация обработчиков — массив в порядке из Global Constraints (на этом шаге в массиве только firstMessage и countTotal; остальные добавляются в Tasks 5–8). Ошибки директории — `throw new \RuntimeException(...)` (console вернёт exit 1). `--debug` — вывод Stopwatch-событий таблицей (компонент Table).
  - `index.twig`: итерация `results` от движка; заголовок аккордеона — `description`; include `"handlers/" ~ template ignore missing` c `{description, data, templateKey: key}` и `only`; фильтр `md5` для id; остальное (CDN, Chart.js) без изменений.

- [ ] **Step 1: Создать `StatHandler`, `AbstractStatHandler`, `FirstMessageHandler`, `CountTotalHandler`** — по интерфейс-блоку.

- [ ] **Step 2: Создать `Engine`** — `calculate()` возвращает `[key => data]` в порядке массива обработчиков.

- [ ] **Step 3: Создать `HtmlRenderer`** и переписать `index.twig` — по контракту.

- [ ] **Step 4: Создать `GenerateCommand`, `Application`, `bin/chatstats`**

`bin/chatstats`:
```php
#!/usr/bin/env php
<?php
require dirname(__DIR__) . '/vendor/autoload.php';
exit((new ChatStats\Application())->run());
```
`Application` — `Symfony\Component\Console\Application` с именем `chatstats` и командой `generate`. `chmod +x bin/chatstats`.

- [ ] **Step 5: Прогон на реальных данных**

Run: `php bin/chatstats generate public/data/sts --key sts --no-cache`
Expected: создан `var/html/sts.html`; панели `firstMessage` и `countTotal` с цифрами из базлайна (grep по файлу).
Timeout: 300s.

- [ ] **Step 6: Проверка ошибки директории**

Run: `php bin/chatstats generate /no/such/dir; echo "exit=$?"`
Expected: сообщение об ошибке в консоли, `exit=1`.

- [ ] **Step 7: Коммит**

```bash
git add bin/chatstats src/Engine.php src/Renderer src/Stats src/Console src/Application.php src/templates/default/index.twig
git commit -m "feat: CLI generate на symfony/console + Engine/HtmlRenderer + первые обработчики"
```

---

### Task 5: Обработчики дат (MedianByDate, CountTotalByDate)

**Files:**
- Create: `src/Stats/MedianByDateHandler.php`, `src/Stats/CountTotalByDateHandler.php`

**Interfaces:**
- Consumes: `MessageCollection::countByDate(string $format): array`, `medianByDate(string $format): float`, `DateHelper::getMonthName()`.
- Produces: `MedianByDateHandler::key()` = `medianByDate`, description «Среднее количество сообщений», data `['year'=>float,'months'=>float,'days'=>float,'weeks'=>float,'hours'=>float]` (форматы: `Y`, `Y-m`, `Y-m-d`, `Y-m N`, `Y-m-d H`). `CountTotalByDateHandler::key()` = `countTotalByDate`, description «Общее количество сообщений по датам», data `['year'=>…,'weeks'=>…,'all_days'=>…,'all_months'=>…,'monthsTop10'=>…,'daysTop10'=>…]` (форматы и сортировки — из текущего CountTotalByDate; `all_months` — через `getMonthName`).

- [ ] **Step 1: Создать оба обработчика** — поверх методов MessageCollection; зарегистрировать в массиве `GenerateCommand` после `countTotal` (порядок: ..., CountTotal, TotalStrlen из Task 6 будет ниже — сейчас порядок: firstMessage, countTotal, medianByDate, countTotalByDate).
- [ ] **Step 2: Прогон и сверка**

Run: `php bin/chatstats generate public/data/sts --key sts --no-cache`
Expected: панели medianByDate и countTotalByDate с теми же числами, что в базлайне (годы/месяцы/топ-10).
Timeout: 300s.

- [ ] **Step 3: Коммит** — `git add src/Stats && git commit -m "feat: обработчики дат (medianByDate, countTotalByDate)"`

---

### Task 6: Обработчики по пользователям (CountTotalByUser, StrlenByUser, UserMedianMessageLength, CountTotalUsers)

**Files:**
- Create: `src/Stats/CountTotalByUserHandler.php`, `src/Stats/StrlenByUserHandler.php`, `src/Stats/UserMedianMessageLengthHandler.php`, `src/Stats/CountTotalUsersHandler.php`

**Interfaces:**
- Consumes: `MessageCollection::countByUser()`, `strlenByUser()`, `medianStrlenByUser()`, `usernames()`.
- Produces: ключи/описания из спеки §7; `UserMedianMessageLengthHandler` data — `[user => int]` (без `number_format`), каскадно отсортировано по значению (числа, не строки).

- [ ] **Step 1: Создать четыре обработчика**; зарегистрировать в порядке: ..., countTotalByDate, countTotalByUser, strlenByUser, userMedianMessageLength, countTotalUsers.
- [ ] **Step 2: Прогон и сверка**

Run: `php bin/chatstats generate public/data/sts --key sts --no-cache`
Expected: панель userMedianMessageLength отсортирована по убыванию **числа** (проверить вручную: значения 9 и 10 стоят в правильном порядке) и остальные панели совпадают с базлайном.
Timeout: 300s.

- [ ] **Step 3: Коммит** — `git add src/Stats && git commit -m "feat: обработчики по пользователям (+фикс числовой сортировки медианы)"`

---

### Task 7: Реплики и активность (CountRepliesByUser, CountUsersByDayNHours, CountByTypeAndUser)

**Files:**
- Create: `src/Stats/CountRepliesByUserHandler.php`, `src/Stats/CountUsersByDayNHoursHandler.php`, `src/Stats/CountByTypeAndUserHandler.php`

**Interfaces:**
- Consumes: `MessageCollection::repliesMatrix()`, `countByUserDayHour()`, `countByTypeAndUser()`.
- Produces: ключи/описания из спеки §7; структуры данных идентичны текущим обработчикам (матрица с hash, dayOfWeek через `DateHelper::getDayName`).

- [ ] **Step 1: Создать три обработчика**; зарегистрировать в порядке: ..., countTotalUsers, countRepliesByUser, countUsersByDayNHours, countByTypeAndUser.
- [ ] **Step 2: Прогон и сверка**

Run: `php bin/chatstats generate public/data/sts --key sts --no-cache`
Expected: панели репликов/активности/типов совпадают с базлайном (`reply_to_message` линкуется — панель репликов не пустая).
Timeout: 300s.

- [ ] **Step 3: Коммит** — `git add src/Stats && git commit -m "feat: обработчики репликов, активности и типов сообщений"`

---

### Task 8: WordsAnalyzer, config/words.php, PopularWordsHandler

**Files:**
- Create: `config/words.php`, `src/Stats/Words/WordsAnalyzer.php`, `src/Stats/PopularWordsHandler.php`

**Interfaces:**
- Produces:
  - `config/words.php` → `['stop_words' => [<слова из константы STOP_WORDS>], 'aliases' => [<wordList из PopularWordsCount>], 'word_len_min' => 3]`.
  - `final class WordsAnalyzer { public function __construct(array $config); /** @return array{list:array,total:array,users:array,uniq:array} */ public function analyze(MessageCollection $messages): array; }` — вся логика текущего PopularWordsCount (`prepareString`, `countWords`, `countUserWord`, `countUserWordList`, `countUniqWordsByUser`) на `mb_*`/`preg` вместо `Str\Str` (lowercase, `ё→е`, вырезание `<a>`-ссылок, схлопывание повторов `(.+?)\1+`, замена алиасов, удаление не-буквенно-цифровых, split по пробелам; фильтр стоп-слов и длины).
  - `PopularWordsHandler::key()` = `popularWordsCount`, description «Популярные слова», data — `['list'=>…,'total'=>…,'users'=>…,'uniq'=>…]`.

- [ ] **Step 1: Создать `config/words.php`** — перенос STOP_WORDS (как массив строк) и wordList из PopularWordsCount без изменений значений.
- [ ] **Step 2: Создать `WordsAnalyzer`** — по интерфейс-блоку; поведение идентично текущему классу (включая `->take(100)`, `take(50)`, фильтр `> 1`).
- [ ] **Step 3: Создать `PopularWordsHandler`**; зарегистрировать последним в `GenerateCommand`.
- [ ] **Step 4: Прогон и сверка**

Run: `php bin/chatstats generate public/data/sts --key sts --no-cache`
Expected: панель popularWordsCount — топ слов, список по пользователям и уникальные совпадают с базлайном (сверка top-10 и uniq).
Timeout: 600s.

- [ ] **Step 5: Коммит** — `git add config/words.php src/Stats/Words src/Stats/PopularWordsHandler.php && git commit -m "feat: WordsAnalyzer на mb_*/preg + конфиг слов"`

---

### Task 9: CachedMessageSource

**Files:**
- Create: `src/Messages/CachedMessageSource.php`
- Modify: `src/Console/GenerateCommand.php` (подключение по флагам)

**Interfaces:**
- Produces: `final class CachedMessageSource implements MessageSource { public function __construct(ExportMessageSource $source, string $dir, bool $cacheEnabled, bool $rebuild); public function getMessages(): MessageCollection; }`
  - Сигнатура кэша: `sha1` по `(relative filename|filemtime)` всех файлов директории; файл `var/cache/parsers/<signature>.json`.
  - Сериализация: JMS `array<ChatStats\Entity\Message>` с `reply_to_message` в `null` (линковка не нужна — MessageCollection линкует при загрузке), `SerializationContext` initial-type как в текущем CachedParser; десериализация `array<...>` → `collect()`. `$cacheEnabled=false` → прямой парсинг без чтения/записи; `$rebuild=true` → пересборка.

- [ ] **Step 1: Создать `CachedMessageSource`** — по интерфейс-блоку; перед сериализацией обнулить `reply_to_message` у всех сообщений (гарантия отсутствия рекурсии в JSON).
- [ ] **Step 2: Подключить в `GenerateCommand`** — `--no-cache` → `cacheEnabled=false`, `--rebuild` → `rebuild=true`; источник выбирается: CachedMessageSource, если кэш включён, иначе ExportMessageSource.
- [ ] **Step 3: Прогон с кэшем**

Run: `php -d memory_limit=1G bin/chatstats generate public/data/sts --key sts` (без `--no-cache`)
Expected: создан `var/cache/parsers/<sig>.json`; размер заметно меньше 170 МБ (`ls -lh var/cache/parsers/`); HTML идентичен предыдущему прогону (diff с `var/html/sts.html` от Task 8 при том же прогоне — числа совпадают).
Timeout: 600s.

- [ ] **Step 4: Прогон из кэша**

Run: `php -d memory_limit=1G bin/chatstats generate public/data/sts --key sts`
Expected: быстрее шага 3, те же цифры; панель countRepliesByUser не пустая (реплики линкуются после десериализации).

- [ ] **Step 5: Проверка инвалидации**

Run: `touch public/data/sts/messages.html` → `php -d memory_limit=1G bin/chatstats generate public/data/sts --key sts`
Expected: сигнатура изменилась (в `var/cache/parsers/` появился второй файл), страница пересобрана без ошибок.

- [ ] **Step 6: Коммит** — `git add src/Messages/CachedMessageSource.php src/Console/GenerateCommand.php && git commit -m "feat: CachedMessageSource с инвалидацией по содержимому экспорта"`

---

### Task 10: Снос старого кода, чистый install, AGENTS.md

**Files:**
- Delete: `public/run.php`, `public/functions.php`, `src/Parsers/`, `src/StatMachine.php`, `src/StatHandlers/`
- Modify: `AGENTS.md` (полностью), `.gitignore` (проверить `var/`, `public/data`)

**Interfaces:**
- Consumes: вся новая структура из Tasks 2–9.

- [ ] **Step 1: Удалить старые файлы**

Run: `git rm -r public/run.php public/functions.php src/Parsers src/StatMachine.php src/StatHandlers`
Expected: `git status` показывает удаления; psr-4 покрывает новые классы.

- [ ] **Step 2: Чистый install**

Run: `composer dump-autoload && rm -rf vendor && composer install`
Expected: установка с нуля без ошибок; `php bin/chatstats list` показывает команду `generate`.

- [ ] **Step 3: Полный прогон (с кэшем и с --debug)**

Run: `php -d memory_limit=1G bin/chatstats generate public/data/sts --key sts` затем `php -d memory_limit=1G bin/chatstats generate public/data/sts --key sts --rebuild --debug`
Expected: обе страницы созданы; в `--debug` — таблица замеров; цифры совпадают с `var/html/baseline-sts.html` (выборочная сверка панелей).
Timeout: 600s.

- [ ] **Step 4: Удалить старые кэши по dataKey и базлайн-артефакт из var (не из git)**

Run: `rm -f var/cache/parsers/sts.json && ls var/cache/parsers/`
Expected: остались только `<signature>.json`-файлы. (Базлайн-страницу оставить для справки, но исключить при необходимости из git — `var/` уже в .gitignore.)

- [ ] **Step 5: Переписать `AGENTS.md`**

Разделы: запуск (`php bin/chatstats generate ...`, опции), пайплайн (GenerateCommand → MessageSource → MessageCollection → Engine/StatHandler → HtmlRenderer), добавление новой статистики (3 шага: класс-обработчик в `src/Stats/`, шаблон в `src/templates/default/handlers/`, регистрация в массиве `GenerateCommand`), нюансы (конфиги `config/words.php`, `config/users.php`, `UserHelper::norm`, md5-фильтр, ignore missing). Язык — русский.

- [ ] **Step 6: Коммит**

```bash
git add -A
git commit -m "refactor: снос старого кода (run.php/functions.php/StatMachine/StatHandlers/Parsers), AGENTS.md"
```

---

## Self-Review

- **Покрытие спеки:** §2 структура — Tasks 2–10; §3 composer — Task 1; §4 CLI — Task 4 (+опции кэша — Task 9); §5 источники — Tasks 3, 9; §6 MessageCollection/ReplyLinker — Task 3; §7 обработчики — Tasks 4–8; §8 Engine/Renderer — Task 4; §9 баги — Task 3 (3), Task 6 (1), Tasks 4/5 (2), Task 4 (4), Task 9 (5, 6), Task 10 (7), Task 3 (8); §10 — инварианты в Global Constraints; §11 порядок — задачи в той же последовательности.
- **Типы:** `MessageCollection` методы совпадают между Task 3 (создание) и Tasks 5–7 (потребление); `norm($username, $namesMap)` — Task 2 создаёт, Task 3 потребляет; ключи обработчиков совпадают со специей (§7) и шаблонами.
- **Review Focus:** пункты 1 (Task 4 Step 6), 2 (Task 3 Step 6, Task 4 контракт FirstMessageHandler), 3 (Task 6 Step 2), 4 и 5 (Task 9 Steps 3–5).
- **Пропорция:** план — последовательность компонентов с сигнатурами и проверками; тела методов не транскрибируются (за исключением карты медиа и бин-скрипта, которые фиксируют точные значения из спеки).
# AGENTS.md — chatStats

Личный PHP CLI-инструмент (без фреймворка, без веб-сервера, без тестов, без CI и линтера): парсит **HTML-экспорты чатов Telegram Desktop** и генерирует по одной статической HTML-странице статистики на чат. Комментарии в коде, описания обработчиков и шаблоны — на **русском**, новые — тоже.

## Запуск

- Установка: `composer install` (нужен **PHP >= 8.4**; синтаксис PHP 8 можно использовать; сущности остаются публичными DTO — их читает JMS-сериализатор). `vendor/`, `var/` и `public/data` в гитигноре.
- Запуск:

  ```
  php bin/chatstats generate <папка-экспорта> [--key=<ключ>] [--title=<заголовок>]
                                          [--no-cache] [--rebuild] [--debug]
  ```

  - `<папка-экспорта>` — обязательный аргумент; несуществующая папка → ошибка и exit 1.
  - `--key` по умолчанию — имя папки; задаёт имя выходного файла (`var/html/<key>.html`).
  - `--title` по умолчанию — ключ; заголовок страницы.
  - `--no-cache` — не читать и не писать кэш; `--rebuild` — пересобрать кэш принудительно; `--debug` — таблица таймингов обработчиков (Stopwatch) и Twig без авто-перезагрузки.
- Тестов нет; проверка = запустить и посмотреть сгенерированный HTML.

## Пайплайн (поток данных)

`bin/chatstats` → `Application` → `Console/GenerateCommand` → `MessageSource` (`ExportMessageSource` или `CachedMessageSource`) → `MessageCollection` → `Engine` (выполняет обработчики `Stats\StatHandler`) → `Renderer\HtmlRenderer` (Twig) → `var/html/<key>.html`.

- `src/Messages/ExportMessageSource.php`: читает все файлы папки экспорта (natsort, пропускает подпапки и `.DS_Store`), парсит через symfony/dom-crawler по CSS-селекторам. Типы медиа определяются декларативной картой `MEDIA_SELECTORS` (порядок = приоритет; photo/sticker — по title внутри `div.media_photo`). Возвращает сообщения **только с `reply_to_id`** — релинк делает `MessageCollection`.
- `src/Messages/CachedMessageSource.php`: сериализует сообщения через JMS в `var/cache/parsers/<signature>.json`. Ключ кэша — **сигнатура содержимого папки** (имена файлов + mtime): любое изменение экспорта пересобирает кэш автоматически. `reply_to_message` не сериализуется (релинк после загрузки), поэтому кэш не раздувается рекурсией.
- `src/Messages/MessageCollection.php`: value-object над коллекцией сообщений (ключ `message_id`); в конструкторе вызывает `ReplyLinker::link()` (проставляет `reply_to_message` по `reply_to_id`). Здесь живут все агрегации: `countByUser()`, `strlenByUser()`, `medianStrlenByUser()`, `countByDate($format)`, `medianByDate($format)`, `countByUserDayHour()`, `countByType()`, `countByTypeAndUser()`, `repliesMatrix()`, `usernames()`, `firstByDate()`, `strlenTotal()`.
- `src/Stats/`: интерфейс `StatHandler` (`key()`, `description()` по-русски, `template()`, `handle(MessageCollection): array`), база `AbstractStatHandler`, 13 обработчиков — чистые функции без состояния, возвращают массивы-контракты для шаблонов. `Stats/Words/WordsAnalyzer.php` — подсчёт популярных слов (mb_ereg/mb_split, без внешних стринг-библиотек).
- `src/Engine.php`: последовательно вызывает `handle()` в порядке регистрации; при `--debug` замеряет каждый обработчик.
- `src/Renderer/HtmlRenderer.php`: рендер `index.twig` + запись файла.

## Добавление новой статистики (3 шага, все обязательны)

1. Новый класс в `src/Stats/`, реализующий `StatHandler` (обычно наследует `AbstractStatHandler`): `key()`, `description()` (по-русски), `handle(MessageCollection): array`. Если агрегация новая — добавить метод в `MessageCollection`.
2. Шаблон `src/templates/default/handlers/<key>.twig` — получает `data`, `description`, `templateKey`; доступен фильтр `md5` (используйте его для уникальных id аккордеонов, как в существующих шаблонах). В `index.twig` инклюд идёт с `ignore missing`: отсутствующий шаблон даёт **пустую панель без ошибки**.
3. **Зарегистрировать обработчик** в массиве `handlers()` в `src/Console/GenerateCommand.php`. Забыть про это — самая частая ошибка: статистика просто не считается. **Порядок массива = порядок панелей на странице.**

## Нюансы и конвенции

- Данные для статистики лежат в `config/`: `users.php` — карта «ник → настоящее имя» для `UserHelper::norm()` (дополнять для новых чатов), `words.php` — стоп-слова и алиасы популярных слов.
- Имена отображаются через `UserHelper::norm($username, $namesMap)` (срезает ` via @channel`, применяет карту из `config/users.php`).
- Сущности (`src/Entity/`) — простые DTO с публичными свойствами, используются JMS-сериализатором; наличие медиа определяется по непустым полям (Photo, Sticker, Video, ...), `MessageType` — маркерный интерфейс.
- Обработчики получают `MessageCollection` — эталонный паттерн см. в `src/Messages/MessageCollection.php` и простых обработчиках вроде `CountRepliesByUserHandler`.
- Twig настраивается с `auto_reload => !$debug` (инвертировано, намеренно), cache — `var/twig/cache`.
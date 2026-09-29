# Спека: редизайн chatStats (глубокий редизайн, PHP 8.5)

Дата: 2026-09-29
Статус: черновик на ревью

## 1. Контекст и цели

Личный PHP CLI-инструмент `chatStats` парсит HTML-экспорты чатов Telegram Desktop и генерирует одну статическую HTML-страницу статистики на чат. Текущее состояние (см. AGENTS.md): запуск через `php public/run.php` с захардкоженными путями, логика композиции в глобальных функциях `public/functions.php`, движок-«швейцарский нож» `StatMachine`, кэш, привязанный только к ключу, 9-этажный `if/elseif` для типов медиа, обработчики-классы с изменяемым состоянием, баги сортировки и мёртвый код.

Цели редизайна:

1. **Актуальная платформа**: PHP 8.5 (установлен 8.5.11), актуальные мажорные версии зависимостей (symfony 7, twig 3, jms/serializer 3, tightenco/collect 9). Убрать `str/str` и `symfony/var-dumper`.
2. **Настоящий CLI**: `bin/chatstats` на symfony/console; без хардкода путей и флагов; понятные ошибки вместо молчаливого выхода.
3. **Чистая архитектура**: разделение на слои — источники сообщений, слой запросов (`MessageCollection`), чистые обработчики статистики (без состояния), движок выполнения, рендер.
4. **Правильный кэш**: инвалидация по содержимому директории экспорта; без рекурсивной сериализации реплаев (кэш 170 МБ → небольшой).
5. **Исправление известных багов** (перечислены в п. 8).
6. **Ускорение добавления статистики**: одна точка регистрации, тонкие обработчики.

Ограничения: **без тестов и CI** (проверка = запуск и визуальная сверка HTML), комментарии/описания/шаблоны — на русском, личный инструмент без публичного API.

## 2. Целевая структура проекта

```
bin/chatstats                        — точка входа CLI (исполняемый скрипт, shebang)
config/
  words.php                          — стоп-слова и словарь алиасов для подсчёта слов
  users.php                          — карта «ник → настоящее имя»
src/
  Application.php                    — композиция (конфиги, источники, обработчики, движок, рендер)
  Console/GenerateCommand.php        — команда generate
  Engine.php                         — выполнение обработчиков (бывш. StatMachine.calculate)
  Renderer/HtmlRenderer.php          — рендер Twig + запись файла (бывш. StatMachine.render)
  Messages/
    MessageSource.php                — интерфейс: getMessages(): MessageCollection
    ExportMessageSource.php          — бывш. ExportParser
    CachedMessageSource.php          — бывш. CachedParser
    ReplyLinker.php                  — простановка reply_to_message по reply_to_id
    MessageCollection.php            — value-object над Collection со всеми запросами
  Stats/
    StatHandler.php                  — интерфейс обработчика
    AbstractStatHandler.php          — база (template() из key())
    …13 обработчиков (бывш. StatHandlers/*)
    Words/WordsAnalyzer.php          — разбор текста и подсчёт слов (без Str\Str)
  UserHelper.php / DateHelper.php    — утилиты, реализация на mb_*/preg/match
  Entity/*                           — DTO без изменений
src/templates/default/…              — те же шаблоны; правки только в index.twig
```

Удаляются: `public/run.php`, `public/functions.php` (вместе с записью в composer `autoload.files`), `src/Parsers/*`, `src/StatMachine.php`, `src/StatHandlers/*`.

## 3. composer.json

- `"php": ">=8.4"`, `"ext-mbstring": "*"`
- require:
  - `illuminate/collections ^12` (преемник заброшенного tightenco/collect; API совместим, работает без E_DEPRECATED на PHP 8.5)
  - `symfony/console ^7.0`
  - `symfony/css-selector ^7.0` (обязателен: dom-crawler 7 сам его больше не тянет, а `Crawler::filter()` без него падает)
  - `symfony/dom-crawler ^7.0`
  - `symfony/stopwatch ^7.0` (переносится из require-dev — используется в обычном режиме `--debug`)
  - `twig/twig ^3.0`
  - `jms/serializer ^3.30`
- удаляются: `str/str`, `symfony/var-dumper`
- `autoload`: только psr-4 `ChatStats\ => src`; блок `files` удаляется
- `require-dev`: пуст (или удалить блок)

## 4. CLI (bin/chatstats, symfony/console)

Команда `generate`:

```
php bin/chatstats generate <dir> [--key=<key>] [--title=<title>] [--no-cache] [--rebuild] [--debug]
```

- `dir` (обязательный): папка экспорта. Не существует → сообщение об ошибке и exit-код 1 (без молчаливого выхода).
- `--key` (по умолчанию basename(dir)): ключ для имени выходного файла/заголовка.
- `--title` (по умолчанию = key): заголовок страницы.
- `--no-cache`: не читать и не писать кэш.
- `--rebuild`: пересобрать кэш принудительно.
- `--debug`: вывод таймингов Stopwatch в консоль (компонент Table) и режим Twig без auto_reload (как сейчас).
- Результат: `var/html/<key>.html`.

## 5. Источники сообщений

### MessageSource (интерфейс)

```php
interface MessageSource {
    public function getMessages(): MessageCollection;
}
```

### ExportMessageSource (бывш. ExportParser)

- Читает все файлы папки (natsort, пропуск подпапок и `.DS_Store`), парсит через dom-crawler.
- Убирается `VarDumper::dump()` (строка 82 текущего кода).
- Типы медиа: вместо цепочки `if/elseif` — декларативная карта `[CSS-селектор => поле сущности]` с фиксированным порядком приоритета: photo, sticker, video, voice, location, audio, poll, document, animation, contact. Добавляется парсинг `Contact` (селектор `.media_contact`) — поле `contact` сейчас объявлено в `Message`, но никогда не заполняется.
- Строковые операции на `mb_*`/`str_contains`/`preg` вместо `Str\Str`.
- Возвращает сообщения **с заполненным только `reply_to_id`** (релинк делает централизованно `MessageCollection`).
- Результат — `Collection` с ключом `message_id`.

### CachedMessageSource (бывш. CachedParser)

- **Сигнатура кэша**: `sha1` по списку (относительное имя файла + `filemtime`) всех файлов экспорта → инвалидация при любом изменении данных, а не по `dataKey`. Файл: `var/cache/parsers/<signature>.json`.
- Сериализуются сообщения с `reply_to_id` и **без** `reply_to_message` (он никогда не заполняется до записи кэша) → нет рекурсивной сериализации, кэш на порядки меньше.
- После десериализации релинк реплаев выполняет общий `ReplyLinker` (см. 6).
- `--no-cache` → прямой парсинг, `--rebuild` → пересборка.
- Старые файлы кэша по ключу `dataKey` больше не создаются; при миграции удаляются вручную.

## 6. MessageCollection и ReplyLinker

### ReplyLinker

```php
final class ReplyLinker {
    public static function link(Collection $messages): void;
}
```

Идем по сообщениям, собирает `reply_to_id => [message_id, ...]` и проставляет `reply_to_message` (по ключам коллекции). Логика — из текущего `ExportParser::normalizeReplies()`.

### MessageCollection

`final readonly class MessageCollection` — оборачивает `Collection` (ключ `message_id`), в конструкторе вызывает `ReplyLinker::link()`. Методы запросов (возвращают те же структуры, что сегодня отдают обработчики — контракт с шаблонами не меняется):

- `count(): int`
- `firstByDate(): ?Message` — первое по дате, безопасно на пустой коллекции (баг `FirstMessage` исправлен)
- `usernames(): array` — уникальные имена, отсортированы (из CountTotalUsers)
- `countByUser(): array` — username => count, sortDesc (из CountTotalByUser)
- `strlenByUser(): array` (из StrlenByUser)
- `medianStrlenByUser(): array` — с **исправлением сортировки**: сравнение чисел, а не строк `number_format` (баг «9» > «10»)
- `countByDate(string $fmt): array` — countBy + sortKeys (из CountTotalByDate)
- `medianByDate(string $fmt): float` (из MedianByDate)
- `countByUserDayHour(): array` — структура из CountUsersByDayNHours (дни недели через DateHelper, часы 0–23 с нулями)
- `countByType(): array` и `countByTypeAndUser(): array` — по полям-медиа с фолбэком `message` (из CountByTypeAndUser)
- `repliesMatrix(): array` — из CountRepliesByUser
- `messages(): Collection` — доступ к сырой коллекции для нестандартных фильтров

## 7. Обработчики (чистая архитектура)

### Интерфейс

```php
interface StatHandler {
    public function key(): string;
    public function description(): string;  // по-русски
    public function template(): string;     // key() . '.twig'
    public function handle(MessageCollection $messages): array;
}
```

`AbstractStatHandler` реализует `template()`. Обработчики — без полей-состояний; результат вычисляется в `handle()` из единственного аргумента. 13 обработчиков соответствуют текущим 1:1 и возвращают прежние структуры данных:

| Новый класс | Бывший | Данные |
|---|---|---|
| FirstMessageHandler | FirstMessage | message/datetime/from |
| CountTotalHandler | CountTotal | count |
| TotalStrlenHandler | TotalStrlen | strlen |
| MedianByDateHandler | MedianByDate | year/months/days/weeks/hours |
| CountTotalByDateHandler | CountTotalByDate | year/weeks/all_days/all_months/monthsTop10/daysTop10 |
| CountTotalByUserHandler | CountTotalByUser | user => count |
| StrlenByUserHandler | StrlenByUser | user => strlen |
| UserMedianMessageLengthHandler | UserMedianMessageLength | user => средняя длина (число, без number_format-строк) |
| CountTotalUsersHandler | CountTotalUsers | список имён |
| CountRepliesByUserHandler | CountRepliesByUser | матрица реплаев |
| CountUsersByDayNHoursHandler | CountUsersByDayNHours | hash/total/dayOfWeek/dayOfWeekByHour |
| CountByTypeAndUserHandler | CountByTypeAndUser | тип => user => count |
| PopularWordsHandler | PopularWordsCount | list/total/users/uniq |

**Регистрация**: один массив в `GenerateCommand` (порядок страницы виден и управляем). Авто-дискавери не делаем.

### PopularWordsHandler + WordsAnalyzer

- `config/words.php` возвращает: `stop_words` (массив, из константы STOP_WORDS), `aliases` (слово => [алиасы], из `wordList`), `word_len_min` (3).
- `WordsAnalyzer` (в `src/Stats/Words/`): методы подготовки строки и подсчёта (из текущих `prepareString`, `countWords`, `countUserWord`, `countUserWordList`, `countUniqWordsByUser`) на `mb_*`/`preg` вместо `Str\Str`. Логика и результат — идентичны текущим.
- `PopularWordsHandler` — тонкая обёртка: берёт конфиг, зовёт анализатор, отдаёт `['list' => …, 'total' => …, 'users' => …, 'uniq' => …]`.

### UserHelper / DateHelper

- `UserHelper::norm()`: карта имён из `config/users.php`; срезание ` via @channel` через `mb_strrpos`/`mb_substr`; цепочка replace по карте.
- `DateHelper`: карта дней недели (как сейчас) + карта месяцев `['01' => 'Январь', …]` (вместо switch из 12 кейсов в CountTotalByDate), доступ через метод `getMonthName(string $num): string`.

## 8. Движок и рендер

### Engine

```php
final class Engine {
    public function __construct(MessageCollection $messages, array $handlers, ?Stopwatch $stopwatch = null);
    /** @return array<string, array> key => data */
    public function calculate(): array;
}
```

Последовательно вызывает `handle()` в порядке массива; при наличии Stopwatch замеряет каждый обработчик (секция `calc`). Хранит только результат — обработчики не мутируют состояние.

### HtmlRenderer

```php
final class HtmlRenderer {
    public function __construct(Environment $twig);
    public function render(string $outputFile, string $title, array $results): void;
}
```

- `results`: `[key => ['description' => …, 'data' => …, 'template' => …]]` — собирается из обработчиков и результатов Engine.
- `index.twig`: итерация по результатам, `description` упоминается один раз (в заголовке аккордеона), include шаблона `handlers/<template>` с `ignore missing` (пустая панель без ошибки — поведение сохраняется), `md5`-фильтр остаётся для id.
- Twig-окружение собирается в `GenerateCommand` (loader — `src/templates/default`, cache — `var/twig/cache`); при `--debug` — `auto_reload=false` (поведение текущего инвертированного флага сохраняется).
- Рендер + `file_put_contents` — в одном классе (запись файла — единственная ответственность рендера).

## 9. Исправляемые баги

1. `UserMedianMessageLength`: сортировка строк из `number_format` («9» > «10») → сортировка по float.
2. `FirstMessage`: падение на пустом чате → `firstByDate(): ?Message`, обработчик отдаёт безопасный фолбэк.
3. `ExportParser`: `VarDumper::dump()` в проде → удалён.
4. Несуществующая директория данных: молчаливый выход → ошибка CLI и exit 1.
5. Кэш по `dataKey` (устаревшие данные молча) → сигнатура по содержимому экспорта.
6. Рекурсивная сериализация `reply_to_message` (кэш 170 МБ) → сериализуется только `reply_to_id`, релинк после загрузки.
7. Мёртвый код: `StatMachine::getStats()`, `getDiscriptionByKey()`, переменная `$data` в `makeData()` → удалены вместе со старыми классами.
8. `Contact` объявлен, но не парсится → парсится через `.media_contact`.

## 10. Безопасность выполнения

- Пайплайн и **контракты данных с шаблонами не меняются**: каждый обработчик возвращает те же структуры. Разница — только в механике.
- Правки шаблонов: только `index.twig` (итерация по результатам, одно упоминание description). Шаблоны обработчиков не трогаются (кроме, возможно, мелочей, если поле `handler.getKey` сменится на `handler.key` — в index.twig).
- PHP 8.5: синтаксис современный (readonly, promotion, match, str_*), но без излишеств: сущности остаются публичными DTO (нужны JMS-сериализатору).

## 11. Порядок работ и верификация (без автотестов)

Общий принцип: каждый шаг завершается запуском `bin/chatstats` (или его предшественника) и сверкой результата.

1. **Базлайн**: до правок сгенерировать текущий HTML по реальным данным (`public/data/sts`) доступным способом (старый код на PHP 8.5 или в docker php:7.4), сохранить копию `var/html/baseline-sts.html`.
2. `composer.json` → актуальные зависимости, `composer update`, проверка установки на PHP 8.5.
3. Слой `Messages/`: `MessageSource`, `ReplyLinker`, `MessageCollection`, `ExportMessageSource` (новые классы рядом со старыми).
4. `Engine`, `HtmlRenderer`, `Application`, `GenerateCommand`, `bin/chatstats` — первый запуск на реальных данных.
5. Обработчики по одному: переезд на `StatHandler` + `MessageCollection`, сверка цифр с базлайном.
6. `WordsAnalyzer` + `config/words.php`; `UserHelper` + `config/users.php`.
7. `CachedMessageSource` с новой сигнатурой кэша; проверка пересборки и размера.
8. Удаление старых файлов, финальная чистка composer, `composer install` с нуля (чистый `vendor/`).
9. Обновление **AGENTS.md**: новый запуск, новая архитектура, обновлённый процесс добавления статистики (класс → шаблон → запись в массив в `GenerateCommand`).

Критерий готовности: `php bin/chatstats generate public/data/sts --key sts --title "…"` без `--debug` и с `--debug`; страница содержит те же цифры, что базлайн; кэш пересобирается и весит заметно меньше 170 МБ.
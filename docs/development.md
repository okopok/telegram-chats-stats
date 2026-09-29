# Разработка chatStats

## Добавление новой статистики (3 шага, все обязательны)

1. **Новый класс** в `src/Stats/`, реализующий `StatHandler` (обычно наследует `AbstractStatHandler`): `key()`, `description()` (по-русски), `handle(MessageCollection): array`. Если агрегация новая — добавить её в `MessageCollection`.
2. **Шаблон** `src/templates/default/handlers/<key>.twig` — получает `data`, `description`, `templateKey`; доступен фильтр `md5` (используйте его для уникальных id аккордеонов, как в существующих шаблонах). В `index.twig` инклюд идёт с `ignore missing`: отсутствующий шаблон даёт **пустую панель без ошибки**.
3. **Зарегистрировать обработчик** в массиве `handlers()` в `src/Console/GenerateCommand.php`. Забыть про это — самая частая ошибка: статистика просто не считается. **Порядок массива = порядок панелей на странице.**

### Как добавить агрегацию в `MessageCollection`

`MessageCollection` — **ленивый однопроходный агрегатор**: первое обращение к любому методу проходит по сообщениям один раз и наполняет кэш `aggregated`. Новая агрегация = счётчик/структура в `aggregate()` (единый проход) + метод-геттер, читающий из кэша. **Не создавайте** методы, которые сами итерируют сообщения повторно — это удваивает проходы. Итерация — `iterate()` (коллекция при `--no-cache`, чанки кэша иначе); сообщения в памяти не хранятся.

После изменения — обновить соответствующие разделы `docs/` (см. [правило документации](README.md#правило-документации)).

## Реестр обработчиков

Порядок в массиве `handlers()` в `GenerateCommand` (он же порядок панелей на странице):

| # | Класс | key | Шаблон |
|---|---|---|---|
| 1 | `FirstMessageHandler` | `firstMessage` | `firstMessage.twig` |
| 2 | `CountTotalHandler` | `countTotal` | `countTotal.twig` |
| 3 | `TotalStrlenHandler` | `totalStrlen` | `totalStrlen.twig` |
| 4 | `MedianByDateHandler` | `medianByDate` | `medianByDate.twig` |
| 5 | `CountTotalByUserHandler` | `countTotalByUser` | `countTotalByUser.twig` |
| 6 | `StrlenByUserHandler` | `strlenByUser` | `strlenByUser.twig` |
| 7 | `UserMedianMessageLengthHandler` | `userMedianMessageLength` | `userMedianMessageLength.twig` |
| 8 | `CountTotalUsersHandler` | `countTotalUsers` | `countTotalUsers.twig` |
| 9 | `CountTotalByDateHandler` | `countTotalByDate` | `countTotalByDate.twig` |
| 10 | `CountRepliesByUserHandler` | `countRepliesByUser` | `countRepliesByUser.twig` |
| 11 | `CountUsersByDayNHoursHandler` | `countUsersByDayNHours` | `countUsersByDayNHours.twig` |
| 12 | `CountByTypeAndUserHandler` | `countByTypeAndUser` | `countByTypeAndUser.twig` |
| 13 | `PopularWordsHandler` | `popularWordsCount` | `popularWordsCount.twig` |
| 14 | `TopReactionsHandler` | `topReactions` | `topReactions.twig` |
| 15 | `TopReactedMessagesHandler` | `topReactedMessages` | `topReactedMessages.twig` |
| 16 | `EditedStatsHandler` | `editedStats` | `editedStats.twig` |
| 17 | `ForwardedStatsHandler` | `forwardedStats` | `forwardedStats.twig` |
| 18 | `TopSitesHandler` | `topSites` | `topSites.twig` |
| 19 | `MediaWeightHandler` | `mediaWeight` | `mediaWeight.twig` |
| 20 | `PollStatsHandler` | `pollStats` | `pollStats.twig` |
| 21 | `ReplySpeedHandler` | `replySpeed` | `replySpeed.twig` |
| 22 | `ChronotypeHandler` | `chronotypes` | `chronotypes.twig` |
| 23 | `HeatmapHandler` | `heatmap` | `heatmap.twig` |
| 24 | `StreakHandler` | `streaks` | `streaks.twig` |
| 25 | `LastActivityHandler` | `lastActivity` | `lastActivity.twig` |
| 26 | `SelfRepliesHandler` | `selfReplies` | `selfReplies.twig` |
| 27 | `LongestMessagesHandler` | `longestMessages` | `longestMessages.twig` |

Эталонный паттерн обработчика — `src/Messages/MessageCollection.php` и простые обработчики вроде `CountRepliesByUserHandler`.

## Шаблоны Twig

- Корень шаблонов: `src/templates/default/`.
- `index.twig` — каркас страницы: инклюдит панели обработчиков с `ignore missing` (нет шаблона → пустая панель без ошибки).
- Панели: `src/templates/default/handlers/<key>.twig`.
- Переменные панели: `data`, `description`, `templateKey`.
- Фильтр `md5` — для уникальных id аккордеонов.
- Кэш Twig: `var/twig/cache`; `auto_reload => !$debug` (инвертировано намеренно — см. [Архитектуру](architecture.md#режим-отладки-debug)).

### Визуальный каркас (`index.twig`)

Страница не зависит от внешних CDN-библиотек стилей: Bootstrap и jQuery не используются, весь CSS инлайновый (тёмная/светлая тема через CSS-переменные + переключатель с сохранением в `localStorage`, адаптивная сетка, аккордеоны на своём JS). Chart.js подключается с CDN — им рисуются canvas-графики.

- **Дашборд**: `GenerateCommand::buildDashboard($results)` собирает KPI-карточки (сообщения, участники, дни, медиа, реакции, знаки) и «забавные факты» из готовых результатов обработчиков — без повторных проходов по сообщениям. Данные передаются в `HtmlRenderer::render(..., $dashboard)` и отображаются в `index.twig` над аккордеонами.
- **Тепловая карта**: `HeatmapHandler` рисует таблицу 7×24 («день недели × час») с интенсивностью цвета от `activityHeatmap()` в `MessageCollection`.
- **Облако слов**: блок в `popularWordsCount.twig` — размер шрифта пропорционален частоте слова.
- **Граф коммуникаций**: блок в `countRepliesByUser.twig` — SVG-граф «кто кому отвечает», рёбра строятся JS-ом из данных `repliesMatrix()`.
- Новые канвасы/скрипты: вместо jQuery использовать `document.addEventListener('DOMContentLoaded', ...)` (нативный DOM-ready).

## Конфиги

- **`config/users.php`** — карта «ник → настоящее имя» для `UserHelper::norm()`. Дополнять для новых чатов.
- **`config/nicks.php`** — карта «имя в чате → ник в Telegram (без @)» для опции `--nicks`: заменяет имена на ники со ссылкой на профиль. Заполняется вручную (username в экспорте нет).
- **`config/words.php`** — стоп-слова и алиасы популярных слов для `WordsAnalyzer`.

## Конвенции

- Комментарии в коде, описания обработчиков, шаблоны и документация — **на русском**.
- Сущности (`src/Entity/`) — простые DTO с публичными свойствами (читаются нативным `serialize` для кэша). Наличие медиа определяется по непустым полям (`Photo`, `Sticker`, `Video`, ...); `MessageType` — маркерный интерфейс. Новые атрибуты сообщения (реакции, правки, пересылки, веб-превью) — поля `Message`; детали медиа (длительность голосовых/видео, размер документов, вопрос/ответы опроса) — поля соответствующих сущностей. `Reaction` — обычный DTO, **не** `MessageType` (реакция не медиа и не должна попадать в `typeOf`).
- Обработчики — чистые функции без состояния: получают `MessageCollection`, возвращают массив-контракт для шаблона.
- Имена пользователей отображаются через `UserHelper::norm($username, $namesMap)` (срезает ` via @channel`, применяет карту из `config/users.php`).
- **Парсер (`ExportMessageSource`) поддерживает два поколения разметки экспорта Telegram** (старую и новую 2025+). При добавлении нового селектора медиа в `MEDIA_SELECTORS` старые селекторы не удаляются — карта покрывает обе разметки, приоритет = порядок.
- **Имена пользователей в шаблонах выводятся через фильтр `user_link`** (HTML-ссылка на профиль при `--nicks`), а метки canvas-графиков — через фильтр `nicks` (ники без ссылок). Новые панели с именами должны использовать эти фильтры. Глобалы: `nicksEnabled`, `nicksMap`. Фильтр `duration` форматирует секунды в «1 мин 23 с».
- **Память**: не сохраняйте коллекции сообщений целиком и не вызывайте `sortBy`/`groupBy` над всей коллекцией — агрегации считаются одним проходом в `MessageCollection::aggregate()`. Изменения затрагивающие объём памяти — проверяйте на большом экспорте (`--limit` меньше не покажет пик).
- Тестов нет; проверка = запуск и визуальная сверка сгенерированного HTML. Для быстрых регресс-проверок после изменений кода используйте `--limit=<N>` (например, 2000) — не гонять весь экспорт.
- `bin/chatstats` — исполняемый скрипт с shebang; поднимает `memory_limit` до 2G (стартовый полный прогон ~1M сообщений до кэша результатов — до ~350MB, запас на пики сериализации).
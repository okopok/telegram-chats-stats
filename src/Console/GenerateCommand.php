<?php

namespace ChatStats\Console;

use ChatStats\Engine;
use ChatStats\Messages\CachedMessageSource;
use ChatStats\Messages\ExportMessageSource;
use ChatStats\Renderer\HtmlRenderer;
use ChatStats\ResultsCache;
use ChatStats\Stats\CountByTypeAndUserHandler;
use ChatStats\Stats\CountRepliesByUserHandler;
use ChatStats\Stats\CountTotalByDateHandler;
use ChatStats\Stats\CountTotalByUserHandler;
use ChatStats\Stats\CountTotalHandler;
use ChatStats\Stats\CountTotalUsersHandler;
use ChatStats\Stats\CountUsersByDayNHoursHandler;
use ChatStats\Stats\ChronotypeHandler;
use ChatStats\Stats\EditedStatsHandler;
use ChatStats\Stats\FirstMessageHandler;
use ChatStats\Stats\ForwardedStatsHandler;
use ChatStats\Stats\HeatmapHandler;
use ChatStats\Stats\LastActivityHandler;
use ChatStats\Stats\LongestMessagesHandler;
use ChatStats\Stats\MedianByDateHandler;
use ChatStats\Stats\MediaWeightHandler;
use ChatStats\Stats\PollStatsHandler;
use ChatStats\Stats\PopularWordsHandler;
use ChatStats\Stats\ReplySpeedHandler;
use ChatStats\Stats\SelfRepliesHandler;
use ChatStats\Stats\StatHandler;
use ChatStats\Stats\StreakHandler;
use ChatStats\Stats\StrlenByUserHandler;
use ChatStats\Stats\TopReactedMessagesHandler;
use ChatStats\Stats\TopReactionsHandler;
use ChatStats\Stats\TopSitesHandler;
use ChatStats\Stats\TotalStrlenHandler;
use ChatStats\Stats\UserMedianMessageLengthHandler;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use function basename;
use function count;
use function dirname;
use function is_dir;
use function preg_replace;
use function round;
use function sprintf;

/**
 * Команда generate: строит страницу статистики по экспорту чата.
 */
final class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('generate')
            ->setDescription('Генерация страницы статистики по HTML-экспорту чата')
            ->addArgument('dir', InputArgument::REQUIRED, 'Папка с HTML-экспортом чата')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'Ключ: имя выходного файла (по умолчанию имя папки)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Заголовок страницы (по умолчанию ключ)')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Не читать и не писать кэш сообщений')
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'Пересобрать кэш сообщений')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Обработать только первые N сообщений (для быстрых проверок; кэш отдельный)')
            ->addOption('nicks', null, InputOption::VALUE_NONE, 'Вместо имён выводить ники со ссылкой на профиль (карта: config/nicks.php)')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Показать тайминги обработчиков');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $dir */
        $dir = $input->getArgument('dir');
        if (!is_dir($dir)) {
            throw new RuntimeException(sprintf('Папка с экспортом не найдена: %s', $dir));
        }

        /** @var string|null $keyOption */
        $keyOption = $input->getOption('key');
        $key = $keyOption ?: basename($dir);
        /** @var string|null $titleOption */
        $titleOption = $input->getOption('title');
        $title = $titleOption ?: $key;
        $cacheEnabled = !$input->getOption('no-cache');
        $rebuild = (bool)$input->getOption('rebuild');
        $debug = (bool)$input->getOption('debug');
        /** @var int|string|null $limitOption */
        $limitOption = $input->getOption('limit');
        $limit = $limitOption !== null ? max(0, (int)$limitOption) : 0;
        /** @var bool $nicks */
        $nicks = (bool)$input->getOption('nicks');

        $namesMap = require dirname(__DIR__, 2) . '/config/users.php';
        $nicksMap = require dirname(__DIR__, 2) . '/config/nicks.php';
        $exportSource = new ExportMessageSource($dir, $namesMap, $limit);
        $source = new CachedMessageSource($exportSource, $dir, $cacheEnabled, $rebuild, $limit);
        $stopwatch = $debug ? new Stopwatch() : null;

        // Кэш результатов: повторные прогоны не десериализуют сообщения и
        // не выполняют обработчики — читается готовый serialize-файл.
        // При --debug результат считается заново (нужны честные тайминги).
        $resultsCache = new ResultsCache();
        $results = null;
        if ($cacheEnabled && !$debug) {
            $results = $resultsCache->get($resultsCache->signature($source->signature()));
            if ($results !== null) {
                $output->writeln('Результаты загружены из кэша (var/cache/results)');
            }
        }
        if ($results === null) {
            $messages = $source->getMessages();
            $engine = new Engine($messages, $this->handlers(), $stopwatch);
            $results = $engine->calculate();
            if ($cacheEnabled && !$debug) {
                $resultsCache->put($resultsCache->signature($source->signature()), $results);
            }
            // Сообщения больше не нужны: освобождаем память до рендера.
            unset($messages, $engine);
        }

        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/src/templates/default'),
            [
                'cache' => dirname(__DIR__, 2) . '/var/twig/cache',
                'auto_reload' => !$debug,
            ]
        );
        $twig->addFilter(new TwigFilter('md5', 'md5'));
        $twig->addGlobal('nicksEnabled', $nicks);
        $twig->addGlobal('nicksMap', $nicksMap);

        $twig->addFilter(new TwigFilter('user_link', static function (string $name) use ($nicks, $nicksMap) {
            if (!$nicks) {
                return $name;
            }
            $nick = $nicksMap[$name] ?? null;
            if ($nick === null || $nick === '') {
                return $name;
            }
            $href = 'https://t.me/' . preg_replace('/^@/', '', $nick);
            return sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">@%s</a>', $href, $nick);
        }, ['is_safe' => ['html']]));

        // Для canvas-графиков (Chart.js): ссылки невозможны, поэтому имена
        // заменяются на ники без разметки.
        $twig->addFilter(new TwigFilter('nicks', static function (array $keys) use ($nicks, $nicksMap) {
            if (!$nicks) {
                return $keys;
            }
            return array_map(static function (string $key) use ($nicksMap) {
                $nick = $nicksMap[$key] ?? null;
                return $nick !== null && $nick !== '' ? '@' . $nick : $key;
            }, $keys);
        }));

        // Секунды → «1 мин 23 с» / «45 с» / «2 ч 5 мин».
        $twig->addFilter(new TwigFilter('duration', static function (int $seconds): string {
            if ($seconds <= 0) {
                return '0 с';
            }
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            $secs = $seconds % 60;
            $parts = [];
            if ($hours > 0) {
                $parts[] = $hours . ' ч';
            }
            if ($minutes > 0) {
                $parts[] = $minutes . ' мин';
            }
            if ($secs > 0 || $parts === []) {
                $parts[] = $secs . ' с';
            }
            return implode(' ', $parts);
        }));

        $renderer = new HtmlRenderer($twig);
        $outputFile = dirname(__DIR__, 2) . '/var/html/' . $key . '.html';
        $renderer->render($outputFile, $title, $this->buildResults($results), $this->buildDashboard($results));

        $output->writeln(sprintf('Готово: %s (%d панелей)', $outputFile, count($results)));
        if ($limit > 0) {
            $output->writeln(sprintf('<comment>Внимание: обработаны только первые %d сообщений (проверочный прогон)</comment>', $limit));
        }

        if ($debug && $stopwatch !== null) {
            $this->printTimings($output, $stopwatch);
        }

        return Command::SUCCESS;
    }

    /**
     * Реестр обработчиков: порядок определяет порядок панелей на странице.
     *
     * @return StatHandler[]
     */
    private function handlers(): array
    {
        return [
            new FirstMessageHandler(),
            new CountTotalHandler(),
            new TotalStrlenHandler(),
            new MedianByDateHandler(),
            new CountTotalByUserHandler(),
            new StrlenByUserHandler(),
            new UserMedianMessageLengthHandler(),
            new CountTotalUsersHandler(),
            new CountTotalByDateHandler(),
            new CountRepliesByUserHandler(),
            new CountUsersByDayNHoursHandler(),
            new CountByTypeAndUserHandler(),
            new PopularWordsHandler(),
            // Новые панели: реакции, контент-хиты, правки, пересылки, ссылки,
            // медиа-вес, опросы, скорость ответа, хронотипы, серии,
            // последняя активность, само-ответы, длинные сообщения.
            new TopReactionsHandler(),
            new TopReactedMessagesHandler(),
            new EditedStatsHandler(),
            new ForwardedStatsHandler(),
            new TopSitesHandler(),
            new MediaWeightHandler(),
            new PollStatsHandler(),
            new ReplySpeedHandler(),
            new ChronotypeHandler(),
            new HeatmapHandler(),
            new StreakHandler(),
            new LastActivityHandler(),
            new SelfRepliesHandler(),
            new LongestMessagesHandler(),
        ];
    }

    /**
     * @param array<string, array> $results key => data
     * @return array<string, array{description: string, data: array, template: string}>
     */
    private function buildResults(array $results): array
    {
        $built = [];
        foreach ($this->handlers() as $handler) {
            $key = $handler->key();
            if (!array_key_exists($key, $results)) {
                continue;
            }
            $built[$key] = [
                'description' => $handler->description(),
                'data' => $results[$key],
                'template' => $handler->template(),
            ];
        }
        return $built;
    }

    /**
     * Сводка для KPI-карточек и «забавных фактов» наверху страницы.
     * Собирается только из готовых результатов обработчиков (без повторных
     * проходов по сообщениям).
     *
     * @param array<string, array> $results key => data
     * @return array<string, mixed>
     */
    private function buildDashboard(array $results): array
    {
        $total = (int)($results['countTotal']['count'] ?? 0);

        // Диапазон жизни чата: от первого сообщения до последнего (дни).
        $firstDate = (int)($results['firstMessage']['datetime'] ?? 0);
        $lastDate = 0;
        $lastActivity = $results['lastActivity']['by_user'] ?? [];
        if ($lastActivity !== []) {
            $lastDate = (int)$lastActivity[0]['date'];
        }
        $daysRange = $firstDate > 0 && $lastDate > $firstDate
            ? (int)max(1, round(($lastDate - $firstDate) / 86400))
            : 0;

        // Медиа: все типы, кроме текстовых сообщений.
        $mediaTotal = 0;
        foreach ($results['countByTypeAndUser'] ?? [] as $type => $byUser) {
            if ($type === 'message') {
                continue;
            }
            $mediaTotal += array_sum($byUser);
        }

        $reactionTotals = $results['topReactions']['totals'] ?? [];
        $totalReactions = array_sum(array_column($reactionTotals, 'count'));
        $topEmojis = array_slice(array_column($reactionTotals, 'emoji'), 0, 3);

        $averagePerDay = $daysRange > 0 ? round($total / $daysRange, 1) : 0.0;

        $facts = $this->buildFacts($results);

        return [
            'total_messages' => $total,
            'total_users' => count($results['countTotalUsers'] ?? []),
            'total_strlen' => (int)($results['totalStrlen']['strlen'] ?? 0),
            'first_date' => $firstDate,
            'last_date' => $lastDate,
            'days_range' => $daysRange,
            'media_total' => $mediaTotal,
            'total_reactions' => $totalReactions,
            'top_emojis' => $topEmojis,
            'average_per_day' => $averagePerDay,
            'facts' => $facts,
        ];
    }

    /**
     * Фанфакты-карточки: собираются из готовых результатов обработчиков.
     *
     * @param array<string, array> $results key => data
     * @return array<int, string>
     */
    private function buildFacts(array $results): array
    {
        $facts = [];

        $longest = $results['longestMessages'][0] ?? null;
        if ($longest !== null) {
            $facts[] = sprintf(
                '📏 Самое длинное сообщение — %s знаков, автор %s',
                number_format((int)$longest['len'], 0, '.', ' '),
                $longest['from'] ?? '?'
            );
        }

        $streak = $results['streaks']['chat'] ?? null;
        if ($streak !== null && ($streak['days'] ?? 0) > 1) {
            $facts[] = sprintf(
                '🔥 Чат писал %s дней подряд (до %s)',
                number_format((int)$streak['days'], 0, '.', ' '),
                $streak['end'] ?? ''
            );
        }

        $edited = $results['editedStats'] ?? null;
        if ($edited !== null && (int)($edited['total_edited'] ?? 0) > 0) {
            $facts[] = sprintf(
                '✏️ Исправлено %s сообщений (%s%%)',
                number_format((int)$edited['total_edited'], 0, '.', ' '),
                isset($edited['total']) && $edited['total'] > 0
                    ? round((int)$edited['total_edited'] / (int)$edited['total'] * 100, 1)
                    : 0
            );
        }

        $replySpeed = $results['replySpeed']['by_user'] ?? [];
        if ($replySpeed !== []) {
            $fastest = $replySpeed[0];
            if ((int)$fastest['count'] >= 5) {
                $facts[] = sprintf(
                    '⚡️ Быстрее всех отвечает %s — медиана %d сек',
                    $fastest['user'] ?? '?',
                    (int)$fastest['median_sec']
                );
            }
        }

        // Пиковый час чата — из тепловой карты (уже посчитана).
        $peakHour = null;
        $peakCount = 0;
        foreach ($results['heatmap']['rows'] ?? [] as $row) {
            foreach ($row['hours'] ?? [] as $cell) {
                if ((int)$cell['count'] > $peakCount) {
                    $peakCount = (int)$cell['count'];
                    $peakHour = (int)$cell['hour'];
                }
            }
        }
        if ($peakHour !== null) {
            $facts[] = sprintf('🌙 Чат оживает чаще всего в %d:00', $peakHour);
        }

        $mediaWeight = $results['mediaWeight'] ?? null;
        if ($mediaWeight !== null && (int)($mediaWeight['voice_total'] ?? 0) > 0) {
            $minutes = (int)round((int)$mediaWeight['voice_total'] / 60);
            $facts[] = sprintf(
                '🎙 Наговорено голосовых на %s минут',
                number_format($minutes, 0, '.', ' ')
            );
        }

        $forwarded = $results['forwardedStats'] ?? null;
        if ($forwarded !== null && (int)($forwarded['total_forwarded'] ?? 0) > 0) {
            $facts[] = sprintf(
                '📨 Переслано сообщений: %s',
                number_format((int)$forwarded['total_forwarded'], 0, '.', ' ')
            );
        }

        $polls = $results['pollStats'] ?? null;
        if ($polls !== null && (int)($polls['total'] ?? 0) > 0) {
            $facts[] = sprintf(
                '📊 Создано опросов: %s',
                number_format((int)$polls['total'], 0, '.', ' ')
            );
        }

        $emojis = $results['topReactions']['totals'] ?? [];
        if ($emojis !== []) {
            $facts[] = sprintf('😁 Самый популярный эмодзи: %s', $emojis[0]['emoji'] ?? '');
        }

        $sites = $results['topSites']['totals'] ?? [];
        if ($sites !== []) {
            $facts[] = sprintf('🔗 Самый популярный сайт: %s', $sites[0]['site'] ?? '');
        }

        return $facts;
    }

    private function printTimings(OutputInterface $output, Stopwatch $stopwatch): void
    {
        $table = new Table($output);
        $table->setHeaders(['Событие', 'Время, сек', 'Память, Кб']);
        foreach ($stopwatch->getSectionEvents('calc') as $name => $event) {
            $table->addRow([
                $name,
                number_format($event->getDuration() / 1000, 3),
                number_format($event->getMemory() / 1024, 0),
            ]);
        }
        $table->render();
    }
}
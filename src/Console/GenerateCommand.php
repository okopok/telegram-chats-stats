<?php

namespace ChatStats\Console;

use ChatStats\Engine;
use ChatStats\Messages\ExportMessageSource;
use ChatStats\Renderer\HtmlRenderer;
use ChatStats\Stats\CountTotalByDateHandler;
use ChatStats\Stats\CountTotalHandler;
use ChatStats\Stats\FirstMessageHandler;
use ChatStats\Stats\MedianByDateHandler;
use ChatStats\Stats\StatHandler;
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
use function dirname;
use function is_dir;
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
        $debug = (bool)$input->getOption('debug');

        $namesMap = require dirname(__DIR__, 2) . '/config/users.php';
        $source = new ExportMessageSource($dir, $namesMap);

        $messages = $source->getMessages();

        $stopwatch = $debug ? new Stopwatch() : null;
        $engine = new Engine($messages, $this->handlers(), $stopwatch);
        $results = $engine->calculate();

        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/src/templates/default'),
            [
                'cache' => dirname(__DIR__, 2) . '/var/twig/cache',
                'auto_reload' => !$debug,
            ]
        );
        $twig->addFilter(new TwigFilter('md5', 'md5'));

        $renderer = new HtmlRenderer($twig);
        $outputFile = dirname(__DIR__, 2) . '/var/html/' . $key . '.html';
        $renderer->render($outputFile, $title, $this->buildResults($results));

        $output->writeln(sprintf('Готово: %s (%d панелей)', $outputFile, count($results)));

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
            new MedianByDateHandler(),
            new CountTotalByDateHandler(),
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
<?php

namespace ChatStats\Renderer;

use Twig\Environment;
use function file_put_contents;

/**
 * Рендерит страницу статистики через Twig и пишет HTML-файл.
 */
final class HtmlRenderer
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * @param string $outputFile путь к итоговому HTML
     * @param string $title заголовок страницы
     * @param array<string, array{description: string, data: array, template: string}> $results
     */
    public function render(string $outputFile, string $title, array $results): void
    {
        $content = $this->twig->render('index.twig', [
            'handlers' => $results,
            'title' => $title,
        ]);

        file_put_contents($outputFile, $content);
    }
}
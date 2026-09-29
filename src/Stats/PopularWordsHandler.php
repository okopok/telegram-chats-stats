<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;
use ChatStats\Stats\Words\WordsAnalyzer;
use function dirname;

class PopularWordsHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'popularWordsCount';
    }

    public function description(): string
    {
        return 'Популярные слова';
    }

    public function handle(MessageCollection $messages): array
    {
        $config = require dirname(__DIR__, 2) . '/config/words.php';
        $analyzer = new WordsAnalyzer($config);
        return $analyzer->analyze($messages);
    }
}
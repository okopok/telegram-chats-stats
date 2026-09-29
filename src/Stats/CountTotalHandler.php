<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class CountTotalHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'countTotal';
    }

    public function description(): string
    {
        return 'Общее количество сообщений';
    }

    public function handle(MessageCollection $messages): array
    {
        return [
            'count' => $messages->count(),
        ];
    }
}
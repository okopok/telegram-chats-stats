<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class TotalStrlenHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'totalStrlen';
    }

    public function description(): string
    {
        return 'Общее количество знаков в чате';
    }

    public function handle(MessageCollection $messages): array
    {
        return [
            'strlen' => $messages->strlenTotal(),
        ];
    }
}
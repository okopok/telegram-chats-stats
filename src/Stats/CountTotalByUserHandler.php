<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class CountTotalByUserHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'countTotalByUser';
    }

    public function description(): string
    {
        return 'Общее количество сообщений по пользователю';
    }

    public function handle(MessageCollection $messages): array
    {
        return $messages->countByUser();
    }
}
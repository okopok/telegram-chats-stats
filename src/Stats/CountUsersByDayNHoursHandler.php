<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class CountUsersByDayNHoursHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'countUsersByDayNHours';
    }

    public function description(): string
    {
        return 'Общее количество сообщений с разбивкой по пользователям, часам и дням недели';
    }

    public function handle(MessageCollection $messages): array
    {
        return $messages->countByUserDayHour();
    }
}
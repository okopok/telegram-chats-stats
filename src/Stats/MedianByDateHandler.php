<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class MedianByDateHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'medianByDate';
    }

    public function description(): string
    {
        return 'Среднее количество сообщений';
    }

    public function handle(MessageCollection $messages): array
    {
        return [
            'year' => $messages->medianByDate('Y'),
            'months' => $messages->medianByDate('Y-m'),
            'days' => $messages->medianByDate('Y-m-d'),
            'weeks' => $messages->medianByDate('Y-m N'),
            'hours' => $messages->medianByDate('Y-m-d H'),
        ];
    }
}
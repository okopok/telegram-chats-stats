<?php

namespace ChatStats\Stats;

use ChatStats\DateHelper;
use ChatStats\Messages\MessageCollection;
use function collect;

class CountTotalByDateHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'countTotalByDate';
    }

    public function description(): string
    {
        return 'Общее количество сообщений по датам';
    }

    public function handle(MessageCollection $messages): array
    {
        return [
            'year' => $messages->countByDate('Y'),
            'weeks' => collect($messages->countByDate('N'))->mapWithKeys(static function ($count, $weekNum) {
                return [DateHelper::getDayName($weekNum) => $count];
            })->all(),
            'all_days' => $messages->countByDate('d'),
            'all_months' => collect($messages->countByDate('m'))->mapWithKeys(static function ($count, $monthNum) {
                return [DateHelper::getMonthName($monthNum) => $count];
            })->all(),
            'monthsTop10' => collect($messages->countByDate('Y-m'))->sortDesc()->take(10)->all(),
            'daysTop10' => collect($messages->countByDate('Y-m-d'))->sortDesc()->take(10)->all(),
        ];
    }
}
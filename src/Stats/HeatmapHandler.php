<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Тепловая карта активности чата: дни недели × часы суток.
 */
class HeatmapHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'heatmap';
    }

    public function description(): string
    {
        return 'Тепловая карта активности';
    }

    public function handle(MessageCollection $messages): array
    {
        $data = $messages->activityHeatmap();
        $rows = [];
        foreach ($data['days'] as $weekNum => $day) {
            $hours = [];
            foreach ($day['hours'] as $hour => $count) {
                // Интенсивность 0..10 для цвета ячейки.
                $level = $data['max'] > 0 ? (int)round($count / $data['max'] * 10) : 0;
                $hours[] = ['hour' => $hour, 'count' => $count, 'level' => $level];
            }
            $rows[] = [
                'name' => $day['name'],
                'count' => $day['count'],
                'hours' => $hours,
            ];
        }
        return ['rows' => $rows, 'max' => $data['max']];
    }
}
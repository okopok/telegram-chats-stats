<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Хронотипы: распределение активности по зонам суток (ночь/утро/день/вечер),
 * пиковый час и доминирующий тип для каждого участника.
 */
class ChronotypeHandler extends AbstractStatHandler
{
    /** Зоны суток с подписями и эмодзи (в порядке ночь → вечер). */
    private const ZONES = [
        'night' => 'Ночь 🦉',
        'morning' => 'Утро 🌅',
        'day' => 'День ☀️',
        'evening' => 'Вечер 🌆',
    ];

    public function key(): string
    {
        return 'chronotypes';
    }

    public function description(): string
    {
        return 'Хронотипы чата';
    }

    public function handle(MessageCollection $messages): array
    {
        $byUser = [];
        foreach ($messages->chronotypes() as $user => $data) {
            $zones = [];
            $total = max(1, $data['total']);
            foreach (self::ZONES as $key => $label) {
                $zones[] = [
                    'key' => $key,
                    'label' => $label,
                    'count' => $data[$key],
                    'share' => round($data[$key] / $total * 100, 1),
                ];
            }
            $byUser[] = [
                'user' => $user,
                'total' => $data['total'],
                'peak_hour' => $data['peak_hour'],
                'type' => $data['type'],
                'type_label' => self::ZONES[$data['type']] ?? $data['type'],
                'zones' => $zones,
            ];
        }

        return ['by_user' => $byUser, 'zone_labels' => array_values(self::ZONES)];
    }
}
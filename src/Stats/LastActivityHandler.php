<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;
use function round;
use function time;

/**
 * Последняя активность: когда каждый участник писал в последний раз,
 * сколько дней назад, текст последнего сообщения.
 */
class LastActivityHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'lastActivity';
    }

    public function description(): string
    {
        return 'Кто последний раз писал';
    }

    public function handle(MessageCollection $messages): array
    {
        $now = time();
        $byUser = [];
        foreach ($messages->lastActivity() as $user => $activity) {
            $daysAgo = (int)round(($now - $activity['date']) / 86400);
            $byUser[] = [
                'user' => $user,
                'date' => $activity['date'],
                'ago_days' => $daysAgo,
                'text' => $activity['text'],
            ];
        }

        return ['by_user' => $byUser];
    }
}
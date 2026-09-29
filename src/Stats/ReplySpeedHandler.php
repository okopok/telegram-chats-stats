<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Скорость ответа: медианное и среднее время между сообщением
 * и реплаем на него, по участникам.
 */
class ReplySpeedHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'replySpeed';
    }

    public function description(): string
    {
        return 'Скорость ответа';
    }

    public function handle(MessageCollection $messages): array
    {
        $byUser = [];
        foreach ($messages->replySpeed() as $user => $speed) {
            if ($speed['count'] === 0) {
                continue;
            }
            $byUser[] = [
                'user' => $user,
                'count' => $speed['count'],
                'median_sec' => $speed['median_sec'],
                'mean_sec' => $speed['mean_sec'],
            ];
        }

        return ['by_user' => $byUser];
    }
}
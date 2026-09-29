<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Серии активности: самый длинный период ежедневной активности чата
 * и персональные рекорды участников (дни подряд).
 */
class StreakHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'streaks';
    }

    public function description(): string
    {
        return 'Серии активности';
    }

    public function handle(MessageCollection $messages): array
    {
        $chat = $messages->longestStreak();
        $users = [];
        foreach ($messages->userStreaks(10) as $user => $streak) {
            $users[] = ['user' => $user] + $streak;
        }

        return ['chat' => $chat, 'users' => $users];
    }
}
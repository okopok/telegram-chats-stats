<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Ответы самому себе: реплаи на собственные сообщения.
 */
class SelfRepliesHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'selfReplies';
    }

    public function description(): string
    {
        return 'Ответы самому себе';
    }

    public function handle(MessageCollection $messages): array
    {
        $byUser = [];
        foreach ($messages->selfReplies() as $user => $count) {
            $byUser[] = ['user' => $user, 'count' => $count];
        }

        return [
            'total' => array_sum(array_column($byUser, 'count')),
            'by_user' => $byUser,
        ];
    }
}
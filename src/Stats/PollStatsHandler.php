<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Опросы: кто создаёт опросы и список опросов с вариантами ответов.
 */
class PollStatsHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'pollStats';
    }

    public function description(): string
    {
        return 'Опросы';
    }

    public function handle(MessageCollection $messages): array
    {
        $stats = $messages->pollsStats();

        $byUser = [];
        foreach ($stats['by_user'] as $user => $count) {
            $byUser[] = ['user' => $user, 'count' => $count];
        }

        $polls = [];
        foreach ($stats['polls'] as $message) {
            $polls[] = [
                'id' => $message->message_id,
                'from' => $message->from?->username ?? '?',
                'date' => $message->date,
                'question' => $message->poll?->question ?? '',
                'answers' => $message->poll?->answers ?? [],
            ];
        }

        return [
            'total' => count($polls),
            'by_user' => $byUser,
            'polls' => $polls,
        ];
    }
}
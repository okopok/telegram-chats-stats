<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Пересылки: кто пересылает сообщения в чат и из каких источников
 * («Forwarded from …»).
 */
class ForwardedStatsHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'forwardedStats';
    }

    public function description(): string
    {
        return 'Пересылки';
    }

    public function handle(MessageCollection $messages): array
    {
        $byUser = [];
        foreach ($messages->forwardedByUser() as $user => $count) {
            $byUser[] = ['user' => $user, 'count' => $count];
        }

        $sources = [];
        foreach (array_slice($messages->forwardedSources(), 0, 15, true) as $source => $count) {
            $sources[] = ['source' => $source, 'count' => $count];
        }

        return [
            'total_forwarded' => array_sum(array_column($byUser, 'count')),
            'total' => $messages->count(),
            'by_user' => $byUser,
            'sources' => $sources,
        ];
    }
}
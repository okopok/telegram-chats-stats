<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Правки сообщений: кто и как часто исправляет свои сообщения,
 * доля правок от общего количества.
 */
class EditedStatsHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'editedStats';
    }

    public function description(): string
    {
        return 'Правки сообщений';
    }

    public function handle(MessageCollection $messages): array
    {
        $total = $messages->count();
        $edited = $messages->editedByUser();
        $byUser = [];
        foreach ($messages->countByUser() as $user => $count) {
            $edits = $edited[$user] ?? 0;
            if ($edits === 0) {
                continue;
            }
            $byUser[] = [
                'user' => $user,
                'edited' => $edits,
                'total' => $count,
                'share' => $count > 0 ? round($edits / $count * 100, 1) : 0.0,
            ];
        }
        usort($byUser, static fn (array $a, array $b): int => $b['edited'] <=> $a['edited']);

        return [
            'total_edited' => array_sum($edited),
            'total' => $total,
            'by_user' => $byUser,
        ];
    }
}
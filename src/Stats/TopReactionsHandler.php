<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Реакции чата: топ эмодзи, реакции по типам сообщений, лидеры
 * по полученным реакциям, топ-3 эмодзи каждого участника.
 */
class TopReactionsHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'topReactions';
    }

    public function description(): string
    {
        return 'Реакции чата';
    }

    public function handle(MessageCollection $messages): array
    {
        // Топ-15 эмодзи по всему чату.
        $totals = [];
        foreach (array_slice($messages->reactionTotals(), 0, 15, true) as $emoji => $count) {
            $totals[] = ['emoji' => $emoji, 'count' => $count];
        }

        // Реакции по типам сообщений.
        $byType = [];
        foreach ($messages->reactionByType() as $type => $count) {
            $byType[] = ['type' => $type, 'count' => $count];
        }

        // Лидеры: сколько реакций получили сообщения каждого участника.
        $recv = $messages->reactionRecvByUser();
        $totalReactions = array_sum($recv);
        $leaders = [];
        foreach (array_slice($recv, 0, 15, true) as $user => $count) {
            $leaders[] = [
                'user' => $user,
                'received' => $count,
                'share' => $totalReactions > 0 ? round($count / $totalReactions * 100, 1) : 0.0,
            ];
        }

        // Топ-3 эмодзи каждого участника.
        $byUser = [];
        $byEmoji = $messages->reactionByUser();
        $userCounts = $messages->countByUser();
        foreach ($byEmoji as $user => $emojiCounts) {
            $top = [];
            foreach (array_slice($emojiCounts, 0, 3, true) as $emoji => $count) {
                $top[] = ['emoji' => $emoji, 'count' => $count];
            }
            $byUser[] = [
                'user' => $user,
                'total' => $userCounts[$user] ?? 0,
                'top' => $top,
            ];
        }
        usort($byUser, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'totals' => $totals,
            'by_type' => $byType,
            'leaders' => $leaders,
            'by_user' => $byUser,
        ];
    }
}
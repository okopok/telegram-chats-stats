<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Медиа-вес: суммарный объём документов, длительность голосовых
 * и видео по участникам.
 */
class MediaWeightHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'mediaWeight';
    }

    public function description(): string
    {
        return 'Медиа-вес';
    }

    public function handle(MessageCollection $messages): array
    {
        $byUser = [];
        foreach ($messages->mediaWeight() as $user => $weight) {
            if (($weight['docs_kb'] + $weight['docs_count'] + $weight['voice_sec'] + $weight['video_sec']) === 0) {
                continue;
            }
            $byUser[] = [
                'user' => $user,
                'docs_kb' => $weight['docs_kb'],
                'docs_mb' => round($weight['docs_kb'] / 1024, 1),
                'docs_count' => $weight['docs_count'],
                'voice_sec' => $weight['voice_sec'],
                'video_sec' => $weight['video_sec'],
            ];
        }

        $voiceTotal = array_sum(array_column($byUser, 'voice_sec'));
        $videoTotal = array_sum(array_column($byUser, 'video_sec'));
        $docsTotal = array_sum(array_column($byUser, 'docs_kb'));

        return [
            'by_user' => $byUser,
            'voice_total' => $voiceTotal,
            'video_total' => $videoTotal,
            'docs_total_kb' => $docsTotal,
        ];
    }
}
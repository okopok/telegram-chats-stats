<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Самые длинные сообщения чата: топ по количеству знаков.
 */
class LongestMessagesHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'longestMessages';
    }

    public function description(): string
    {
        return 'Самые длинные сообщения';
    }

    public function handle(MessageCollection $messages): array
    {
        $result = [];
        foreach ($messages->longestMessages(10) as $item) {
            $message = $item['message'];
            $result[] = [
                'len' => $item['len'],
                'from' => $message->from?->username ?? '?',
                'date' => $message->date,
                'text' => $message->text,
            ];
        }
        return $result;
    }
}
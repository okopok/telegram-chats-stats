<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Контент-хиты: топ сообщений по сумме реакций, с текстом, автором,
 * датой и составом реакций.
 */
class TopReactedMessagesHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'topReactedMessages';
    }

    public function description(): string
    {
        return 'Самые популярные сообщения';
    }

    public function handle(MessageCollection $messages): array
    {
        $result = [];
        foreach ($messages->topReactedMessages(20) as $item) {
            $message = $item['message'];
            $reactions = [];
            foreach ($message->reactions as $reaction) {
                $reactions[] = ['emoji' => $reaction->emoji, 'count' => $reaction->count];
            }
            $result[] = [
                'id' => $message->message_id,
                'from' => $message->from?->username ?? '?',
                'date' => $message->date,
                'text' => $message->text,
                'total' => $item['score'],
                'reactions' => $reactions,
                'type' => $this->typeLabel($message),
            ];
        }
        return $result;
    }

    /**
     * Обозначение типа сообщения для контент-хитов (иконка).
     */
    private function typeLabel(\ChatStats\Entity\Message $message): string
    {
        return match (true) {
            $message->photo !== null => '🖼',
            $message->sticker !== null => '🎨',
            $message->video !== null => '🎬',
            $message->voice !== null => '🎙',
            $message->animation !== null => '🎞',
            $message->poll !== null => '📊',
            $message->document !== null => '📄',
            $message->location !== null => '📍',
            $message->contact !== null => '👤',
            $message->audio !== null => '🎵',
            default => '💬',
        };
    }
}
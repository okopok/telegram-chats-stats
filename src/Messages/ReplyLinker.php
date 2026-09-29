<?php

namespace ChatStats\Messages;

use ChatStats\Entity\Message;
use Illuminate\Support\Collection;

/**
 * Проставляет reply_to_message по reply_to_id: коллекция должна быть
 * ключевана по message_id (как в ExportMessageSource).
 */
final class ReplyLinker
{
    public static function link(Collection $messages): void
    {
        $replies = [];
        /** @var Message $message */
        foreach ($messages as $message) {
            if ($message->reply_to_id) {
                $replies[(string)$message->reply_to_id][] = $message->message_id;
            }
        }
        foreach ($replies as $replyId => $messageIds) {
            foreach ($messageIds as $messageId) {
                $messages[$messageId]->reply_to_message = $messages->get($replyId);
            }
        }
    }
}
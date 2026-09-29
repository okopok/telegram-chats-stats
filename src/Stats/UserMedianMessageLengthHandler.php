<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class UserMedianMessageLengthHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'userMedianMessageLength';
    }

    public function description(): string
    {
        return 'Средняя длинна сообщений по пользователям';
    }

    public function handle(MessageCollection $messages): array
    {
        return $messages->medianStrlenByUser();
    }
}
<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class CountByTypeAndUserHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'countByTypeAndUser';
    }

    public function description(): string
    {
        return 'Сообщения по типам сообщения и пользователям';
    }

    public function handle(MessageCollection $messages): array
    {
        return $messages->countByTypeAndUser();
    }
}
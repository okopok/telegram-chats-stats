<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class CountTotalUsersHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'countTotalUsers';
    }

    public function description(): string
    {
        return 'Общее количество всех пользователей за всё время';
    }

    public function handle(MessageCollection $messages): array
    {
        return $messages->usernames();
    }
}
<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class StrlenByUserHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'strlenByUser';
    }

    public function description(): string
    {
        return 'Общее количество знаков по пользователю';
    }

    public function handle(MessageCollection $messages): array
    {
        return $messages->strlenByUser();
    }
}
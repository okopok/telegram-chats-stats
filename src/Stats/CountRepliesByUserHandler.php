<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class CountRepliesByUserHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'countRepliesByUser';
    }

    public function description(): string
    {
        return 'Общее количество реплаев по пользователю';
    }

    public function handle(MessageCollection $messages): array
    {
        return $messages->repliesMatrix();
    }
}
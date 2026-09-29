<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

class FirstMessageHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'firstMessage';
    }

    public function description(): string
    {
        return 'Самое первое сообщение';
    }

    public function handle(MessageCollection $messages): array
    {
        $first = $messages->firstByDate();
        return [
            'message' => $first?->text ?? '',
            'datetime' => $first?->date ?? 0,
            'from' => $first?->from->username ?? '',
        ];
    }
}
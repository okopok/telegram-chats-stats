<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

interface StatHandler
{
    public function key(): string;

    public function description(): string;

    public function template(): string;

    public function handle(MessageCollection $messages): array;
}
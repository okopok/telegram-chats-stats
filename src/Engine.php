<?php

namespace ChatStats;

use ChatStats\Messages\MessageCollection;
use ChatStats\Stats\StatHandler;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Последовательно выполняет обработчики статистики над коллекцией сообщений.
 */
final class Engine
{
    private MessageCollection $messages;

    /** @var StatHandler[] */
    private array $handlers;

    private ?Stopwatch $stopwatch;

    public function __construct(MessageCollection $messages, array $handlers, ?Stopwatch $stopwatch = null)
    {
        $this->messages = $messages;
        $this->handlers = $handlers;
        $this->stopwatch = $stopwatch;
    }

    /** @return array<string, array> key => data в порядке регистрации */
    public function calculate(): array
    {
        $this->stopwatch?->openSection();
        $results = [];
        foreach ($this->handlers as $handler) {
            $this->stopwatch?->start('handler ' . $handler->key());
            $results[$handler->key()] = $handler->handle($this->messages);
            $this->stopwatch?->stop('handler ' . $handler->key());
        }
        $this->stopwatch?->stopSection('calc');
        return $results;
    }
}
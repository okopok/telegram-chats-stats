<?php

namespace ChatStats\Stats;

abstract class AbstractStatHandler implements StatHandler
{
    final public function template(): string
    {
        return $this->key() . '.twig';
    }
}
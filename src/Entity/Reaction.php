<?php

namespace ChatStats\Entity;

/**
 * Реакция на сообщение: эмодзи и количество поставивших.
 *
 * Не implements MessageType: реакция — не тип медиа, а атрибут сообщения.
 */
class Reaction
{
    public string $emoji = '';
    public int $count = 0;
}
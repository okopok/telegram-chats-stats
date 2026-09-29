<?php


namespace ChatStats\Entity;


class Poll implements MessageType
{
    /** Вопрос опроса из div.question */
    public ?string $question = null;

    /** Варианты ответа из div.answer */
    public array $answers = [];
}

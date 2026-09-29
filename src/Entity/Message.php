<?php

namespace ChatStats\Entity;

class Message implements MessageType
{
    public int $message_id = 0;
    public int $date = 0;
    public string $text = '';
    public ?int $reply_to_id = 0;
    public ?Message $reply_to_message = null;
    public ?User $from = null;
    public ?Audio $audio = null;
    public ?Document $document = null;
    public ?Animation $animation = null;
    public ?Photo $photo = null;
    public ?Sticker $sticker = null;
    public ?Video $video = null;
    public ?Voice $voice = null;
    public ?Contact $contact = null;
    public ?Location $location = null;
    public ?Poll $poll = null;

    /** @var Reaction[] реакции на сообщение (эмодзи + количество) */
    public array $reactions = [];

    /** Сообщение было исправлено (в экспорте есть пометка «edited») */
    public bool $edited = false;

    /** Источник пересылки, например «Илья Ларин» (текст после «Forwarded from ») */
    public ?string $forwarded_from = null;

    /** Сайт веб-превью ссылки, например «YouTube» */
    public ?string $webpage_site = null;

    /** Заголовок веб-превью ссылки */
    public ?string $webpage_title = null;
}

<?php


namespace ChatStats\Entity;


class Video implements MessageType
{
    /** Длительность видео в секундах (из div.video_duration «0:19»), null если не указана */
    public ?int $duration_sec = null;
}

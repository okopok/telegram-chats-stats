<?php

namespace ChatStats\Entity;

class Voice implements MessageType
{
    /** Длительность голосового в секундах (из «0:14»), null если не указана */
    public ?int $duration_sec = null;
}

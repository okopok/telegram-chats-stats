<?php


namespace ChatStats\Entity;


class Document implements MessageType
{
    /** Имя файла из div.title блока .media_file */
    public ?string $name = null;

    /** Размер файла в килобайтах (из «709.8 KB» / «1.2 MB»), null если не указан */
    public ?float $size_kb = null;
}

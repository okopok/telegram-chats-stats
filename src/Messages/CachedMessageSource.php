<?php

namespace ChatStats\Messages;

use ChatStats\Entity\Message;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerBuilder;
use function collect;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_dir;
use function scandir;
use function sha1;

/**
 * Кэширует разобранные сообщения в JSON.
 *
 * Ключ кэша — сигнатура содержимого директории экспорта (имена файлов + mtime),
 * поэтому любые изменения данных инвалидируют кэш. В JSON сохраняются только
 * reply_to_id: reply_to_message проставляет MessageCollection при загрузке.
 */
final class CachedMessageSource implements MessageSource
{
    private ExportMessageSource $source;

    private string $dir;

    private bool $cacheEnabled;

    private bool $rebuild;

    public function __construct(ExportMessageSource $source, string $dir, bool $cacheEnabled = true, bool $rebuild = false)
    {
        $this->source = $source;
        $this->dir = $dir;
        $this->cacheEnabled = $cacheEnabled;
        $this->rebuild = $rebuild;
    }

    public function getMessages(): MessageCollection
    {
        if (!$this->cacheEnabled) {
            return $this->source->getMessages();
        }

        $serializer = SerializerBuilder::create()->build();
        $cacheFile = dirname(__DIR__, 2) . '/var/cache/parsers/' . $this->signature() . '.json';

        if ($this->rebuild || !file_exists($cacheFile)) {
            $messages = $this->source->getMessages()->messages();
            // reply_to_message не сериализуем: релинк выполнит MessageCollection
            foreach ($messages as $message) {
                $message->reply_to_message = null;
            }
            $data = $serializer->serialize(
                $messages->all(),
                'json',
                SerializationContext::create()->setInitialType('array<ChatStats\Entity\Message>')
            );
            file_put_contents($cacheFile, $data);
            unset($data);
        }

        $messages = $serializer->deserialize(
            file_get_contents($cacheFile),
            'array<ChatStats\Entity\Message>',
            'json'
        );

        $collection = collect($messages)->keyBy(static fn (Message $message) => $message->message_id);

        return new MessageCollection($collection);
    }

    /**
     * Сигнатура директории: sha1 по (имя файла|mtime) всех файлов экспорта.
     */
    private function signature(): string
    {
        $parts = [];
        foreach (scandir($this->dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->dir . '/' . $name;
            if (is_dir($path)) {
                continue;
            }
            $parts[] = $name . '|' . (string)filemtime($path);
        }
        sort($parts, SORT_STRING);
        return sha1(implode(';', $parts));
    }
}
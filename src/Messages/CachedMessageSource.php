<?php

namespace ChatStats\Messages;

use ChatStats\Entity\Message;
use RuntimeException;
use function collect;
use function count;
use function dirname;
use function file_put_contents;
use function filemtime;
use function is_dir;
use function mkdir;
use function scandir;
use function serialize;
use function sha1;
use function sort;
use function sprintf;

/**
 * Кэширует разобранные сообщения в файлы (нативный serialize).
 *
 * Ключ кэша — сигнатура содержимого директории экспорта (имена файлов + mtime),
 * поэтому любые изменения данных инвалидируют кэш. Сообщения пишутся/читаются
 * чанками (по CHUNK_SIZE) — сериализация не держит в памяти весь массив целиком.
 * reply_to_message не сохраняется: релинк делает MessageCollection при загрузке.
 */
final class CachedMessageSource implements MessageSource
{
    /** Сообщений в одном чанке кэша: ограничивает пик памяти при сериализации. */
    private const CHUNK_SIZE = 5000;

    /**
     * Версия формата кэша: меняется при изменении структуры сериализуемых
     * сущностей (новые поля), чтобы старый кэш без новых данных не считался
     * валидным. Сигнатура директории зависит только от файлов экспорта.
     */
    private const FORMAT_VERSION = 'v3';

    private ExportMessageSource $source;

    private string $dir;

    private bool $cacheEnabled;

    private bool $rebuild;

    private int $limit;

    public function __construct(
        ExportMessageSource $source,
        string $dir,
        bool $cacheEnabled = true,
        bool $rebuild = false,
        int $limit = 0
    ) {
        $this->source = $source;
        $this->dir = $dir;
        $this->cacheEnabled = $cacheEnabled;
        $this->rebuild = $rebuild;
        $this->limit = $limit;
    }

    public function getMessages(): MessageCollection
    {
        if (!$this->cacheEnabled) {
            $messages = [];
            foreach ($this->source->getMessages() as $message) {
                $messages[$message->message_id] = $message;
            }
            return MessageCollection::fromCollection(collect($messages));
        }

        $cacheDir = dirname(__DIR__, 2) . '/var/cache/parsers/' . $this->signature() . '-' . self::FORMAT_VERSION;

        if ($this->rebuild || !is_dir($cacheDir)) {
            $this->writeChunks($cacheDir);
        }

        return MessageCollection::fromChunks($cacheDir);
    }

    /**
     * Парсит экспорт и пишет чанки кэша потоково (без хранения всей коллекции).
     */
    private function writeChunks(string $cacheDir): void
    {
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $index = 0;
        $chunk = [];
        /** @var Message $message */
        foreach ($this->source->getMessages() as $message) {
            // reply_to_message не сериализуем: релинк выполнит MessageCollection
            $message->reply_to_message = null;
            $chunk[] = $message;
            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->writeChunk($cacheDir, $index++, $chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->writeChunk($cacheDir, $index, $chunk);
        }
    }

    /**
     * @param Message[] $chunk
     */
    private function writeChunk(string $cacheDir, int $index, array $chunk): void
    {
        $file = sprintf('%s/%05d.ser', $cacheDir, $index);
        if (file_put_contents($file, serialize($chunk)) === false) {
            throw new RuntimeException(sprintf('Не удалось записать кэш: %s', $file));
        }
    }

    /**
     * Сигнатура директории: sha1 по (имя файла|mtime) всех файлов экспорта.
     * К лимиту добавляется суффикс, чтобы кэш с лимитом не пересекался
     * с полным кэшем (и наоборот).
     */
    public function signature(): string
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
        $signature = sha1(implode(';', $parts));
        return $this->limit > 0 ? $signature . '-limit-' . $this->limit : $signature;
    }
}
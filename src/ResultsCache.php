<?php

namespace ChatStats;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_dir;
use function is_file;
use function mkdir;
use function sha1;
use function sprintf;
use function unserialize;

/**
 * Кэш результатов Engine::calculate() в файле (нативный serialize).
 *
 * Позволяет повторным запускам не десериализовать сообщения и не выполнять
 * обработчики заново — только читать готовый результат. Сигнатура учитывает
 * как данные экспорта (сигнатура CachedMessageSource), так и код проекта:
 * изменение src/ или config/ инвалидирует кэш автоматически.
 */
final class ResultsCache
{
    public function signature(string $messagesSignature): string
    {
        return sha1($messagesSignature . '|' . $this->codeSignature());
    }

    public function get(string $signature): ?array
    {
        $file = $this->file($signature);
        if (!file_exists($file)) {
            return null;
        }
        $data = unserialize(file_get_contents($file));
        return is_array($data) ? $data : null;
    }

    public function put(string $signature, array $results): void
    {
        $file = $this->file($signature);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (file_put_contents($file, serialize($results)) === false) {
            throw new RuntimeException(sprintf('Не удалось записать кэш результатов: %s', $file));
        }
    }

    private function file(string $signature): string
    {
        return dirname(__DIR__) . '/var/cache/results/' . $signature . '.ser';
    }

    /**
     * Сигнатура кода: mtime всех файлов src/ и config/.
     * Любое изменение логики или конфигов инвалидирует кэш результатов.
     */
    private function codeSignature(): string
    {
        $parts = [];
        foreach ([dirname(__DIR__) . '/src', dirname(__DIR__) . '/config'] as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $parts[] = $file->getPathname() . '|' . (string)$file->getMTime();
            }
        }
        sort($parts);
        return sha1(implode(';', $parts));
    }
}
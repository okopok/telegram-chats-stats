<?php

namespace ChatStats\Stats\Words;

use ChatStats\Entity\Message;
use ChatStats\Messages\MessageCollection;
use function arsort;
use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_slice;
use function array_unique;
use function count;
use function in_array;
use function ksort;
use function mb_ereg_replace;
use function mb_split;
use function mb_strlen;
use function mb_strtolower;
use function str_replace;
use function trim;

/**
 * Подсчёт популярных слов: подготовка строки и агрегация по словам,
 * пользователям и «уникальным» словам.
 *
 * Работает на обычных PHP-массивах (без Illuminate Collections) —
 * заметно экономит память на больших экспортах.
 */
final class WordsAnalyzer
{
    /** @var string[] */
    private array $stopWords;

    /** @var array<string, string[]> */
    private array $aliases;

    private int $wordLenMin;

    /** @var array<string, array<string, int>> ключ алиаса => (юзер => счётчик) */
    private array $resultList = [];

    /** @var array<string, int> слово => счётчик по всем */
    private array $resultWordTotal = [];

    /** @var array<string, array<string, int>> юзер => (слово => счётчик) */
    private array $resultUserWordTotal = [];

    /** @var array<string, string[]> слово => список юзеров */
    private array $uniqWords = [];

    /**
     * @param array{stop_words: string[], aliases: array<string, string[]>, word_len_min: int} $config
     */
    public function __construct(array $config)
    {
        $this->stopWords = $config['stop_words'];
        $this->aliases = $config['aliases'];
        $this->wordLenMin = $config['word_len_min'];
    }

    /**
     * @return array{list: array, total: array, users: array, uniq: array}
     */
    public function analyze(MessageCollection $messages): array
    {
        $this->resultWordTotal = [];
        $this->resultUserWordTotal = [];
        $this->uniqWords = [];
        $this->resultList = array_fill_keys(array_keys($this->aliases), []);

        foreach ($messages->iterate() as $message) {
            if (!mb_strlen($message->text)) {
                continue;
            }
            $words = $this->prepareString($message->text);

            $this->countWords($words);
            $this->countUserWord($words, $message->from->username);
            $this->countUserWordList($message, $words);
        }
        $this->countUniqWordsByUser();

        return [
            'list' => $this->buildListResult(),
            'total' => $this->top($this->resultWordTotal, 100),
            'users' => $this->buildUsersResult(),
            'uniq' => $this->uniqWords,
        ];
    }

    /**
     * Алиасы с ненулевыми счётчиками, по убыванию.
     *
     * @return array<string, array<string, int>>
     */
    private function buildListResult(): array
    {
        $result = [];
        foreach ($this->resultList as $key => $values) {
            arsort($values);
            $values = array_filter($values);
            if ($values !== []) {
                $result[$key] = $values;
            }
        }
        return $result;
    }

    /**
     * Счётчики по юзерам: топ-50 слов с счётчиком > 1, по убыванию.
     *
     * @return array<string, array<string, int>>
     */
    private function buildUsersResult(): array
    {
        $result = [];
        foreach ($this->resultUserWordTotal as $user => $words) {
            $words = $this->top($words, 50);
            $words = array_filter($words, static fn (int $value) => $value > 1);
            if ($words !== []) {
                $result[$user] = $words;
            }
        }
        return $result;
    }

    /**
     * Топ-N по убыванию с сохранением ключей.
     *
     * @param array<string, int> $values
     * @return array<string, int>
     */
    private function top(array $values, int $n): array
    {
        arsort($values);
        return array_slice($values, 0, $n, true);
    }

    /**
     * @return string[]
     */
    private function prepareString(string $text): array
    {
        $str = mb_strtolower($text);
        $str = str_replace('ё', 'е', $str);
        $str = mb_ereg_replace('(<a\b[^>]*>.*?<\/a>)', '', $str, 'msr');
        $str = mb_ereg_replace('(.+?)\1+', '\1', $str, 'msr');

        foreach ($this->aliases as $key => $aliasForms) {
            $str = mb_ereg_replace($key, $aliasForms[0], $str, 'msr');
        }

        $str = mb_ereg_replace('([^0-9a-zа-я\s])+', ' ', $str, 'msr');
        $str = trim($str);

        if ($str === '') {
            return [];
        }

        $words = mb_split('[[:space:]]+', $str);
        return array_values(array_filter($words, static fn ($word) => $word !== ''));
    }

    /**
     * @param string[] $words
     */
    private function countWords(array $words): void
    {
        foreach ($words as $word) {
            if ($this->checkStopWord($word) || mb_strlen($word) < $this->wordLenMin) {
                continue;
            }
            $this->resultWordTotal[$word] = ($this->resultWordTotal[$word] ?? 0) + 1;
        }
    }

    private function checkStopWord(string $word): bool
    {
        return in_array($word, $this->stopWords, false);
    }

    /**
     * @param string[] $words
     */
    private function countUserWord(array $words, string $userName): void
    {
        foreach ($words as $word) {
            if ($this->checkStopWord($word) || mb_strlen($word) < $this->wordLenMin) {
                continue;
            }

            $this->resultUserWordTotal[$userName][$word] = ($this->resultUserWordTotal[$userName][$word] ?? 0) + 1;
            $this->uniqWords[$word][] = $userName;
        }
    }

    /**
     * Слова из алиасов в сообщении юзера — в resultList.
     *
     * @param string[] $words
     */
    private function countUserWordList(Message $message, array $words): void
    {
        foreach ($this->aliases as $key => $aliasForms) {
            $current = 0;
            foreach ($aliasForms as $alias) {
                foreach ($words as $word) {
                    if ($alias == $word) {
                        $current++;
                    }
                }
            }

            if (!$current) {
                continue;
            }
            $userName = $message->from->username;
            $this->resultList[$key][$userName] = ($this->resultList[$key][$userName] ?? 0) + $current;
        }
    }

    /**
     * Уникальные слова юзера (встречаются только у него): топ-10 по убыванию.
     */
    private function countUniqWordsByUser(): void
    {
        $result = [];

        foreach ($this->uniqWords as $word => $users) {
            if (count(array_unique($users)) !== 1) {
                continue;
            }
            $user = $users[0];
            $result[$user][$word] = $this->resultWordTotal[$word];
        }

        ksort($result);
        foreach ($result as $user => &$words) {
            $words = $this->top($words, 10);
        }
        unset($words);

        $this->uniqWords = $result;
    }
}
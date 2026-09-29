<?php

namespace ChatStats\Stats\Words;

use ChatStats\Entity\Message;
use ChatStats\Messages\MessageCollection;
use Illuminate\Support\Collection;
use function collect;
use function in_array;
use function mb_ereg_replace;
use function mb_split;
use function mb_strlen;
use function mb_strtolower;
use function str_replace;
use function trim;

/**
 * Подсчёт популярных слов: подготовка строки и агрегация по словам,
 * пользователям и «уникальным» словам.
 */
final class WordsAnalyzer
{
    /** @var string[] */
    private array $stopWords;

    /** @var array<string, string[]> */
    private array $aliases;

    private int $wordLenMin;

    private Collection $resultList;

    private Collection $resultWordTotal;

    private Collection $resultUserWordTotal;

    private Collection $uniqWords;

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
        $this->resultWordTotal = collect([]);
        $this->resultUserWordTotal = collect([]);
        $this->uniqWords = collect([]);

        $emptyKeys = collect($this->aliases)->mapWithKeys(static function ($value, $key) {
            return [$key => 0];
        })->all();

        $this->resultList = collect($this->aliases)->mapWithKeys(static function ($value, $key) {
            return [$key => collect([])];
        });

        $messages->messages()->filter(function (Message $message) {
            return mb_strlen($message->text);
        })->each(function (Message $message) use ($emptyKeys) {
            $words = $this->prepareString($message->text);

            $this->countWords($words);
            $this->countUserWord($words, $message->from->username);
            $this->countUserWordList($message, $words, $emptyKeys);
        });
        $this->countUniqWordsByUser();

        return [
            'list' => $this->resultList
                ->mapWithKeys(static function ($value, $key) {
                    return [$key => $value->sortDesc()];
                })
                ->filter(function ($item, $key) {
                    return collect($item)->filter()->isNotEmpty();
                })->all(),
            'total' => $this->resultWordTotal->sortDesc()->take(100)->all(),
            'users' => $this->resultUserWordTotal->mapWithKeys(static function ($value, $key) {
                return [$key => $value->sortDesc()->take(50)->filter(static function ($value) {
                    return $value > 1;
                })->all()];
            })->all(),
            'uniq' => $this->uniqWords->sort()->all(),
        ];
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
            $wordStat = $this->resultWordTotal->get($word, 0);
            $wordStat++;
            $this->resultWordTotal[$word] = $wordStat;
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

            $userWords = $this->resultUserWordTotal->get($userName, collect([]));
            $wordStat = $userWords->get($word, 0);
            $wordStat++;

            $userWords->offsetSet($word, $wordStat);
            $this->resultUserWordTotal->offsetSet($userName, $userWords);

            if (!$this->uniqWords->has($word)) {
                $this->uniqWords[$word] = collect([]);
            }
            $this->uniqWords[$word]->push($userName);
        }
    }

    /**
     * @param string[] $words
     * @param array<string, int> $emptyKeys
     */
    private function countUserWordList(Message $message, array $words, array $emptyKeys): void
    {
        $current = $emptyKeys;

        foreach ($this->aliases as $key => $aliasForms) {
            foreach ($aliasForms as $alias) {
                foreach ($words as $word) {
                    if ($alias == $word) {
                        $current[$key]++;
                    }
                }
            }
        }

        foreach ($current as $key => $value) {
            if (!$value) {
                continue;
            }
            $curVal = $this->resultList->get($key)->get($message->from->username, 0);
            $this->resultList[$key]->offsetSet($message->from->username, $curVal + $value);
        }
    }

    private function countUniqWordsByUser(): void
    {
        $result = collect([]);

        $this->uniqWords->filter(static function ($value) {
            return $value->unique()->count() === 1;
        })
            ->mapWithKeys(static function ($value, $key) {
                return [$key => $value[0]];
            })
            ->each(function ($user, $word) use ($result) {
                if (!$result->has($user)) {
                    $result->offsetSet($user, collect([$word => $this->resultWordTotal[$word]]));
                } else {
                    $result[$user]->offsetSet($word, $this->resultWordTotal[$word]);
                }
            });

        $this->uniqWords = $result->sort()->mapWithKeys(static function ($value, $key) {
            return [$key => $value->sortDesc()->take(10)->all()];
        });
    }
}
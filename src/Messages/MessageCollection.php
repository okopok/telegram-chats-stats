<?php

namespace ChatStats\Messages;

use ChatStats\DateHelper;
use ChatStats\Entity\Message;
use ChatStats\Entity\MessageType;
use Illuminate\Support\Collection;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_fill;
use function array_slice;
use function array_sum;
use function arsort;
use function collect;
use function count;
use function date;
use function file_get_contents;
use function glob;
use function intdiv;
use function ksort;
use function mb_strlen;
use function md5;
use function range;
use function round;
use function sort;
use function strtotime;
use function time;
use function uasort;
use function unserialize;
use function usort;

/**
 * Value-object над сообщениями: ленивый однопроходный агрегатор статистики.
 *
 * Память = один чанк: в зависимости от источника сообщения либо уже лежат
 * в коллекции (--no-cache), либо читаются потоково из чанков кэша.
 * Первое обращение к любому методу агрегации выполняет один проход
 * по всем сообщениям и наполняет внутренний кэш агрегатов.
 */
final class MessageCollection
{
    /** @var Collection<Message>|null сообщения в памяти (режим --no-cache) */
    private ?Collection $messages;

    /** @var string|null директория чанков кэша (потоковый режим) */
    private ?string $chunkDir;

    /** @var array<string, mixed>|null кэш агрегатов после первого прохода */
    private ?array $aggregated = null;

    public function __construct(?Collection $messages = null, ?string $chunkDir = null)
    {
        $this->messages = $messages;
        $this->chunkDir = $chunkDir;
    }

    public static function fromCollection(Collection $messages): self
    {
        return new self($messages);
    }

    public static function fromChunks(string $chunkDir): self
    {
        return new self(null, $chunkDir);
    }

    /**
     * Итерирует сообщения: по коллекции или по чанкам (потоково).
     *
     * @return iterable<Message>
     */
    public function iterate(): iterable
    {
        if ($this->chunkDir !== null) {
            $files = glob($this->chunkDir . '/*.ser');
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $chunk = unserialize(file_get_contents($file), ['allowed_classes' => true]);
                foreach ($chunk as $message) {
                    yield $message;
                }
                unset($chunk);
            }
            return;
        }
        if ($this->messages !== null) {
            foreach ($this->messages as $message) {
                yield $message;
            }
        }
    }

    /**
     * Один проход по всем сообщениям: наполняет все агрегаты.
     * Вызывается лениво при первом обращении к агрегации.
     */
    private function aggregate(): void
    {
        if ($this->aggregated !== null) {
            return;
        }

        $userCount = [];          // юзер => число сообщений
        $userStrlen = [];         // юзер => сумма длин
        $dateCounts = [];         // формат => [ключ => количество]
        $dayHour = [];            // юзер => [день => [час => количество]]
        $typeCount = [];          // тип => количество
        $typeUserCount = [];      // тип => [юзер => количество]
        $replyPairs = [];         // (юзер, reply_to_id, дата) для repliesMatrix и скорости
        $replyIds = [];           // reply_to_id => true (цели реплаев)
        $first = null;            // первое по дате сообщение
        $total = 0;
        $totalStrlen = 0;

        // Реакции, правки, пересылки, сайты, медиа-вес, опросы, серии.
        $reactionTotals = [];     // эмодзи => сумма количеств
        $reactionByUser = [];     // юзер => [эмодзи => сумма количеств]
        $reactionRecvByUser = []; // юзер => сколько реакций получили его сообщения
        $reactionMsgCount = [];   // юзер => сообщений с реакциями
        $reactionByType = [];     // тип => реакций на сообщения этого типа
        $topReacted = [];         // id => [score, msg] топ сообщений по реакциям
        $editedByUser = [];       // юзер => правок
        $forwardedByUser = [];    // юзер => переслано сообщений
        $forwardedFrom = [];      // источник => переслано оттуда
        $sitesByUser = [];        // юзер => [сайт => количество]
        $docsByUser = [];         // юзер => сумма KB документов
        $docsCountByUser = [];    // юзер => документов с известным размером
        $voiceSecByUser = [];     // юзер => секунд голосовых
        $videoSecByUser = [];     // юзер => секунд видео
        $pollsByUser = [];        // юзер => опросов
        $pollList = [];           // список опросов {msg, ...}
        $daysAll = [];            // 'Y-m-d' => true (все дни активности чата)
        $daysUser = [];           // юзер => ['Y-m-d' => true]
        $lastByUser = [];         // юзер => последнее (по дате) сообщение
        $longest = [];            // топ-10 по длине текста {len, msg}

        foreach ($this->iterate() as $message) {
            $total++;
            $totalStrlen += mb_strlen($message->text);
            $username = $message->from?->username ?? '';

            $userCount[$username] = ($userCount[$username] ?? 0) + 1;
            $userStrlen[$username] = ($userStrlen[$username] ?? 0) + mb_strlen($message->text);

            $weekDay = date('N', $message->date);
            $hour = (int)date('G', $message->date);
            $dayHour[$username][$weekDay][$hour] = ($dayHour[$username][$weekDay][$hour] ?? 0) + 1;

            $type = $this->typeOf($message);
            $typeCount[$type] = ($typeCount[$type] ?? 0) + 1;
            $typeUserCount[$type][$username] = ($typeUserCount[$type][$username] ?? 0) + 1;

            foreach (['Y', 'N', 'd', 'm', 'Y-m', 'Y-m-d', 'Y-m N', 'Y-m-d H'] as $format) {
                $dateCounts[$format][date($format, $message->date)] = ($dateCounts[$format][date($format, $message->date)] ?? 0) + 1;
            }

            if ($message->reply_to_id) {
                $replyPairs[] = [$username, $message->reply_to_id, $message->date];
                $replyIds[$message->reply_to_id] = true;
            }

            if ($first === null || $message->date < $first->date) {
                $first = $message;
            }

            // Реакции: сумма по эмодзи, по юзерам, по типам сообщений, топ-20.
            if ($message->reactions !== []) {
                $score = 0;
                foreach ($message->reactions as $reaction) {
                    $emoji = $reaction->emoji;
                    $reactionTotals[$emoji] = ($reactionTotals[$emoji] ?? 0) + $reaction->count;
                    $reactionByUser[$username][$emoji] = ($reactionByUser[$username][$emoji] ?? 0) + $reaction->count;
                    $reactionRecvByUser[$username] = ($reactionRecvByUser[$username] ?? 0) + $reaction->count;
                    $reactionByType[$type] = ($reactionByType[$type] ?? 0) + $reaction->count;
                    $score += $reaction->count;
                }
                $reactionMsgCount[$username] = ($reactionMsgCount[$username] ?? 0) + 1;
                $topReacted[$message->message_id] = ['score' => $score, 'msg' => $message];
                if (count($topReacted) > 50) {
                    uasort($topReacted, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
                    $topReacted = array_slice($topReacted, 0, 20, true);
                }
            }

            if ($message->edited) {
                $editedByUser[$username] = ($editedByUser[$username] ?? 0) + 1;
            }

            if ($message->forwarded_from !== null) {
                $forwardedByUser[$username] = ($forwardedByUser[$username] ?? 0) + 1;
                $forwardedFrom[$message->forwarded_from] = ($forwardedFrom[$message->forwarded_from] ?? 0) + 1;
            }

            if ($message->webpage_site !== null) {
                $site = $message->webpage_site;
                $sitesByUser[$username][$site] = ($sitesByUser[$username][$site] ?? 0) + 1;
            }

            $document = $message->document;
            if ($document !== null && $document->size_kb !== null) {
                $docsByUser[$username] = ($docsByUser[$username] ?? 0) + $document->size_kb;
                $docsCountByUser[$username] = ($docsCountByUser[$username] ?? 0) + 1;
            }

            if ($message->voice?->duration_sec !== null) {
                $voiceSecByUser[$username] = ($voiceSecByUser[$username] ?? 0) + $message->voice->duration_sec;
            }

            if ($message->video?->duration_sec !== null) {
                $videoSecByUser[$username] = ($videoSecByUser[$username] ?? 0) + $message->video->duration_sec;
            }

            if ($message->poll !== null) {
                $pollsByUser[$username] = ($pollsByUser[$username] ?? 0) + 1;
                $pollList[] = $message;
            }

            $day = date('Y-m-d', $message->date);
            $daysAll[$day] = true;
            $daysUser[$username][$day] = true;

            // Последнее сообщение и топ длинных — сравнение с текущим кандидатом.
            $lastByUser[$username] = $message;
            $textLen = mb_strlen($message->text);
            $longest[$message->message_id] = ['len' => $textLen, 'msg' => $message];
            if (count($longest) > 15) {
                uasort($longest, static fn (array $a, array $b): int => $b['len'] <=> $a['len']);
                $longest = array_slice($longest, 0, 10, true);
            }
        }

        // Матрица реплаев и даты целей: второй проход по целям.
        $replyUser = [];
        $replyTargetDate = [];
        foreach ($this->iterate() as $message) {
            if (isset($replyIds[$message->message_id])) {
                $replyUser[$message->message_id] = $message->from?->username ?? '';
                $replyTargetDate[$message->message_id] = $message->date;
            }
        }
        $replies = [];
        $selfReplies = [];
        $speedDeltas = [];
        foreach ($replyPairs as [$from, $toId, $replyDate]) {
            $to = $replyUser[$toId] ?? '';
            if ($to === '' || $from === '') {
                continue;
            }
            $replies[$from][$to] = ($replies[$from][$to] ?? 0) + 1;
            if ($to === $from) {
                $selfReplies[$from] = ($selfReplies[$from] ?? 0) + 1;
            }
            // Скорость ответа: только «живые» реплаи в пределах недели.
            $targetDate = $replyTargetDate[$toId] ?? null;
            if ($targetDate !== null) {
                $delta = $replyDate - $targetDate;
                if ($delta >= 0 && $delta <= 7 * 86400) {
                    $speedDeltas[$from][] = $delta;
                }
            }
        }
        foreach ($replies as &$byUser) {
            arsort($byUser);
        }
        unset($byUser);
        ksort($replies);

        // Медиана скорости ответа по юзерам (в секундах).
        $replySpeed = [];
        foreach ($speedDeltas as $user => $deltas) {
            sort($deltas);
            $n = count($deltas);
            $median = $n > 0 ? $deltas[intdiv($n, 2)] : 0;
            $replySpeed[$user] = [
                'count' => $n,
                'median_sec' => $median,
                'mean_sec' => $n > 0 ? (int)round(array_sum($deltas) / $n) : 0,
            ];
        }

        $this->aggregated = [
            'total' => $total,
            'totalStrlen' => $totalStrlen,
            'userCount' => $userCount,
            'userStrlen' => $userStrlen,
            'dateCounts' => $dateCounts,
            'dayHour' => $dayHour,
            'typeCount' => $typeCount,
            'typeUserCount' => $typeUserCount,
            'replies' => $replies,
            'first' => $first,
            'reactionTotals' => $reactionTotals,
            'reactionByUser' => $reactionByUser,
            'reactionRecvByUser' => $reactionRecvByUser,
            'reactionMsgCount' => $reactionMsgCount,
            'reactionByType' => $reactionByType,
            'topReacted' => $topReacted,
            'editedByUser' => $editedByUser,
            'forwardedByUser' => $forwardedByUser,
            'forwardedFrom' => $forwardedFrom,
            'sitesByUser' => $sitesByUser,
            'docsByUser' => $docsByUser,
            'docsCountByUser' => $docsCountByUser,
            'voiceSecByUser' => $voiceSecByUser,
            'videoSecByUser' => $videoSecByUser,
            'pollsByUser' => $pollsByUser,
            'pollList' => $pollList,
            'daysAll' => $daysAll,
            'daysUser' => $daysUser,
            'lastByUser' => $lastByUser,
            'longest' => $longest,
            'selfReplies' => $selfReplies,
            'replySpeed' => $replySpeed,
        ];
    }

    public function count(): int
    {
        $this->aggregate();
        return $this->aggregated['total'];
    }

    public function firstByDate(): ?Message
    {
        $this->aggregate();
        return $this->aggregated['first'];
    }

    public function usernames(): array
    {
        $this->aggregate();
        $users = array_keys($this->aggregated['userCount']);
        sort($users);
        return $users;
    }

    public function countByUser(): array
    {
        $this->aggregate();
        $result = $this->aggregated['userCount'];
        arsort($result);
        return $result;
    }

    public function strlenByUser(): array
    {
        $this->aggregate();
        $result = $this->aggregated['userStrlen'];
        arsort($result);
        return $result;
    }

    public function strlenTotal(): int
    {
        $this->aggregate();
        return $this->aggregated['totalStrlen'];
    }

    public function medianStrlenByUser(): array
    {
        $this->aggregate();
        $result = [];
        foreach ($this->aggregated['userCount'] as $user => $count) {
            $result[$user] = $count > 0
                ? (int)round($this->aggregated['userStrlen'][$user] / $count)
                : 0;
        }
        arsort($result);
        return $result;
    }

    public function countByDate(string $format): array
    {
        $this->aggregate();
        $result = $this->aggregated['dateCounts'][$format] ?? [];
        ksort($result);
        return $result;
    }

    public function medianByDate(string $format): float
    {
        $this->aggregate();
        $items = $this->aggregated['dateCounts'][$format] ?? [];
        if ($items === []) {
            return 0.0;
        }
        return round(array_sum($items) / count($items), 2);
    }

    public function countByUserDayHour(): array
    {
        $this->aggregate();
        $result = [];
        foreach ($this->aggregated['dayHour'] as $username => $days) {
            $gr = [];
            foreach ($days as $weekNum => $hours) {
                // Дни идут в порядке недели (1..7), не по алфавиту названий.
                $gr[(int)$weekNum] = [
                    'name' => DateHelper::getDayName((int)$weekNum),
                    'count' => array_sum($hours),
                    'hours' => $hours,
                ];
            }
            ksort($gr, SORT_NUMERIC);
            $result[$username] = [
                'hash' => md5($username),
                'total' => $this->aggregated['userCount'][$username] ?? 0,
                'dayOfWeek' => [],
                'dayOfWeekByHour' => [],
            ];
            foreach ($gr as $day) {
                $result[$username]['dayOfWeek'][$day['name']] = $day['count'];
                $result[$username]['dayOfWeekByHour'][$day['name']] = $this->fillHours($day['hours']);
            }
        }
        uasort($result, static fn (array $a, array $b) => $b['total'] <=> $a['total']);
        return $result;
    }

    /**
     * Тепловая карта активности чата: дни недели (1..7) × часы (0..23).
     *
     * @return array{days: array<int, array{name: string, count: int, hours: array<int, int>}>, max: int}
     */
    public function activityHeatmap(): array
    {
        $this->aggregate();
        $days = [];
        $max = 0;
        foreach (range(1, 7) as $weekNum) {
            $hours = array_fill(0, 24, 0);
            foreach ($this->aggregated['dayHour'] as $userDays) {
                foreach ($userDays[$weekNum] ?? [] as $hour => $cnt) {
                    $hours[$hour] += $cnt;
                }
            }
            $days[$weekNum] = [
                'name' => DateHelper::getDayName($weekNum),
                'count' => array_sum($hours),
                'hours' => $hours,
            ];
            $max = max($max, ...$hours);
        }
        return ['days' => $days, 'max' => $max];
    }

    public function countByType(): array
    {
        $this->aggregate();
        return $this->aggregated['typeCount'];
    }

    public function countByTypeAndUser(): array
    {
        $this->aggregate();
        $result = $this->aggregated['typeUserCount'];
        foreach ($result as &$byUser) {
            arsort($byUser);
        }
        unset($byUser);
        return $result;
    }

    public function repliesMatrix(): array
    {
        $this->aggregate();
        return $this->aggregated['replies'];
    }

    /**
     * Реакции по эмодзи во всём чате: эмодзи => сумма количеств, по убыванию.
     *
     * @return array<string, int>
     */
    public function reactionTotals(): array
    {
        $this->aggregate();
        $result = $this->aggregated['reactionTotals'];
        arsort($result);
        return $result;
    }

    /**
     * Реакции по юзерам: юзер => [эмодзи => сумма количеств].
     *
     * @return array<string, array<string, int>>
     */
    public function reactionByUser(): array
    {
        $this->aggregate();
        $result = $this->aggregated['reactionByUser'];
        foreach ($result as &$byEmoji) {
            arsort($byEmoji);
        }
        unset($byEmoji);
        ksort($result);
        return $result;
    }

    /**
     * Лидеры «лайков»: юзер => сколько реакций получил, по убыванию.
     *
     * @return array<string, int>
     */
    public function reactionRecvByUser(): array
    {
        $this->aggregate();
        $result = $this->aggregated['reactionRecvByUser'];
        arsort($result);
        return $result;
    }

    /**
     * Реакции по типам сообщений: тип => сумма реакций, по убыванию.
     *
     * @return array<string, int>
     */
    public function reactionByType(): array
    {
        $this->aggregate();
        $result = $this->aggregated['reactionByType'];
        arsort($result);
        return $result;
    }

    /**
     * Топ сообщений по сумме реакций.
     *
     * @return array<int, array{message: Message, score: int}>
     */
    public function topReactedMessages(int $n = 20): array
    {
        $this->aggregate();
        $items = $this->aggregated['topReacted'];
        uasort($items, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $items = array_slice($items, 0, $n, true);
        $result = [];
        foreach ($items as $item) {
            $result[] = ['message' => $item['msg'], 'score' => $item['score']];
        }
        return $result;
    }

    /**
     * Правки по юзерам: юзер => количество исправленных сообщений, по убыванию.
     *
     * @return array<string, int>
     */
    public function editedByUser(): array
    {
        $this->aggregate();
        $result = $this->aggregated['editedByUser'];
        arsort($result);
        return $result;
    }

    /**
     * Пересланные сообщения по юзерам: юзер => количество, по убыванию.
     *
     * @return array<string, int>
     */
    public function forwardedByUser(): array
    {
        $this->aggregate();
        $result = $this->aggregated['forwardedByUser'];
        arsort($result);
        return $result;
    }

    /**
     * Источники пересылок: «Forwarded from X» => количество, по убыванию.
     *
     * @return array<string, int>
     */
    public function forwardedSources(): array
    {
        $this->aggregate();
        $result = $this->aggregated['forwardedFrom'];
        arsort($result);
        return $result;
    }

    /**
     * Сайты веб-превью по юзерам: юзер => [сайт => количество].
     *
     * @return array<string, array<string, int>>
     */
    public function sitesByUser(): array
    {
        $this->aggregate();
        $result = $this->aggregated['sitesByUser'];
        foreach ($result as &$bySite) {
            arsort($bySite);
        }
        unset($bySite);
        ksort($result);
        return $result;
    }

    /**
     * Медиа-вес по юзерам: документы (КБ/шт), голосовые и видео (сек).
     * Сортировка по суммарной длительности голосовых + видео, по убыванию.
     *
     * @return array<string, array{docs_kb: float, docs_count: int, voice_sec: int, video_sec: int}>
     */
    public function mediaWeight(): array
    {
        $this->aggregate();
        $result = [];
        foreach ($this->aggregated['userCount'] as $username => $count) {
            $result[$username] = [
                'docs_kb' => round($this->aggregated['docsByUser'][$username] ?? 0.0, 1),
                'docs_count' => $this->aggregated['docsCountByUser'][$username] ?? 0,
                'voice_sec' => $this->aggregated['voiceSecByUser'][$username] ?? 0,
                'video_sec' => $this->aggregated['videoSecByUser'][$username] ?? 0,
            ];
        }
        uasort($result, static fn (array $a, array $b): int => ($b['voice_sec'] + $b['video_sec']) <=> ($a['voice_sec'] + $a['video_sec']));
        return $result;
    }

    /**
     * Опросы: юзер => количество (по убыванию) + список сообщений-опросов
     * с вопросом и вариантами ответов (по дате).
     *
     * @return array{by_user: array<string, int>, polls: Message[]}
     */
    public function pollsStats(): array
    {
        $this->aggregate();
        $byUser = $this->aggregated['pollsByUser'];
        arsort($byUser);
        $polls = $this->aggregated['pollList'];
        usort($polls, static fn (Message $a, Message $b): int => $a->date <=> $b->date);
        return ['by_user' => $byUser, 'polls' => $polls];
    }

    /**
     * Скорость ответа: юзер => {count, median_sec, mean_sec} по «живым»
     * реплаям (в пределах недели). Сортировка по медиане, по возрастанию.
     *
     * @return array<string, array{count: int, median_sec: int, mean_sec: int}>
     */
    public function replySpeed(): array
    {
        $this->aggregate();
        $result = $this->aggregated['replySpeed'];
        uasort($result, static fn (array $a, array $b): int => $a['median_sec'] <=> $b['median_sec']);
        return $result;
    }

    /**
     * Хронотипы: доля активности по зонам суток и пиковый час.
     * Зоны: 0–5 ночь, 6–11 утро, 12–17 день, 18–23 вечер.
     *
     * @return array<string, array{total: int, night: int, morning: int, day: int, evening: int, peak_hour: int, type: string}>
     */
    public function chronotypes(): array
    {
        $this->aggregate();
        $result = [];
        foreach ($this->aggregated['dayHour'] as $username => $days) {
            $zones = ['night' => 0, 'morning' => 0, 'day' => 0, 'evening' => 0];
            $hourTotal = array_fill(0, 24, 0);
            foreach ($days as $hours) {
                foreach ($hours as $hour => $cnt) {
                    $zones[$this->zoneOfHour((int)$hour)] += $cnt;
                    $hourTotal[$hour] += $cnt;
                }
            }
            arsort($hourTotal);
            $peakHour = array_key_first($hourTotal);
            $total = array_sum($zones);
            $type = $total > 0 ? $this->zoneOfHour($peakHour) : 'day';
            $result[$username] = [
                'total' => $total,
                'night' => $zones['night'],
                'morning' => $zones['morning'],
                'day' => $zones['day'],
                'evening' => $zones['evening'],
                'peak_hour' => $peakHour,
                'type' => $type,
            ];
        }
        uasort($result, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        return $result;
    }

    /**
     * Самый длинный период ежедневной активности чата (дни подряд).
     *
     * @return array{start: string, end: string, days: int}|null
     */
    public function longestStreak(): ?array
    {
        $this->aggregate();
        return $this->findLongestStreak(array_keys($this->aggregated['daysAll']));
    }

    /**
     * Топ пользователей по самой длинной серии дней подряд.
     *
     * @return array<string, array{start: string, end: string, days: int}>
     */
    public function userStreaks(int $n = 10): array
    {
        $this->aggregate();
        $result = [];
        foreach ($this->aggregated['daysUser'] as $username => $days) {
            $streak = $this->findLongestStreak(array_keys($days));
            if ($streak !== null) {
                $result[$username] = $streak;
            }
        }
        uasort($result, static fn (array $a, array $b): int => $b['days'] <=> $a['days']);
        return array_slice($result, 0, $n, true);
    }

    /**
     * Последняя активность: юзер => {date, text} последнего сообщения,
     * по дате, по убыванию (самые «свежие» сверху).
     *
     * @return array<string, array{date: int, text: string}>
     */
    public function lastActivity(): array
    {
        $this->aggregate();
        $result = [];
        foreach ($this->aggregated['lastByUser'] as $username => $message) {
            $result[$username] = ['date' => $message->date, 'text' => $message->text];
        }
        uasort($result, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);
        return $result;
    }

    /**
     * Кто отвечает сам себе (реплай на своё же сообщение), по убыванию.
     *
     * @return array<string, int>
     */
    public function selfReplies(): array
    {
        $this->aggregate();
        $result = $this->aggregated['selfReplies'];
        arsort($result);
        return $result;
    }

    /**
     * Самые длинные сообщения.
     *
     * @return array<int, array{message: Message, len: int}>
     */
    public function longestMessages(int $n = 10): array
    {
        $this->aggregate();
        $items = $this->aggregated['longest'];
        uasort($items, static fn (array $a, array $b): int => $b['len'] <=> $a['len']);
        $items = array_slice($items, 0, $n, true);
        $result = [];
        foreach ($items as $item) {
            $result[] = ['message' => $item['msg'], 'len' => $item['len']];
        }
        return $result;
    }

    /**
     * Зона суток для часа (хронотипы).
     */
    private function zoneOfHour(int $hour): string
    {
        if ($hour < 6) {
            return 'night';
        }
        if ($hour < 12) {
            return 'morning';
        }
        if ($hour < 18) {
            return 'day';
        }
        return 'evening';
    }

    /**
     * Ищет самую длинную последовательность календарных дней подряд.
     *
     * @param string[] $days даты вида 'Y-m-d'
     * @return array{start: string, end: string, days: int}|null
     */
    private function findLongestStreak(array $days): ?array
    {
        if ($days === []) {
            return null;
        }
        sort($days);
        $best = ['start' => $days[0], 'end' => $days[0], 'days' => 1];
        $current = $best;
        $prevTs = strtotime($days[0]);
        foreach (array_slice($days, 1) as $day) {
            $ts = strtotime($day);
            // Календарное «завтра» (устойчиво к переходу на летнее время).
            if (date('Y-m-d', $prevTs + 86400) === $day) {
                $current['days']++;
                $current['end'] = $day;
                if ($current['days'] > $best['days']) {
                    $best = $current;
                }
            } else {
                $current = ['start' => $day, 'end' => $day, 'days' => 1];
            }
            $prevTs = $ts;
        }
        return $best;
    }

    /**
     * Дополняет часы счётчиками 0..23 для графика.
     *
     * @param array<int, int> $hours
     * @return array<int, int>
     */
    private function fillHours(array $hours): array
    {
        foreach (range(0, 23) as $hour) {
            if (!array_key_exists($hour, $hours)) {
                $hours[$hour] = 0;
            }
        }
        ksort($hours);
        return $hours;
    }

    /**
     * Тип сообщения по полям-медиа; порядок проверки как в прежнем CountByTypeAndUser,
     * contact добавлен как новый тип (см. ExportMessageSource::MEDIA_SELECTORS).
     */
    private function typeOf(Message $message): string
    {
        $types = ['poll', 'video', 'audio', 'photo', 'sticker', 'voice', 'location', 'animation', 'document', 'contact'];
        foreach ($types as $type) {
            if ($message->{$type} instanceof MessageType) {
                return $type;
            }
        }
        return 'message';
    }
}
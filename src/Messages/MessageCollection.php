<?php

namespace ChatStats\Messages;

use ChatStats\DateHelper;
use ChatStats\Entity\Message;
use ChatStats\Entity\MessageType;
use Illuminate\Support\Collection;
use function array_key_exists;
use function arsort;
use function collect;
use function date;
use function ksort;
use function mb_strlen;
use function md5;
use function range;
use function round;

/**
 * Value-object над коллекцией сообщений: все агрегации статистики живут здесь.
 * В конструкторе выполняется линковка реплаев (ReplyLinker).
 */
final readonly class MessageCollection
{
    public function __construct(private Collection $messages)
    {
        ReplyLinker::link($this->messages);
    }

    public function count(): int
    {
        return $this->messages->count();
    }

    public function firstByDate(): ?Message
    {
        if ($this->messages->isEmpty()) {
            return null;
        }
        /** @var Message $message */
        $message = $this->messages->sortBy('date')->first();
        return $message;
    }

    public function usernames(): array
    {
        $users = collect([]);
        /** @var Message $message */
        foreach ($this->messages as $message) {
            if (!$users->has($message->from->username)) {
                $users[$message->from->username] = 1;
            }
        }
        return $users->keys()->sort()->values()->all();
    }

    public function countByUser(): array
    {
        return $this->messages
            ->countBy(static fn (Message $item) => $item->from->username)
            ->sortDesc()
            ->all();
    }

    public function strlenByUser(): array
    {
        return $this->messages
            ->groupBy(static fn (Message $item) => $item->from->username)
            ->map(static fn (Collection $messages) => $messages->sum(
                static fn (Message $message) => mb_strlen($message->text)
            ))
            ->sortDesc()
            ->all();
    }

    public function medianStrlenByUser(): array
    {
        return $this->messages
            ->groupBy(static fn (Message $item) => $item->from->username)
            ->map(function (Collection $messages) {
                $total = $messages->sum(static fn (Message $message) => mb_strlen($message->text));
                return $messages->count() > 0 ? round($total / $messages->count()) : 0;
            })
            ->sortDesc()
            ->all();
    }

    public function countByDate(string $format): array
    {
        return $this->messages
            ->countBy(static fn (Message $item) => date($format, $item->date))
            ->sortKeys()
            ->all();
    }

    public function medianByDate(string $format): float
    {
        $items = $this->messages->countBy(static fn (Message $item) => date($format, $item->date));
        if ($items->isEmpty()) {
            return 0.0;
        }
        return round($items->sum() / $items->count(), 2);
    }

    public function countByUserDayHour(): array
    {
        return $this->messages
            ->groupBy(static fn (Message $item) => $item->from->username)
            ->map(function (Collection $messages, $username) {
                $gr = $messages->groupBy(static fn (Message $message) => date('N', $message->date))
                    ->sortKeys()
                    ->mapWithKeys(static fn ($week, $weekNum) => [DateHelper::getDayName($weekNum) => $week]);
                return [
                    'hash' => md5($username),
                    'total' => $messages->count(),
                    'dayOfWeek' => $gr->map(static fn (Collection $day) => $day->count())->all(),
                    'dayOfWeekByHour' => $gr->map(function (Collection $day) {
                        $hours = $day->countBy(static fn (Message $message) => date('G', $message->date))->all();
                        foreach (range(0, 23) as $hour) {
                            if (!array_key_exists($hour, $hours)) {
                                $hours[$hour] = 0;
                            }
                        }
                        ksort($hours);
                        return $hours;
                    })->all(),
                ];
            })
            ->sortByDesc('total')
            ->all();
    }

    public function countByType(): array
    {
        return $this->messages
            ->countBy(fn (Message $item) => $this->typeOf($item))
            ->all();
    }

    public function countByTypeAndUser(): array
    {
        return $this->messages
            ->groupBy(fn (Message $item) => $this->typeOf($item))
            ->map(static fn (Collection $messages) => $messages
                ->countBy(static fn (Message $message) => $message->from->username)
                ->sortDesc()
                ->all())
            ->all();
    }

    public function repliesMatrix(): array
    {
        $users = [];
        $this->messages->filter(static fn (Message $message) => (bool)$message->reply_to_message)
            ->each(function (Message $message) use (&$users) {
                $from = $message->from->username;
                $to = $message->reply_to_message->from->username;
                if (empty($users[$from])) {
                    $users[$from] = [$to => 0];
                }
                if (empty($users[$from][$to])) {
                    $users[$from][$to] = 0;
                }
                $users[$from][$to]++;
            });
        return collect($users)
            ->mapWithKeys(static function ($value, $key) {
                arsort($value);
                return [$key => $value];
            })
            ->sortKeys()
            ->all();
    }

    public function messages(): Collection
    {
        return $this->messages;
    }

    /**
     * Тип сообщения по полям-медиа; порядок проверки как в прежнем CountByTypeAndUser.
     */
    private function typeOf(Message $message): string
    {
        $types = ['poll', 'video', 'audio', 'photo', 'sticker', 'voice', 'location', 'animation', 'document'];
        foreach ($types as $type) {
            if ($message->{$type} instanceof MessageType) {
                return $type;
            }
        }
        return 'message';
    }
}
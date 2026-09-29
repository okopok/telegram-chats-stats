<?php

namespace ChatStats\Messages;

use ChatStats\Entity\Animation;
use ChatStats\Entity\Audio;
use ChatStats\Entity\Contact;
use ChatStats\Entity\Document;
use ChatStats\Entity\Location;
use ChatStats\Entity\Message;
use ChatStats\Entity\Photo;
use ChatStats\Entity\Poll;
use ChatStats\Entity\Sticker;
use ChatStats\Entity\User;
use ChatStats\Entity\Video;
use ChatStats\Entity\Voice;
use ChatStats\UserHelper;
use DateTime;
use DirectoryIterator;
use Generator;
use Illuminate\Support\Collection;
use Symfony\Component\DomCrawler\Crawler;
use Traversable;
use function collect;
use function file_get_contents;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function str_contains;

/**
 * Читает HTML-экспорт чата Telegram Desktop.
 */
final class ExportMessageSource implements MessageSource
{
    /**
     * CSS-селекторы типов медиа в порядке приоритета; photo/sticker
     * определяются по title внутри div.media_photo.
     */
    private const MEDIA_SELECTORS = [
        ['selector' => 'div.media_photo', 'field' => 'photo', 'titleContains' => 'photo'],
        ['selector' => 'div.media_photo', 'field' => 'sticker', 'titleContains' => 'sticker'],
        ['selector' => '.media_video', 'field' => 'video'],
        ['selector' => '.media_voice_message', 'field' => 'voice'],
        ['selector' => '.media_live_location', 'field' => 'location'],
        ['selector' => '.media_audio_file', 'field' => 'audio'],
        ['selector' => '.media_poll', 'field' => 'poll'],
        ['selector' => '.media_document', 'field' => 'document'],
        ['selector' => '.media_animation', 'field' => 'animation'],
        ['selector' => '.media_contact', 'field' => 'contact'],
    ];

    private string $dir;

    private array $namesMap;

    private ?User $lastUser = null;

    public function __construct(string $dir, array $namesMap)
    {
        $this->dir = $dir;
        $this->namesMap = $namesMap;
    }

    public function getMessages(): MessageCollection
    {
        $messages = collect([]);
        /** @var Collection $stack */
        foreach ($this->getMessagesByStacks() as $stack) {
            if ($stack->isEmpty()) {
                continue;
            }
            /** @var Message|null $message */
            foreach ($stack as $message) {
                if ($message === null) {
                    continue;
                }
                $messages->put($message->message_id, $message);
            }
        }

        return new MessageCollection($messages);
    }

    protected function getMessagesByStacks(): Traversable
    {
        foreach ($this->parseDir() as $crawler) {
            yield $this->getMessageStacks($this->getMessagesList($crawler));
        }
    }

    protected function parseDir(): ?Generator
    {
        $files = [];
        foreach (new DirectoryIterator($this->dir) as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->isDir()) {
                continue;
            }
            if ($fileInfo->getFilename() === '.DS_Store') {
                continue;
            }
            $files[] = $fileInfo->getRealPath();
        }
        natsort($files);
        foreach ($files as $file) {
            yield new Crawler(file_get_contents($file));
        }
    }

    protected function getMessageStacks(Crawler $messages): Collection
    {
        $ms = $messages->each(function (Crawler $item, $i) {
            if ($this->isServiceMessage($item)) {
                return null;
            }
            if (!$this->isJoined($item)) {
                $this->lastUser = $this->getFrom($item);
            }
            return $this->getMessage($this->lastUser, $item);
        });
        return collect($ms)->filter();
    }

    protected function isServiceMessage(Crawler $message): bool
    {
        return $message->attr('class') === 'message service';
    }

    protected function isJoined(Crawler $message): bool
    {
        return str_contains((string)$message->attr('class'), 'joined');
    }

    protected function getFrom(Crawler $message): User
    {
        $fromName = $message->filter('.from_name')->first()->text('unknown');
        $user = new User();
        $user->username = UserHelper::norm($fromName, $this->namesMap);
        return $user;
    }

    protected function getMessage(User $from, Crawler $message): Message
    {
        $messageEntity = new Message();
        $messageEntity->from = $from;
        $messageEntity->message_id = $this->getMessageId($message);
        $messageEntity->date = (new DateTime($this->getMessageDate($message)))->getTimestamp();
        $messageEntity->text = $this->getText($message);

        if ($this->isReply($message)) {
            $messageEntity->reply_to_id = $this->getReply($message);
        }

        $mediaWrap = $this->getMediaWrap($message);
        if ($this->hasMedia($mediaWrap)) {
            foreach (self::MEDIA_SELECTORS as $rule) {
                $node = $mediaWrap->filter($rule['selector']);
                if (!$node->count()) {
                    continue;
                }
                if (isset($rule['titleContains'])) {
                    $title = $node->filter('div.body > div.title')->first()->text('');
                    if (!str_contains(mb_strtolower($title), $rule['titleContains'])) {
                        continue;
                    }
                }
                $messageEntity->{$rule['field']} = $this->newMediaEntity($rule['field']);
                break;
            }
        }
        return $messageEntity;
    }

    protected function newMediaEntity(string $field): object
    {
        return match ($field) {
            'photo' => new Photo(),
            'sticker' => new Sticker(),
            'video' => new Video(),
            'voice' => new Voice(),
            'location' => new Location(),
            'audio' => new Audio(),
            'poll' => new Poll(),
            'document' => new Document(),
            'animation' => new Animation(),
            'contact' => new Contact(),
        };
    }

    protected function getMessageId(Crawler $message): int
    {
        return (int)preg_replace('/^message/', '', (string)$message->attr('id'));
    }

    protected function getMessageDate(Crawler $message): ?string
    {
        return $message->filter('.date')->first()->attr('title');
    }

    protected function getText(Crawler $message): string
    {
        return $message->filter('.text')->first()->text('');
    }

    protected function isReply(Crawler $message): bool
    {
        return (bool)$message->filter('div.reply_to > a')->count();
    }

    protected function getReply(Crawler $message): int
    {
        $href = (string)$message->filter('div.reply_to > a')->first()->attr('href');
        if (preg_match('/go_to_message(\d+)/', $href, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    protected function getMediaWrap(Crawler $message): Crawler
    {
        return $message->filter('.media_wrap');
    }

    protected function hasMedia(Crawler $wrap): bool
    {
        return (bool)$wrap->count();
    }

    protected function getMessagesList(Crawler $crawler): Crawler
    {
        return $crawler->filter('div.message');
    }
}
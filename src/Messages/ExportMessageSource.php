<?php

namespace ChatStats\Messages;

use ChatStats\DateHelper;
use ChatStats\Entity\Animation;
use ChatStats\Entity\Audio;
use ChatStats\Entity\Contact;
use ChatStats\Entity\Document;
use ChatStats\Entity\Location;
use ChatStats\Entity\Message;
use ChatStats\Entity\Photo;
use ChatStats\Entity\Poll;
use ChatStats\Entity\Reaction;
use ChatStats\Entity\Sticker;
use ChatStats\Entity\User;
use ChatStats\Entity\Video;
use ChatStats\Entity\Voice;
use ChatStats\UserHelper;
use DirectoryIterator;
use Generator;
use Illuminate\Support\Collection;
use Symfony\Component\DomCrawler\Crawler;
use Traversable;
use function array_map;
use function collect;
use function file_get_contents;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function str_contains;
use function strtoupper;
use function trim;

/**
 * Читает HTML-экспорт чата Telegram Desktop.
 */
final class ExportMessageSource implements MessageSource
{
    /**
     * CSS-селекторы типов медиа в порядке приоритета. Парсер поддерживает
     * два поколения разметки экспорта Telegram Desktop:
     *
     * - новая (2025+): фото/стикеры/гифки/видео лежат вне `div.media_wrap`
     *   (`a.photo_wrap`, `a.sticker_wrap`, `a.animated_wrap`, `a.video_file_wrap`),
     *   документы — `.media_file`, локации — `.media_location`;
     * - старая: всё внутри `div.media_wrap`, photo/sticker определяются по
     *   `title` внутри `div.media_photo`, документы — `.media_document`,
     *   локации — `.media_live_location`.
     *
     * `media_video` в новой разметке содержит title «GIF» (гифка) или
     * «Video» (видеофайл), поэтому гифка проверяется раньше.
     */
    private const MEDIA_SELECTORS = [
        ['selector' => 'a.photo_wrap', 'field' => 'photo'],
        ['selector' => 'a.sticker_wrap', 'field' => 'sticker'],
        ['selector' => 'div.media_photo', 'field' => 'photo', 'titleContains' => 'photo'],
        ['selector' => 'div.media_photo', 'field' => 'sticker', 'titleContains' => 'sticker'],
        ['selector' => 'a.animated_wrap', 'field' => 'animation'],
        ['selector' => '.media_video', 'field' => 'animation', 'titleContains' => 'gif'],
        ['selector' => '.media_animation', 'field' => 'animation'],
        ['selector' => 'a.video_file_wrap', 'field' => 'video'],
        ['selector' => '.media_video', 'field' => 'video'],
        ['selector' => '.media_voice_message', 'field' => 'voice'],
        ['selector' => '.media_location', 'field' => 'location'],
        ['selector' => '.media_live_location', 'field' => 'location'],
        ['selector' => '.media_audio_file', 'field' => 'audio'],
        ['selector' => '.media_poll', 'field' => 'poll'],
        ['selector' => '.media_file', 'field' => 'document'],
        ['selector' => '.media_document', 'field' => 'document'],
        ['selector' => '.media_contact', 'field' => 'contact'],
    ];

    private string $dir;

    private array $namesMap;

    private int $limit;

    private int $processed = 0;

    private ?User $lastUser = null;

    public function __construct(string $dir, array $namesMap, int $limit = 0)
    {
        $this->dir = $dir;
        $this->namesMap = $namesMap;
        $this->limit = $limit;
    }

    /** @return \Generator<Message> */
    public function getMessages(): Generator
    {
        foreach ($this->getMessagesByStacks() as $stack) {
            /** @var Message|null $message */
            foreach ($stack as $message) {
                if ($message === null) {
                    continue;
                }
                yield $message;
            }
            if ($this->limit > 0 && $this->processed >= $this->limit) {
                return;
            }
        }
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
            if ($this->limit > 0 && $this->processed >= $this->limit) {
                return null;
            }
            if (!$this->isJoined($item)) {
                $this->lastUser = $this->getFrom($item);
            }
            $this->processed++;
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
        $messageEntity->date = DateHelper::parseExportedDate($this->getMessageDate($message));
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

        // Атрибуты сообщения (не медиа): реакции, правки, пересылки, веб-превью.
        $messageEntity->reactions = $this->getReactions($message);
        $messageEntity->edited = $this->isEdited($message);
        $messageEntity->forwarded_from = $this->getForwardedFrom($message);
        [$webpageSite, $webpageTitle] = $this->getWebpage($message);
        $messageEntity->webpage_site = $webpageSite;
        $messageEntity->webpage_title = $webpageTitle;

        // Детали медиа (имя/размер документа, длительности, опрос).
        $this->fillMediaDetails($messageEntity, $message);

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
        // Медиа ищутся по всему сообщению: в новой разметке экспорта
        // фото/стикеры/гифки/видео лежат вне div.media_wrap.
        return $message;
    }

    /**
     * Реакции на сообщение: emoji + количество (div.reactions > span.reaction).
     *
     * @return Reaction[]
     */
    protected function getReactions(Crawler $message): array
    {
        $reactions = [];
        $message->filter('.reactions .reaction')->each(function (Crawler $node) use (&$reactions): void {
            $reaction = new Reaction();
            $reaction->emoji = trim($node->filter('.emoji')->text(''));
            $reaction->count = (int)trim($node->filter('.count')->text('0'));
            if ($reaction->emoji !== '') {
                $reactions[] = $reaction;
            }
        });
        return $reactions;
    }

    /**
     * Признак правки: в блоке даты текст вида «edited 23:07».
     */
    protected function isEdited(Crawler $message): bool
    {
        return str_contains($message->filter('div.pull_right.date')->first()->text(''), 'edited');
    }

    /**
     * Источник пересылки: текст «Forwarded from Имя» → «Имя» (или null).
     */
    protected function getForwardedFrom(Crawler $message): ?string
    {
        $text = trim($message->filter('.forwarded_from')->first()->text(''));
        if ($text === '') {
            return null;
        }
        $text = preg_replace('/^Forwarded from\s+/i', '', $text);
        return $text !== '' ? $text : null;
    }

    /**
     * Сайт и заголовок веб-превью ссылки (a.webpage_preview), либо null.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function getWebpage(Crawler $message): array
    {
        $node = $message->filter('a.webpage_preview');
        if (!$node->count()) {
            return [null, null];
        }
        $site = trim($node->filter('.webpage_site')->first()->text(''));
        $title = trim($node->filter('.webpage_title')->first()->text(''));
        return [$site !== '' ? $site : null, $title !== '' ? $title : null];
    }

    /**
     * Детали медиа-сущностей: имя/размер документа, длительности голосовых
     * и видео, вопрос и варианты опроса. Все значения опциональны.
     */
    protected function fillMediaDetails(Message $messageEntity, Crawler $message): void
    {
        if ($messageEntity->document !== null) {
            $document = $messageEntity->document;
            /** @var string $name */
            $name = $message->filter('.media_file .title')->first()->text('');
            $document->name = trim($name) !== '' ? trim($name) : null;
            $document->size_kb = $this->parseSizeKb(
                $message->filter('.media_file .status')->first()->text('')
            );
        }

        if ($messageEntity->voice !== null) {
            $messageEntity->voice->duration_sec = $this->parseDuration(
                $message->filter('.media_voice_message .status')->first()->text('')
            );
        }

        if ($messageEntity->video !== null) {
            $messageEntity->video->duration_sec = $this->parseDuration(
                $message->filter('div.video_duration')->first()->text('')
            );
        }

        if ($messageEntity->poll !== null) {
            $poll = $messageEntity->poll;
            /** @var string $question */
            $question = $message->filter('.media_poll .question')->first()->text('');
            $poll->question = trim($question) !== '' ? trim($question) : null;
            $poll->answers = $message->filter('.media_poll .answer')->each(
                static fn (Crawler $node): string => trim($node->text(''), "- \t")
            );
        }
    }

    /**
     * Размер файла из текста «709.8 KB» / «1.2 MB» в килобайтах, либо null.
     */
    protected function parseSizeKb(string $status): ?float
    {
        if (preg_match('/([\d.]+)\s*(KB|MB)/i', $status, $matches)) {
            $value = (float)$matches[1];
            return strtoupper($matches[2]) === 'MB' ? $value * 1024 : $value;
        }
        return null;
    }

    /**
     * Длительность из текста «0:14» (мм:сс) или «1:02:33» (чч:мм:сс) в секундах.
     */
    protected function parseDuration(string $text): ?int
    {
        $text = trim($text);
        if ($text === '' || !preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $text, $matches)) {
            return null;
        }
        $hours = isset($matches[1]) && $matches[1] !== '' ? (int)$matches[1] : 0;
        return $hours * 3600 + (int)$matches[2] * 60 + (int)$matches[3];
    }

    protected function hasMedia(Crawler $message): bool
    {
        return (bool)$message->filter(
            'a.photo_wrap, a.sticker_wrap, a.animated_wrap, a.video_file_wrap,'
            . ' .media_photo, .media_video, .media_voice_message, .media_location,'
            . ' .media_live_location, .media_audio_file, .media_poll, .media_file,'
            . ' .media_document, .media_contact'
        )->count();
    }

    protected function getMessagesList(Crawler $crawler): Crawler
    {
        return $crawler->filter('div.message');
    }
}
<?php

namespace App\Imap;

use Carbon\CarbonImmutable;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Support\BodyPartDecoder;
use Illuminate\Support\Collection;
use Illuminate\Support\ItemNotFoundException;
use ZBateson\MailMimeParser\Header\IHeaderPart;

class MailReader
{
    public const int MAX_BODY_CHARACTERS = 20_000;

    public const int MAX_HEADER_CHARACTERS = 50_000;

    public const int MAX_ATTACHMENT_BYTES = 5_000_000;

    private const int MAX_THREAD_REFERENCES = 25;

    public function __construct(private MailboxFolders $folders) {}

    /**
     * @return list<array{name: string, flags: list<string>}>
     */
    public function folders(): array
    {
        return $this->folders->visible()
            ->map(fn (FolderInterface $folder) => [
                'name' => $this->pathOf($folder),
                'flags' => array_values($folder->flags()),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{folder: string, messages: int, unread: int, uid_next: int}>
     */
    public function status(?string $folder = null): array
    {
        $folders = $folder === null ? $this->folders->visible() : collect([$this->folders->find($folder)]);

        return $folders
            ->map(function (FolderInterface $folder) {
                $status = $folder->status();

                return [
                    'folder' => $this->pathOf($folder),
                    'messages' => (int) ($status['MESSAGES'] ?? 0),
                    'unread' => (int) ($status['UNSEEN'] ?? 0),
                    'uid_next' => (int) ($status['UIDNEXT'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{root: string, resource: string, usage: int, limit: int}>
     */
    public function quota(): array
    {
        try {
            $roots = $this->folders->mailbox()->inbox()->quota();
        } catch (ImapCapabilityException) {
            throw new MailboxUnavailable('This mail server does not report storage quotas.');
        }

        return collect($roots)
            ->flatMap(fn (array $resources, string $root) => collect($resources)
                ->map(fn (array $values, string $resource) => ['root' => $root, 'resource' => $resource, ...$values])
                ->values())
            ->all();
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public function search(string $folder, array $criteria, int $limit, int $page = 1): array
    {
        return $this->searchIn($this->folders->find($folder), $criteria, $limit, $page);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public function searchAllFolders(array $criteria, int $limit): array
    {
        return $this->folders->visible()
            ->flatMap(fn (FolderInterface $folder) => collect($this->searchIn($folder, $criteria, $limit))
                ->map(fn (array $summary) => ['folder' => $this->pathOf($folder), ...$summary]))
            ->sortByDesc(fn (array $summary) => $this->chronologicalKey($summary))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    public function count(string $folder, array $criteria): int
    {
        $query = $this->folders->find($folder)->messages();

        $this->applyCriteria($query, $criteria);

        try {
            return $query->count();
        } catch (ItemNotFoundException) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function message(string $folder, int $uid): ?array
    {
        $message = $this->find($folder, $uid);

        if ($message === null) {
            return null;
        }

        $body = str($message->text(fetch: true) ?? HtmlToText::convert($message->html(fetch: true) ?? ''));

        return [
            ...$this->summary($message),
            'cc' => $this->addresses($message->cc()),
            'reply_to' => $this->address($message->replyTo()),
            'message_id' => $message->messageId(),
            'attachments' => $this->attachmentParts($message)
                ->map(fn (BodyStructurePart $part, int $index) => [
                    'index' => $index,
                    'filename' => $part->filename(),
                    'type' => $part->contentType(),
                    'size' => $part->size(),
                ])
                ->all(),
            'body' => $body->substr(0, self::MAX_BODY_CHARACTERS)->toString(),
            'truncated' => $body->length() > self::MAX_BODY_CHARACTERS,
        ];
    }

    /**
     * @return array{headers: string, truncated: bool}|null
     */
    public function headers(string $folder, int $uid): ?array
    {
        $message = $this->find($folder, $uid);

        if ($message === null) {
            return null;
        }

        $headers = str($message->head())->trim();

        return [
            'headers' => $headers->substr(0, self::MAX_HEADER_CHARACTERS)->toString(),
            'truncated' => $headers->length() > self::MAX_HEADER_CHARACTERS,
        ];
    }

    /**
     * @return array{subject: ?string, message_id: ?string, references: list<string>}|null
     */
    public function replyHeaders(string $folder, int $uid): ?array
    {
        $message = $this->find($folder, $uid);

        if ($message === null) {
            return null;
        }

        return [
            'subject' => $message->subject(),
            'message_id' => filled($message->messageId()) ? trim($message->messageId(), '<>') : null,
            'references' => $this->threadIds($message)->map(fn (string $id) => trim($id, '<>'))->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function thread(string $folder, int $uid): ?array
    {
        $message = $this->find($folder, $uid);

        if ($message === null) {
            return null;
        }

        $ids = $this->threadIds($message);

        return $this->folders->visible()
            ->flatMap(function (FolderInterface $folder) use ($ids) {
                $query = $folder->messages()->withHeaders()->withFlags()->withSize()->limit(100);

                $query->where(function (ImapQueryBuilder $query) use ($ids) {
                    foreach ($ids as $id) {
                        $query->orWhere('HEADER Message-ID', $id)
                            ->orWhere('HEADER References', $id)
                            ->orWhere('HEADER In-Reply-To', $id);
                    }
                });

                return collect($this->summaries($query))
                    ->map(fn (array $summary) => ['folder' => $this->pathOf($folder), ...$summary]);
            })
            ->sortBy(fn (array $summary) => $this->chronologicalKey($summary))
            ->values()
            ->all();
    }

    /**
     * @return array{filename: ?string, type: string, data: string, text: ?string}|null
     */
    public function attachment(string $folder, int $uid, int $index): ?array
    {
        $message = $this->find($folder, $uid);
        $part = $message === null ? null : $this->attachmentParts($message)->get($index);

        if ($part === null) {
            return null;
        }

        if (($part->size() ?? 0) > self::MAX_ATTACHMENT_BYTES * 4 / 3) {
            throw new AttachmentTooLarge('The attachment is larger than the '.(self::MAX_ATTACHMENT_BYTES / 1_000_000).' MB limit.');
        }

        $raw = $message->bodyPart($part->partNumber());

        return [
            'filename' => $part->filename(),
            'type' => $part->contentType(),
            'data' => BodyPartDecoder::binary($part, $raw) ?? '',
            'text' => $part->type() === 'text' ? BodyPartDecoder::text($part, $raw) : null,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function threadIds(Message $message): Collection
    {
        return collect($message->header('References')?->getParts() ?? [])
            ->map(fn (IHeaderPart $part) => $part->getValue())
            ->merge($message->inReplyTo())
            ->push($message->messageId())
            ->filter()
            ->map(fn (string $id) => str_starts_with($id, '<') ? $id : "<{$id}>")
            ->unique()
            ->take(-self::MAX_THREAD_REFERENCES)
            ->values();
    }

    private function pathOf(FolderInterface $folder): string
    {
        return $this->folders->pathOf($folder);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    private function searchIn(FolderInterface $folder, array $criteria, int $limit, int $page = 1): array
    {
        $query = $folder->messages()
            ->withHeaders()
            ->withFlags()
            ->withSize()
            ->limit($limit, $page);

        $this->applyCriteria($query, $criteria);

        return $this->summaries($query);
    }

    /**
     * @param  array{date: ?string, uid: int}  $summary
     * @return array{int, int}
     */
    private function chronologicalKey(array $summary): array
    {
        return [(int) strtotime($summary['date'] ?? ''), $summary['uid']];
    }

    private function find(string $folder, int $uid): ?Message
    {
        $message = $this->folders->find($folder)->messages()
            ->withHeaders()
            ->withFlags()
            ->withSize()
            ->withBodyStructure()
            ->find($uid);

        return $message instanceof Message ? $message : null;
    }

    /**
     * @return Collection<int, BodyStructurePart>
     */
    private function attachmentParts(Message $message): Collection
    {
        return collect($message->bodyStructure()?->attachments() ?? []);
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function applyCriteria(MessageQueryInterface $query, array $criteria): void
    {
        foreach (['from', 'to', 'cc', 'bcc', 'subject', 'text', 'body'] as $field) {
            if (filled($criteria[$field] ?? null)) {
                $query->{$field}($criteria[$field]);
            }
        }

        if (filled($criteria['since'] ?? null)) {
            $query->since(CarbonImmutable::parse($criteria['since']));
        }

        if (filled($criteria['before'] ?? null)) {
            $query->before(CarbonImmutable::parse($criteria['before']));
        }

        if (filled($criteria['larger_than'] ?? null)) {
            $query->larger((int) $criteria['larger_than']);
        }

        if (filled($criteria['smaller_than'] ?? null)) {
            $query->smaller((int) $criteria['smaller_than']);
        }

        if ($criteria['unread_only'] ?? false) {
            $query->unseen();
        }

        if ($criteria['flagged_only'] ?? false) {
            $query->flagged();
        }

        if ($criteria['answered_only'] ?? false) {
            $query->answered();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function summaries(MessageQueryInterface $query): array
    {
        try {
            $messages = $query->get();
        } catch (ItemNotFoundException) {
            // iCloud omits the untagged "* SEARCH" line when nothing matches, and ImapEngine's search() calls firstOrFail on it.
            return [];
        }

        return $messages
            ->map(fn (MessageInterface $message) => $this->summary($message))
            ->values()
            ->all();
    }

    /**
     * @return array{uid: int, date: ?string, from: ?string, to: list<string>, subject: ?string, size: ?int, unread: bool, flagged: bool, answered: bool}
     */
    private function summary(MessageInterface $message): array
    {
        $flags = $message->flags();

        return [
            'uid' => $message->uid(),
            'date' => $message->date()?->toIso8601String(),
            'from' => $this->address($message->from()),
            'to' => $this->addresses($message->to()),
            'subject' => $message->subject(),
            'size' => $message->size(),
            'unread' => ! in_array('\\Seen', $flags, true),
            'flagged' => in_array('\\Flagged', $flags, true),
            'answered' => in_array('\\Answered', $flags, true),
        ];
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return collect($addresses)
            ->map(fn (Address $address) => $this->address($address))
            ->values()
            ->all();
    }

    private function address(?Address $address): ?string
    {
        return match (true) {
            $address === null => null,
            filled($address->name()) => "{$address->name()} <{$address->email()}>",
            default => $address->email(),
        };
    }
}

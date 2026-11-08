<?php

namespace App\Imap;

use Carbon\CarbonImmutable;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Support\BodyPartDecoder;
use DirectoryTree\ImapEngine\Support\Str as ImapStr;
use Illuminate\Support\Collection;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\Str;
use ZBateson\MailMimeParser\Header\IHeaderPart;

class MailReader
{
    public const int MAX_BODY_CHARACTERS = 20_000;

    public const int MAX_HEADER_CHARACTERS = 50_000;

    public const int MAX_ATTACHMENT_BYTES = 5_000_000;

    private const int MAX_THREAD_REFERENCES = 25;

    /**
     * @param  list<string>  $hiddenFolders
     */
    public function __construct(
        private MailboxInterface $mailbox,
        private array $hiddenFolders = [],
    ) {}

    /**
     * @return list<array{name: string, flags: list<string>}>
     */
    public function folders(): array
    {
        return $this->visibleFolders()
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
        $folders = $folder === null ? $this->visibleFolders() : collect([$this->folder($folder)]);

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
            $roots = $this->mailbox()->inbox()->quota();
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
        return $this->searchIn($this->folder($folder), $criteria, $limit, $page);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public function searchAllFolders(array $criteria, int $limit): array
    {
        return $this->visibleFolders()
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
        $query = $this->folder($folder)->messages();

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
     * @return list<array<string, mixed>>|null
     */
    public function thread(string $folder, int $uid): ?array
    {
        $message = $this->find($folder, $uid);

        if ($message === null) {
            return null;
        }

        $ownId = $message->messageId();

        $ids = collect($message->header('References')?->getParts() ?? [])
            ->map(fn (IHeaderPart $part) => $part->getValue())
            ->merge($message->inReplyTo())
            ->push($ownId)
            ->filter()
            ->map(fn (string $id) => str_starts_with($id, '<') ? $id : "<{$id}>")
            ->unique()
            ->take(-self::MAX_THREAD_REFERENCES)
            ->values();

        return $this->visibleFolders()
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

    private function mailbox(): MailboxInterface
    {
        try {
            $this->mailbox->connect();
        } catch (ImapCommandException) {
            throw new MailboxUnavailable('The mail server rejected the login. If this account uses an app-specific password, it may have been revoked.');
        } catch (ImapConnectionException) {
            throw new MailboxUnavailable('Could not reach the mail server. Try again shortly.');
        }

        return $this->mailbox;
    }

    /**
     * @return Collection<int, FolderInterface>
     */
    private function visibleFolders(): Collection
    {
        return collect($this->mailbox()->folders()->get())
            ->reject(fn (FolderInterface $folder) => $this->isHidden($this->pathOf($folder), $folder->delimiter()))
            ->reject(fn (FolderInterface $folder) => in_array('\\Noselect', $folder->flags(), true))
            ->values();
    }

    private function folder(string $path): FolderInterface
    {
        // find() sends the path to LIST as a pattern, so * and % would match folders isHidden() never saw.
        if (Str::contains($path, ['*', '%']) || $this->isHidden($path)) {
            throw UnknownFolder::named($path);
        }

        $folder = $this->mailbox()->folders()->find($path);

        if ($folder === null || $this->isHidden($this->pathOf($folder), $folder->delimiter())) {
            throw UnknownFolder::named($path);
        }

        return $folder;
    }

    private function pathOf(FolderInterface $folder): string
    {
        return ImapStr::fromImapUtf7($folder->path());
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
        $message = $this->folder($folder)->messages()
            ->withHeaders()
            ->withFlags()
            ->withSize()
            ->withBodyStructure()
            ->find($uid);

        return $message instanceof Message ? $message : null;
    }

    private function isHidden(string $path, string $delimiter = '/'): bool
    {
        return collect($this->hiddenFolders)->contains(
            fn (string $hidden) => $path === $hidden || str_starts_with($path, $hidden.$delimiter),
        );
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

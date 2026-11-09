<?php

namespace App\Imap;

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\Support\Str as ImapStr;
use Illuminate\Support\ItemNotFoundException;

class MailWriter
{
    public function __construct(private MailboxFolders $folders) {}

    /**
     * @param  list<int>  $uids
     */
    public function setFlags(string $folder, array $uids, ?bool $read = null, ?bool $flagged = null): int
    {
        $found = $this->existing($this->folders->find($folder), $uids);

        if ($found === []) {
            return 0;
        }

        $connection = $this->folders->mailbox()->connection();

        if ($read !== null) {
            $connection->store(ImapFlag::Seen->value, $found, mode: $read ? '+' : '-');
        }

        if ($flagged !== null) {
            $connection->store(ImapFlag::Flagged->value, $found, mode: $flagged ? '+' : '-');
        }

        return count($found);
    }

    /**
     * @param  list<int>  $uids
     * @return array{moved: int, folder: string, expunged: bool}
     */
    public function move(string $folder, array $uids, string $destination): array
    {
        return $this->moveTo($this->folders->find($folder), $uids, $this->folders->find($destination));
    }

    /**
     * @param  list<int>  $uids
     * @return array{moved: int, folder: string, expunged: bool}
     */
    public function trash(string $folder, array $uids): array
    {
        return $this->moveTo($this->folders->find($folder), $uids, $this->folders->special(SpecialFolder::Trash));
    }

    /**
     * @param  list<int>  $uids
     * @return array{moved: int, folder: string, expunged: bool}
     */
    public function junk(string $folder, array $uids): array
    {
        return $this->moveTo($this->folders->find($folder), $uids, $this->folders->special(SpecialFolder::Junk));
    }

    public function createFolder(string $name): string
    {
        $this->assertFree($name);

        return $this->folders->pathOf($this->folders->mailbox()->folders()->create($name));
    }

    public function renameFolder(string $from, string $to): string
    {
        $folder = $this->folders->find($from);

        if ($this->folders->isSpecial($folder) || $this->folders->containsHidden($folder)) {
            throw new OperationRefused("The {$this->folders->pathOf($folder)} folder cannot be renamed.");
        }

        $this->assertFree($to);

        $folder->move(ImapStr::toImapUtf7($to));

        return $to;
    }

    public function saveDraft(string $rawMessage): int
    {
        return $this->folders->special(SpecialFolder::Drafts)->messages()->append($rawMessage, [ImapFlag::Draft, ImapFlag::Seen]);
    }

    public function saveSent(string $rawMessage): int
    {
        return $this->folders->special(SpecialFolder::Sent)->messages()->append($rawMessage, [ImapFlag::Seen]);
    }

    public function markAnswered(string $folder, int $uid): void
    {
        $found = $this->existing($this->folders->find($folder), [$uid]);

        if ($found !== []) {
            $this->folders->mailbox()->connection()->store(ImapFlag::Answered->value, $found, mode: '+');
        }
    }

    /**
     * @param  list<int>  $uids
     * @return array{moved: int, folder: string, expunged: bool}
     */
    private function moveTo(FolderInterface $source, array $uids, FolderInterface $destination): array
    {
        $destinationName = $this->folders->pathOf($destination);

        if ($this->folders->pathOf($source) === $destinationName) {
            throw new OperationRefused("The messages are already in {$destinationName}.");
        }

        $found = $this->existing($source, $uids);

        if ($found === []) {
            return ['moved' => 0, 'folder' => $destinationName, 'expunged' => true];
        }

        $mailbox = $this->folders->mailbox();
        $connection = $mailbox->connection();

        if (in_array('MOVE', $mailbox->capabilities(), true)) {
            $connection->move($destination->path(), $found);

            return ['moved' => count($found), 'folder' => $destinationName, 'expunged' => true];
        }

        $connection->copy($destination->path(), $found);
        $connection->store(ImapFlag::Deleted->value, $found, mode: '+');

        // EXPUNGE is folder-wide: it would also permanently remove mail someone else marked \Deleted.
        if ($this->deletedCount($source) > count($found)) {
            return ['moved' => count($found), 'folder' => $destinationName, 'expunged' => false];
        }

        $source->expunge();

        return ['moved' => count($found), 'folder' => $destinationName, 'expunged' => true];
    }

    /**
     * @param  list<int>  $uids
     * @return list<int>
     */
    private function existing(FolderInterface $folder, array $uids): array
    {
        try {
            return $folder->messages()
                ->uid($uids)
                ->limit(count($uids))
                ->get()
                ->map(fn (MessageInterface $message) => $message->uid())
                ->sort()
                ->values()
                ->all();
        } catch (ItemNotFoundException) {
            // iCloud omits the untagged "* SEARCH" line when nothing matches, and ImapEngine's search() calls firstOrFail on it.
            return [];
        }
    }

    private function deletedCount(FolderInterface $folder): int
    {
        try {
            return $folder->messages()->deleted()->count();
        } catch (ItemNotFoundException) {
            return 0;
        }
    }

    private function assertFree(string $name): void
    {
        $this->folders->assertNameAllowed($name);

        if ($this->folders->mailbox()->folders()->find($name) !== null) {
            throw new OperationRefused("A folder named {$name} already exists.");
        }
    }
}

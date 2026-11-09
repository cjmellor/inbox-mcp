<?php

namespace App\Imap;

use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Support\Str as ImapStr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MailboxFolders
{
    /**
     * @param  list<string>  $hiddenFolders
     */
    public function __construct(
        private MailboxInterface $mailbox,
        private array $hiddenFolders = [],
    ) {}

    public function mailbox(): MailboxInterface
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
    public function visible(): Collection
    {
        return collect($this->mailbox()->folders()->get())
            ->reject(fn (FolderInterface $folder) => $this->isHidden($this->pathOf($folder), $folder->delimiter()))
            ->reject(fn (FolderInterface $folder) => in_array('\\Noselect', $folder->flags(), true))
            ->values();
    }

    public function find(string $path): FolderInterface
    {
        $this->assertNameAllowed($path);

        $folder = $this->mailbox()->folders()->find($path);

        if ($folder === null || $this->isHidden($this->pathOf($folder), $folder->delimiter())) {
            throw UnknownFolder::named($path);
        }

        return $folder;
    }

    public function special(SpecialFolder $use): FolderInterface
    {
        $folders = $this->visible();

        $folder = $folders->first(fn (FolderInterface $folder) => in_array($use->value, $folder->flags(), true))
            ?? $folders->first(fn (FolderInterface $folder) => $this->pathOf($folder) === $use->fallbackName());

        throw_if($folder === null, UnknownFolder::class, "This mailbox has no {$use->name} folder.");

        return $folder;
    }

    public function isSpecial(FolderInterface $folder): bool
    {
        return $this->pathOf($folder) === 'INBOX' || collect(SpecialFolder::cases())->contains(
            fn (SpecialFolder $use) => in_array($use->value, $folder->flags(), true) || $this->pathOf($folder) === $use->fallbackName(),
        );
    }

    public function assertNameAllowed(string $path): void
    {
        // find() sends the path to LIST as a pattern, so * and % would match folders isHidden() never saw.
        if (Str::contains($path, ['*', '%']) || $this->isHidden($path)) {
            throw UnknownFolder::named($path);
        }
    }

    public function containsHidden(FolderInterface $folder): bool
    {
        $prefix = $this->pathOf($folder).$folder->delimiter();

        return collect($this->hiddenFolders)->contains(fn (string $hidden) => str_starts_with($hidden, $prefix));
    }

    public function pathOf(FolderInterface $folder): string
    {
        return ImapStr::fromImapUtf7($folder->path());
    }

    private function isHidden(string $path, string $delimiter = '/'): bool
    {
        return collect($this->hiddenFolders)->contains(
            fn (string $hidden) => $path === $hidden || str_starts_with($path, $hidden.$delimiter),
        );
    }
}

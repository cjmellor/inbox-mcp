<?php

namespace Tests\Feature\Imap;

use App\Imap\MailboxFolders;
use App\Imap\MailWriter;
use App\Imap\OperationRefused;
use App\Imap\UnknownFolder;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Mailbox;
use Tests\TestCase;

class MailWriterTest extends TestCase
{
    private const array ICLOUD_FOLDERS = [
        '* LIST (\\Noinferiors) "/" "INBOX"',
        '* LIST () "/" "Archive"',
        '* LIST () "/" "Junk"',
        '* LIST () "/" "Notes"',
        '* LIST (\\Trash) "/" "Deleted Messages"',
        '* LIST (\\Sent) "/" "Sent Messages"',
        '* LIST () "/" "Drafts"',
    ];

    public function test_flags_are_stored_only_on_messages_that_exist(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...$this->folderLookup('INBOX', '\\Noinferiors', 2),
            ...$this->selection(3),
            '* SEARCH 5',
            'TAG4 OK SEARCH completed',
            'TAG5 OK STORE completed',
            'TAG6 OK STORE completed',
        ]);

        $this->assertSame(1, $writer->setFlags('INBOX', [5, 6], read: true, flagged: false));
        $stream->assertWritten('TAG4 UID SEARCH UID 5:6');
        $stream->assertWritten('TAG5 UID STORE 5 +FLAGS.SILENT (\\Seen)');
        $stream->assertWritten('TAG6 UID STORE 5 -FLAGS.SILENT (\\Flagged)');
    }

    public function test_no_flags_are_stored_when_no_message_matches(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...$this->folderLookup('INBOX', '\\Noinferiors', 2),
            ...$this->selection(3),
            'TAG4 OK SEARCH completed',
        ]);

        $this->assertSame(0, $writer->setFlags('INBOX', [99], read: true));
        $stream->assertNotWritten('STORE');
    }

    public function test_trash_copies_marks_deleted_and_expunges_when_the_server_cannot_move(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...$this->folderLookup('INBOX', '\\Noinferiors', 2),
            ...self::ICLOUD_FOLDERS,
            'TAG3 OK LIST completed',
            ...$this->selection(4),
            '* SEARCH 5',
            'TAG5 OK SEARCH completed',
            '* CAPABILITY IMAP4rev1 UIDPLUS',
            'TAG6 OK CAPABILITY completed',
            'TAG7 OK [COPYUID 1 5 900] COPY completed',
            'TAG8 OK STORE completed',
            ...$this->selection(9),
            '* SEARCH 5',
            'TAG10 OK SEARCH completed',
            '* 1 EXPUNGE',
            'TAG11 OK EXPUNGE completed',
        ]);

        $this->assertSame(['moved' => 1, 'folder' => 'Deleted Messages', 'expunged' => true], $writer->trash('INBOX', [5]));
        $stream->assertWritten('TAG7 UID COPY 5 "Deleted Messages"');
        $stream->assertWritten('TAG8 UID STORE 5 +FLAGS.SILENT (\\Deleted)');
        $stream->assertWritten('TAG10 UID SEARCH DELETED');
        $stream->assertWritten('TAG11 EXPUNGE');
    }

    public function test_the_expunge_is_skipped_when_other_messages_are_already_marked_deleted(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...$this->folderLookup('INBOX', '\\Noinferiors', 2),
            ...self::ICLOUD_FOLDERS,
            'TAG3 OK LIST completed',
            ...$this->selection(4),
            '* SEARCH 5',
            'TAG5 OK SEARCH completed',
            '* CAPABILITY IMAP4rev1 UIDPLUS',
            'TAG6 OK CAPABILITY completed',
            'TAG7 OK [COPYUID 1 5 900] COPY completed',
            'TAG8 OK STORE completed',
            ...$this->selection(9),
            '* SEARCH 2 5',
            'TAG10 OK SEARCH completed',
        ]);

        $this->assertSame(['moved' => 1, 'folder' => 'Deleted Messages', 'expunged' => false], $writer->trash('INBOX', [5]));
        $stream->assertNotWritten('EXPUNGE');
    }

    public function test_a_server_that_can_move_gets_a_single_move(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...$this->folderLookup('INBOX', '\\Noinferiors', 2),
            ...$this->folderLookup('Archive', '', 3),
            ...$this->selection(4),
            '* SEARCH 5 6',
            'TAG5 OK SEARCH completed',
            '* CAPABILITY IMAP4rev1 MOVE',
            'TAG6 OK CAPABILITY completed',
            'TAG7 OK MOVE completed',
        ]);

        $this->assertSame(['moved' => 2, 'folder' => 'Archive', 'expunged' => true], $writer->move('INBOX', [5, 6], 'Archive'));
        $stream->assertWritten('TAG7 UID MOVE 5:6 "Archive"');
        $stream->assertNotWritten('COPY');
    }

    public function test_mail_cannot_be_moved_into_a_hidden_folder(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed($this->folderLookup('INBOX', '\\Noinferiors', 2));

        $this->expectException(UnknownFolder::class);

        try {
            $writer->move('INBOX', [5], 'Notes/Recovered Items');
        } finally {
            $stream->assertNotWritten('SELECT');
        }
    }

    public function test_mail_cannot_be_moved_into_its_own_folder(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...$this->folderLookup('Archive', '', 2),
            ...$this->folderLookup('Archive', '', 3),
        ]);

        $this->expectException(OperationRefused::class);

        $writer->move('Archive', [5], 'Archive');
    }

    public function test_junk_is_found_by_name_when_the_server_does_not_flag_it(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...$this->folderLookup('INBOX', '\\Noinferiors', 2),
            ...self::ICLOUD_FOLDERS,
            'TAG3 OK LIST completed',
            ...$this->selection(4),
            'TAG5 OK SEARCH completed',
        ]);

        $this->assertSame(['moved' => 0, 'folder' => 'Junk', 'expunged' => true], $writer->junk('INBOX', [5]));
    }

    public function test_a_missing_special_folder_is_reported(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...$this->folderLookup('INBOX', '\\Noinferiors', 2),
            '* LIST (\\Noinferiors) "/" "INBOX"',
            'TAG3 OK LIST completed',
        ]);

        $this->expectException(UnknownFolder::class);
        $this->expectExceptionMessage('This mailbox has no Junk folder.');

        $writer->junk('INBOX', [5]);
    }

    public function test_special_folders_cannot_be_renamed(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed($this->folderLookup('Drafts', '', 2));

        $this->expectException(OperationRefused::class);
        $this->expectExceptionMessage('The Drafts folder cannot be renamed.');

        try {
            $writer->renameFolder('Drafts', 'Old drafts');
        } finally {
            $stream->assertNotWritten('RENAME');
        }
    }

    public function test_a_folder_holding_a_hidden_folder_cannot_be_renamed(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed($this->folderLookup('Work', '', 2));

        $this->expectException(OperationRefused::class);

        try {
            $writer->renameFolder('Work', 'Public');
        } finally {
            $stream->assertNotWritten('RENAME');
        }
    }

    public function test_a_renamed_folder_is_encoded_for_the_server(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...$this->folderLookup('Archive', '', 2),
            'TAG3 OK LIST completed',
            'TAG4 OK RENAME completed',
        ]);

        $this->assertSame('Entwürfe', $writer->renameFolder('Archive', 'Entwürfe'));
        $stream->assertWritten('TAG4 RENAME "Archive" "Entw&APw-rfe"');
    }

    public function test_a_folder_cannot_be_created_under_a_hidden_name(): void
    {
        [$stream, $writer] = $this->connectedWriter();

        $this->expectException(UnknownFolder::class);

        try {
            $writer->createFolder('Notes/Sneaky');
        } finally {
            $stream->assertNotWritten('CREATE');
        }
    }

    public function test_a_draft_is_appended_to_drafts_as_a_seen_draft(): void
    {
        [$stream, $writer] = $this->connectedWriter();
        $stream->feed([
            ...self::ICLOUD_FOLDERS,
            'TAG2 OK LIST completed',
            ...$this->selection(3),
            '+ Ready for literal data',
            'TAG4 OK [APPENDUID 1 77] APPEND completed',
        ]);

        $this->assertSame(77, $writer->saveDraft("Subject: Hi\r\n\r\nBody"));
        $stream->assertWritten('TAG4 APPEND "Drafts" (\\Draft \\Seen)');
    }

    /**
     * @return array{FakeStream, MailWriter}
     */
    private function connectedWriter(): array
    {
        $stream = new FakeStream;
        $stream->open();
        $stream->feed(['* OK [CAPABILITY IMAP4rev1] ready', 'TAG1 OK user logged in']);

        $mailbox = new Mailbox(['host' => 'imap.example.test', 'username' => 'user', 'password' => 'secret']);
        $mailbox->connect(new ImapConnection($stream));

        return [$stream, new MailWriter(new MailboxFolders($mailbox, ['Notes', 'Work/Secret']))];
    }

    /**
     * @return list<string>
     */
    private function folderLookup(string $name, string $flags, int $tag): array
    {
        return ["* LIST ({$flags}) \"/\" \"{$name}\"", "TAG{$tag} OK LIST completed"];
    }

    /**
     * @return list<string>
     */
    private function selection(int $tag): array
    {
        return ['* 2 EXISTS', "TAG{$tag} OK [READ-WRITE] SELECT completed"];
    }
}

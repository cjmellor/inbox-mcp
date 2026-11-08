<?php

namespace Tests\Feature\Imap;

use App\Imap\AttachmentTooLarge;
use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Imap\UnknownFolder;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Mailbox;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailReaderTest extends TestCase
{
    public function test_folders_hide_configured_folders_and_their_children(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $stream->feed([
            '* LIST (\\Noinferiors) "/" "INBOX"',
            '* LIST () "/" "Archive"',
            '* LIST () "/" "Notes"',
            '* LIST () "/" "Notes/Recovered Items"',
            '* LIST (\\Sent) "/" "Sent Messages"',
            'TAG2 OK LIST completed',
        ]);

        $this->assertSame(['INBOX', 'Archive', 'Sent Messages'], array_column($reader->folders(), 'name'));
    }

    public function test_folders_skip_containers_that_cannot_be_opened(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $stream->feed([
            '* LIST (\\Noinferiors) "/" "INBOX"',
            '* LIST (\\Noselect \\HasChildren) "/" "[Gmail]"',
            'TAG2 OK LIST completed',
        ]);

        $this->assertSame(['INBOX'], array_column($reader->folders(), 'name'));
    }

    public function test_folder_names_are_decoded_before_hiding_and_listing(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $stream->feed([
            '* LIST (\\Noinferiors) "/" "INBOX"',
            '* LIST () "/" "Bills &- Receipts"',
            '* LIST () "/" "Entw&APw-rfe"',
            'TAG2 OK LIST completed',
        ]);

        $this->assertSame(['INBOX', 'Entwürfe'], array_column($reader->folders(), 'name'));
    }

    public function test_hidden_children_follow_the_servers_delimiter(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $stream->feed([
            '* LIST (\\Noinferiors) "." "INBOX"',
            '* LIST () "." "Notes.Recovered Items"',
            'TAG2 OK LIST completed',
        ]);

        $this->assertSame(['INBOX'], array_column($reader->folders(), 'name'));
    }

    public function test_a_decoded_folder_name_can_be_passed_back(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $stream->feed([
            '* LIST () "/" "Entw&APw-rfe"',
            'TAG2 OK LIST completed',
            '* 0 EXISTS',
            'TAG3 OK [READ-WRITE] SELECT completed',
            'TAG4 OK SEARCH completed',
        ]);

        $this->assertSame([], $reader->search('Entwürfe', [], 5));
        $stream->assertWritten('TAG2 LIST "" "Entw&APw-rfe"');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wildcardFolders(): array
    {
        return ['star' => ['N*'], 'percent' => ['%'], 'bare star' => ['*']];
    }

    #[DataProvider('wildcardFolders')]
    public function test_wildcard_folder_names_are_refused_before_reaching_the_server(string $folder): void
    {
        [$stream, $reader] = $this->connectedReader();

        $this->expectException(UnknownFolder::class);

        try {
            $reader->search($folder, [], 1);
        } finally {
            $stream->assertNotWritten('LIST');
        }
    }

    public function test_hidden_folders_are_refused_without_asking_the_server(): void
    {
        [$stream, $reader] = $this->connectedReader();

        $this->expectException(UnknownFolder::class);

        try {
            $reader->search('Notes/Recovered Items', [], 10);
        } finally {
            $stream->assertNotWritten('LIST');
        }
    }

    public function test_a_hidden_folder_is_refused_when_the_server_resolves_it_from_another_spelling(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $stream->feed([
            '* LIST () "/" "Notes"',
            'TAG2 OK LIST completed',
        ]);

        $this->expectException(UnknownFolder::class);

        try {
            $reader->search('notes', [], 1);
        } finally {
            $stream->assertNotWritten('SELECT');
        }
    }

    public function test_status_covers_every_visible_folder(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $stream->feed([
            '* LIST (\\Noinferiors) "/" "INBOX"',
            '* LIST () "/" "Notes"',
            'TAG2 OK LIST completed',
            '* STATUS "INBOX" (MESSAGES 146 UNSEEN 1 RECENT 0 UIDNEXT 3670 UIDVALIDITY 1)',
            'TAG3 OK STATUS completed',
        ]);

        $this->assertSame(
            [['folder' => 'INBOX', 'messages' => 146, 'unread' => 1, 'uid_next' => 3670]],
            $reader->status(),
        );
        $stream->assertNotWritten('STATUS "Notes"');
    }

    public function test_quota_is_flattened_per_resource(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $stream->feed([
            '* LIST (\\Noinferiors) "/" "INBOX"',
            'TAG2 OK LIST completed',
            '* CAPABILITY IMAP4rev1 QUOTA',
            'TAG3 OK CAPABILITY completed',
            '* QUOTAROOT "INBOX" "root"',
            '* QUOTA "root" (STORAGE 57191 2147483648 MESSAGE 10 1000)',
            'TAG4 OK GETQUOTAROOT completed',
        ]);

        $this->assertSame([
            ['root' => 'root', 'resource' => 'STORAGE', 'usage' => 57191, 'limit' => 2147483648],
            ['root' => 'root', 'resource' => 'MESSAGE', 'usage' => 10, 'limit' => 1000],
        ], $reader->quota());
    }

    public function test_quota_is_unavailable_when_the_server_lacks_the_capability(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $stream->feed([
            '* LIST (\\Noinferiors) "/" "INBOX"',
            'TAG2 OK LIST completed',
            '* CAPABILITY IMAP4rev1',
            'TAG3 OK CAPABILITY completed',
        ]);

        $this->expectException(MailboxUnavailable::class);
        $this->expectExceptionMessage('This mail server does not report storage quotas.');

        $reader->quota();
    }

    public function test_an_unmatched_search_returns_no_messages(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $this->feedFolderSelection($stream);
        $stream->feed('TAG4 OK SEARCH completed');

        $this->assertSame([], $reader->search('INBOX', ['subject' => 'nothing matches'], 10));
    }

    public function test_an_unmatched_count_is_zero(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $this->feedFolderSelection($stream);
        $stream->feed('TAG4 OK SEARCH completed');

        $this->assertSame(0, $reader->count('INBOX', ['flagged_only' => true]));
        $stream->assertWritten('TAG4 UID SEARCH FLAGGED');
    }

    public function test_search_summaries_are_fetched_without_marking_mail_read(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $this->feedFolderSelection($stream);
        $stream->feed(['* SEARCH 41 42', 'TAG4 OK SEARCH completed']);
        $headers = $this->headers(['Subject: Invoice due', 'From: Billing <billing@example.com>']);
        $stream->feed([
            '* 2 FETCH (UID 42 FLAGS () RFC822.SIZE 900 BODY[HEADER] {'.strlen($headers).'}',
            $headers.')',
            'TAG5 OK FETCH completed',
        ]);

        $messages = $reader->search('INBOX', ['to' => 'example.com', 'since' => '2026-11-01', 'unread_only' => true], 1);

        $stream->assertWritten('TAG4 UID SEARCH TO "example.com" SINCE 01-Nov-2026 UNSEEN');
        $stream->assertWritten('BODY.PEEK[HEADER]');
        $stream->assertNotWritten('BODY[');
        $stream->assertNotWritten('STORE');
        $this->assertSame([[
            'uid' => 42,
            'date' => '2026-11-05T09:00:00+00:00',
            'from' => 'Billing <billing@example.com>',
            'to' => ['me@example.com'],
            'subject' => 'Invoice due',
            'size' => 900,
            'unread' => true,
            'flagged' => false,
            'answered' => false,
        ]], $messages);
    }

    public function test_an_html_only_message_is_converted_to_text_and_truncated(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $html = '<html><body><p>'.str_repeat('a', MailReader::MAX_BODY_CHARACTERS + 10).'</p></body></html>';
        $this->feedMessage($stream, 42, '("TEXT" "HTML" ("CHARSET" "utf-8") NIL NIL "7BIT" '.strlen($html).' 1 NIL NIL NIL NIL)');
        $stream->feed([
            '* 2 FETCH (UID 42 BODY[1] {'.strlen($html).'}',
            $html.')',
            'TAG6 OK FETCH completed',
        ]);

        $message = $reader->message('INBOX', 42);

        $stream->assertWritten('BODY.PEEK[1]');
        $this->assertSame(str_repeat('a', MailReader::MAX_BODY_CHARACTERS), $message['body']);
        $this->assertTrue($message['truncated']);
    }

    public function test_a_text_attachment_is_decoded_from_its_charset(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $this->feedMessage($stream, 42, '(("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 5 1 NIL NIL NIL NIL)'
            .'("TEXT" "PLAIN" ("CHARSET" "windows-1252" "NAME" "menu.txt") NIL NIL "BASE64" 8 1 NIL ("ATTACHMENT" ("FILENAME" "menu.txt")) NIL NIL)'
            .' "MIXED" ("BOUNDARY" "b") NIL NIL NIL)');
        $stream->feed([
            '* 2 FETCH (UID 42 BODY[2] {8}',
            base64_encode("caf\xE9").')',
            'TAG6 OK FETCH completed',
        ]);

        $attachment = $reader->attachment('INBOX', 42, 0);

        $stream->assertWritten('BODY.PEEK[2]');
        $this->assertSame('menu.txt', $attachment['filename']);
        $this->assertSame('café', $attachment['text']);
    }

    public function test_an_attachment_over_the_size_limit_is_refused_before_downloading(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $this->feedMessage($stream, 42, '(("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 5 1 NIL NIL NIL NIL)'
            .'("APPLICATION" "ZIP" ("NAME" "big.zip") NIL NIL "BASE64" 99000000 NIL ("ATTACHMENT" ("FILENAME" "big.zip")) NIL NIL)'
            .' "MIXED" ("BOUNDARY" "b") NIL NIL NIL)');

        $this->expectException(AttachmentTooLarge::class);

        try {
            $reader->attachment('INBOX', 42, 0);
        } finally {
            $stream->assertNotWritten('BODY.PEEK[2]');
        }
    }

    public function test_thread_search_matches_every_referenced_message_id(): void
    {
        [$stream, $reader] = $this->connectedReader();
        $this->feedMessage(
            $stream,
            42,
            '("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 5 1 NIL NIL NIL NIL)',
            ['Message-ID: <reply@example.com>', 'In-Reply-To: <original@example.com>', 'References: <original@example.com>'],
        );
        $stream->feed([
            '* LIST (\\Noinferiors) "/" "INBOX"',
            'TAG6 OK LIST completed',
            '* 2 EXISTS',
            'TAG7 OK [READ-WRITE] SELECT completed',
            'TAG8 OK SEARCH completed',
        ]);

        $this->assertSame([], $reader->thread('INBOX', 42));
        $stream->assertWritten('TAG8 UID SEARCH OR (OR (OR (OR (OR (HEADER MESSAGE-ID "<original@example.com>") (HEADER REFERENCES "<original@example.com>")) (HEADER IN-REPLY-TO "<original@example.com>")) (HEADER MESSAGE-ID "<reply@example.com>")) (HEADER REFERENCES "<reply@example.com>")) (HEADER IN-REPLY-TO "<reply@example.com>")');
    }

    public function test_a_rejected_login_is_reported_as_unavailable(): void
    {
        $stream = new FakeStream;
        $stream->open();
        $stream->feed(['* OK ready', 'TAG1 NO [AUTHENTICATIONFAILED] Authentication failed']);

        $this->expectException(MailboxUnavailable::class);
        $this->expectExceptionMessage('The mail server rejected the login.');

        (new MailReader($this->lazyMailbox($stream)))->folders();
    }

    public function test_an_unreachable_server_is_reported_as_unavailable(): void
    {
        $stream = new FakeStream;
        $stream->open();
        $stream->setMeta('eof', true);

        $this->expectException(MailboxUnavailable::class);
        $this->expectExceptionMessage('Could not reach the mail server.');

        (new MailReader($this->lazyMailbox($stream)))->folders();
    }

    /**
     * @return array{FakeStream, MailReader}
     */
    private function connectedReader(): array
    {
        $stream = new FakeStream;
        $stream->open();
        $stream->feed(['* OK [CAPABILITY IMAP4rev1] ready', 'TAG1 OK user logged in']);

        $mailbox = new Mailbox(['host' => 'imap.example.test', 'username' => 'user', 'password' => 'secret']);
        $mailbox->connect(new ImapConnection($stream));

        return [$stream, new MailReader($mailbox, ['Notes', 'Bills & Receipts'])];
    }

    private function lazyMailbox(FakeStream $stream): Mailbox
    {
        return new class(['host' => 'imap.example.test', 'username' => 'user', 'password' => 'secret'], $stream) extends Mailbox
        {
            public function __construct(array $config, private FakeStream $stream)
            {
                parent::__construct($config);
            }

            public function connect(?ConnectionInterface $connection = null): void
            {
                parent::connect($connection ?? new ImapConnection($this->stream));
            }
        };
    }

    private function feedFolderSelection(FakeStream $stream): void
    {
        $stream->feed([
            '* LIST (\\Noinferiors) "/" "INBOX"',
            'TAG2 OK LIST completed',
            '* 2 EXISTS',
            'TAG3 OK [READ-WRITE] SELECT completed',
        ]);
    }

    /**
     * @param  list<string>  $extraHeaders
     */
    private function feedMessage(FakeStream $stream, int $uid, string $bodyStructure, array $extraHeaders = []): void
    {
        $this->feedFolderSelection($stream);
        $headers = $this->headers($extraHeaders);
        $stream->feed([
            "* 2 FETCH (UID {$uid})",
            'TAG4 OK FETCH completed',
            "* 2 FETCH (UID {$uid} FLAGS () RFC822.SIZE 900 BODY[HEADER] {".strlen($headers).'}',
            "{$headers} BODYSTRUCTURE {$bodyStructure})",
            'TAG5 OK FETCH completed',
        ]);
    }

    /**
     * @param  list<string>  $overrides
     */
    private function headers(array $overrides = []): string
    {
        $headers = collect([
            'From' => 'Alice <alice@example.com>',
            'To' => 'me@example.com',
            'Subject' => 'Lunch',
            'Date' => 'Thu, 05 Nov 2026 09:00:00 +0000',
            'Message-ID' => '<abc@example.com>',
        ])->merge(collect($overrides)->mapWithKeys(function (string $line) {
            [$name, $value] = explode(': ', $line, 2);

            return [$name => $value];
        }));

        return $headers->map(fn (string $value, string $name) => "{$name}: {$value}")->implode("\r\n")."\r\n\r\n";
    }
}

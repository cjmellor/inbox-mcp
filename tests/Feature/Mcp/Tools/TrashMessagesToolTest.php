<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailWriter;
use App\Imap\UnknownFolder;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\TrashMessagesTool;
use Mockery\MockInterface;
use Tests\TestCase;

class TrashMessagesToolTest extends TestCase
{
    public function test_messages_are_moved_to_the_trash(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('trash')
            ->with('INBOX', [5, 6])
            ->andReturn(['moved' => 2, 'folder' => 'Deleted Messages', 'expunged' => true]));

        MailServer::tool(TrashMessagesTool::class, ['uids' => [5, 6]])
            ->assertOk()
            ->assertSee('"folder":"Deleted Messages"');
    }

    public function test_a_mailbox_without_a_trash_folder_is_an_error(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('trash')
            ->andThrow(new UnknownFolder('This mailbox has no Trash folder.')));

        MailServer::tool(TrashMessagesTool::class, ['uids' => [5]])
            ->assertHasErrors(['This mailbox has no Trash folder.']);
    }
}

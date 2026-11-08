<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\ListFoldersTool;
use Mockery\MockInterface;
use Tests\TestCase;

class ListFoldersToolTest extends TestCase
{
    public function test_folders_are_listed_with_their_flags(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('folders')
            ->andReturn([['name' => 'Sent Messages', 'flags' => ['\\Sent']]]));

        MailServer::tool(ListFoldersTool::class)
            ->assertOk()
            ->assertSee(['Sent Messages', '\\\\Sent']);
    }

    public function test_an_unavailable_mailbox_is_an_error(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('folders')
            ->andThrow(new MailboxUnavailable('Could not reach the mail server. Try again shortly.')));

        MailServer::tool(ListFoldersTool::class)
            ->assertHasErrors(['Could not reach the mail server. Try again shortly.']);
    }
}

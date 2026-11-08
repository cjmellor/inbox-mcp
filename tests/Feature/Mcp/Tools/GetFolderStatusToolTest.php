<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailReader;
use App\Imap\UnknownFolder;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\GetFolderStatusTool;
use Mockery\MockInterface;
use Tests\TestCase;

class GetFolderStatusToolTest extends TestCase
{
    public function test_every_folder_is_reported_when_none_is_named(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('status')
            ->with(null)
            ->andReturn([['folder' => 'INBOX', 'messages' => 146, 'unread' => 1, 'uid_next' => 3670]]));

        MailServer::tool(GetFolderStatusTool::class)
            ->assertOk()
            ->assertSee(['"messages":146', '"unread":1']);
    }

    public function test_an_unknown_folder_is_an_error(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('status')
            ->with('Notes')
            ->andThrow(UnknownFolder::named('Notes')));

        MailServer::tool(GetFolderStatusTool::class, ['folder' => 'Notes'])
            ->assertHasErrors(['Unknown folder [Notes]. Use list-folders to see the available folders.']);
    }
}

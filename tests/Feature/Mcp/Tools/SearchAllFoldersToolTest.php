<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailReader;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\SearchAllFoldersTool;
use Mockery\MockInterface;
use Tests\TestCase;

class SearchAllFoldersToolTest extends TestCase
{
    public function test_results_from_every_folder_are_fenced(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('searchAllFolders')
            ->with(['from' => 'billing@example.com'], 20)
            ->andReturn([['folder' => 'Archive', 'uid' => 3, 'subject' => 'Receipt']]));

        MailServer::tool(SearchAllFoldersTool::class, ['from' => 'billing@example.com'])
            ->assertOk()
            ->assertSee(['<untrusted-email-content-', '"folder": "Archive"', 'Receipt']);
    }
}

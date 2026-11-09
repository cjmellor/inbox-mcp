<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailWriter;
use App\Imap\OperationRefused;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\RenameFolderTool;
use Mockery\MockInterface;
use Tests\TestCase;

class RenameFolderToolTest extends TestCase
{
    public function test_a_folder_is_renamed(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('renameFolder')
            ->with('Receipts', 'Bills')
            ->andReturn('Bills'));

        MailServer::tool(RenameFolderTool::class, ['folder' => 'Receipts', 'name' => 'Bills'])
            ->assertOk()
            ->assertSee('"renamed":"Bills"');
    }

    public function test_a_special_folder_cannot_be_renamed(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('renameFolder')
            ->andThrow(new OperationRefused('The INBOX folder cannot be renamed.')));

        MailServer::tool(RenameFolderTool::class, ['folder' => 'INBOX', 'name' => 'Old'])
            ->assertHasErrors(['The INBOX folder cannot be renamed.']);
    }
}

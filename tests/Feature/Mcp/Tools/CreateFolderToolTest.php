<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailWriter;
use App\Imap\OperationRefused;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\CreateFolderTool;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateFolderToolTest extends TestCase
{
    public function test_a_folder_is_created(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('createFolder')
            ->with('Receipts/2026')
            ->andReturn('Receipts/2026'));

        MailServer::tool(CreateFolderTool::class, ['name' => 'Receipts/2026'])
            ->assertOk()
            ->assertSee('"created":"Receipts/2026"');
    }

    public function test_an_existing_folder_is_an_error(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('createFolder')
            ->andThrow(new OperationRefused('A folder named Archive already exists.')));

        MailServer::tool(CreateFolderTool::class, ['name' => 'Archive'])
            ->assertHasErrors(['A folder named Archive already exists.']);
    }
}

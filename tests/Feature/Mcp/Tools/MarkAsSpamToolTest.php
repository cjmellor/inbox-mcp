<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailWriter;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\MarkAsSpamTool;
use Mockery\MockInterface;
use Tests\TestCase;

class MarkAsSpamToolTest extends TestCase
{
    public function test_messages_are_moved_to_junk(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('junk')
            ->with('Archive', [5])
            ->andReturn(['moved' => 1, 'folder' => 'Junk', 'expunged' => true]));

        MailServer::tool(MarkAsSpamTool::class, ['folder' => 'Archive', 'uids' => [5]])
            ->assertOk()
            ->assertSee('"folder":"Junk"');
    }

    public function test_no_matching_messages_is_an_error(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('junk')
            ->andReturn(['moved' => 0, 'folder' => 'Junk', 'expunged' => true]));

        MailServer::tool(MarkAsSpamTool::class, ['uids' => [99]])
            ->assertHasErrors(['No matching messages.']);
    }
}

<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailWriter;
use App\Imap\OperationRefused;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\MoveMessagesTool;
use Mockery\MockInterface;
use Tests\TestCase;

class MoveMessagesToolTest extends TestCase
{
    public function test_messages_are_moved_from_the_inbox_by_default(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('move')
            ->with('INBOX', [5], 'Archive')
            ->andReturn(['moved' => 1, 'folder' => 'Archive', 'expunged' => true]));

        MailServer::tool(MoveMessagesTool::class, ['uids' => [5], 'destination' => 'Archive'])
            ->assertOk()
            ->assertSee('"moved":1')
            ->assertDontSee('note');
    }

    public function test_an_unexpunged_move_says_the_originals_remain_marked_deleted(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('move')
            ->andReturn(['moved' => 1, 'folder' => 'Archive', 'expunged' => false]));

        MailServer::tool(MoveMessagesTool::class, ['uids' => [5], 'destination' => 'Archive'])
            ->assertOk()
            ->assertSee('the originals are marked deleted in the source folder');
    }

    public function test_a_refused_move_is_an_error(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('move')
            ->andThrow(new OperationRefused('The messages are already in Archive.')));

        MailServer::tool(MoveMessagesTool::class, ['folder' => 'Archive', 'uids' => [5], 'destination' => 'Archive'])
            ->assertHasErrors(['The messages are already in Archive.']);
    }
}

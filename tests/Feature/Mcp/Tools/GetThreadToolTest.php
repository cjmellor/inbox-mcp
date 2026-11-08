<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailReader;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\GetThreadTool;
use Mockery\MockInterface;
use Tests\TestCase;

class GetThreadToolTest extends TestCase
{
    public function test_the_conversation_is_fenced(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('thread')
            ->with('INBOX', 42)
            ->andReturn([['folder' => 'Sent Messages', 'uid' => 8, 'subject' => 'Re: Lunch']]));

        MailServer::tool(GetThreadTool::class, ['uid' => 42])
            ->assertOk()
            ->assertSee(['<untrusted-email-content-', 'Re: Lunch']);
    }

    public function test_a_missing_message_is_an_error(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('thread')
            ->andReturnNull());

        MailServer::tool(GetThreadTool::class, ['uid' => 99])
            ->assertHasErrors(['No message with uid 99 in that folder.']);
    }
}

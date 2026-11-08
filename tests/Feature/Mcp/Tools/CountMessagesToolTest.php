<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailReader;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\CountMessagesTool;
use Mockery\MockInterface;
use Tests\TestCase;

class CountMessagesToolTest extends TestCase
{
    public function test_matching_messages_are_counted(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('count')
            ->with('INBOX', ['unread_only' => true])
            ->andReturn(1));

        MailServer::tool(CountMessagesTool::class, ['unread_only' => true])
            ->assertOk()
            ->assertSee('"count":1');
    }
}

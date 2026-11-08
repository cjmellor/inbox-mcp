<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailReader;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\GetMessageHeadersTool;
use Mockery\MockInterface;
use Tests\TestCase;

class GetMessageHeadersToolTest extends TestCase
{
    public function test_the_raw_headers_are_fenced(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('headers')
            ->with('INBOX', 42)
            ->andReturn(['headers' => 'Received: from mx.example.com', 'truncated' => false]));

        MailServer::tool(GetMessageHeadersTool::class, ['uid' => 42])
            ->assertOk()
            ->assertSee(['<untrusted-email-content-', 'Received: from mx.example.com']);
    }

    public function test_a_missing_message_is_an_error(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('headers')
            ->andReturnNull());

        MailServer::tool(GetMessageHeadersTool::class, ['uid' => 99])
            ->assertHasErrors(['No message with uid 99 in that folder.']);
    }
}

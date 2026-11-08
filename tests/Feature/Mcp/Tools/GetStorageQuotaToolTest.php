<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\GetStorageQuotaTool;
use Mockery\MockInterface;
use Tests\TestCase;

class GetStorageQuotaToolTest extends TestCase
{
    public function test_quota_usage_is_returned(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('quota')
            ->andReturn([['root' => 'root', 'resource' => 'STORAGE', 'usage' => 57191, 'limit' => 2147483648]]));

        MailServer::tool(GetStorageQuotaTool::class)
            ->assertOk()
            ->assertSee(['"usage":57191', '"limit":2147483648']);
    }

    public function test_a_server_without_quotas_is_an_error(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('quota')
            ->andThrow(new MailboxUnavailable('This mail server does not report storage quotas.')));

        MailServer::tool(GetStorageQuotaTool::class)
            ->assertHasErrors(['This mail server does not report storage quotas.']);
    }
}

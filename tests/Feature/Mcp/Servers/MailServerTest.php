<?php

namespace Tests\Feature\Mcp\Servers;

use Tests\TestCase;

class MailServerTest extends TestCase
{
    public function test_every_tool_is_registered_and_read_only(): void
    {
        config(['services.mcp.token_hashes' => ['claude-code' => hash('sha256', 'known-token')]]);

        $tools = $this->withToken('known-token')
            ->postJson('/mail', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
            ->assertOk()
            ->json('result.tools');

        $this->assertSame([
            'list-folders', 'get-folder-status', 'get-storage-quota',
            'search-messages', 'search-all-folders', 'count-messages',
            'get-message', 'get-message-headers', 'get-thread', 'get-attachment',
        ], array_column($tools, 'name'));

        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint'], "{$tool['name']} is not read-only");
        }
    }
}

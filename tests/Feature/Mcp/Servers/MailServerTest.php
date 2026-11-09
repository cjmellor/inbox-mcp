<?php

namespace Tests\Feature\Mcp\Servers;

use Tests\TestCase;

class MailServerTest extends TestCase
{
    public function test_reading_tools_are_read_only_and_writing_tools_are_not(): void
    {
        config(['services.mcp.token_hashes' => ['claude-code' => hash('sha256', 'known-token')]]);

        $tools = $this->withToken('known-token')
            ->postJson('/mail', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
            ->assertOk()
            ->json('result.tools');

        $reading = [
            'list-folders', 'get-folder-status', 'get-storage-quota',
            'search-messages', 'search-all-folders', 'count-messages',
            'get-message', 'get-message-headers', 'get-thread', 'get-attachment',
        ];
        $writing = [
            'update-message-flags', 'move-messages', 'trash-messages', 'mark-as-spam',
            'create-folder', 'rename-folder', 'create-draft', 'send-email',
        ];

        $this->assertSame([...$reading, ...$writing], array_column($tools, 'name'));

        foreach ($tools as $tool) {
            $this->assertSame(in_array($tool['name'], $reading, true), $tool['annotations']['readOnlyHint'] ?? false, "{$tool['name']} has the wrong readOnlyHint");
        }
    }
}

<?php

namespace Tests\Feature\Http\Middleware;

use Tests\TestCase;

class AuthenticateMcpTokenTest extends TestCase
{
    public function test_a_request_without_a_token_gets_a_401(): void
    {
        $this->postJson('/mail', $this->initialize())
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
    }

    public function test_a_request_with_an_unknown_token_gets_a_401(): void
    {
        $this->withTokens(['claude-code' => 'known-token']);

        $this->withToken('unknown-token')->postJson('/mail', $this->initialize())->assertUnauthorized();
    }

    public function test_a_known_token_reaches_the_server(): void
    {
        $this->withTokens(['claude-code' => 'known-token']);

        $this->withToken('known-token')
            ->postJson('/mail', $this->initialize(), ['Accept' => 'application/json, text/event-stream'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'inbox');
    }

    public function test_each_token_has_its_own_rate_limit(): void
    {
        $this->withTokens(['claude-code' => 'first-token', 'amp' => 'second-token']);

        foreach (range(1, 60) as $attempt) {
            $this->withToken('first-token')->postJson('/mail', $this->initialize())->assertOk();
        }

        $this->withToken('first-token')->postJson('/mail', $this->initialize())->assertTooManyRequests();
        $this->withToken('second-token')->postJson('/mail', $this->initialize())->assertOk();
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function withTokens(array $tokens): void
    {
        config(['services.mcp.token_hashes' => array_map(fn (string $token) => hash('sha256', $token), $tokens)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ];
    }
}

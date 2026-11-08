<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('mcp:token {client : A short name for the client, e.g. claude-code}', function (string $client) {
    $token = Str::random(48);

    $this->line("Token for the client (shown once):\n  {$token}");
    $this->line("\nAppend this entry to MCP_TOKEN_HASHES in .env (comma-separated):\n  {$client}:".hash('sha256', $token));
})->purpose('Generate a bearer token for an MCP client');

<?php

return [

    'imap' => [
        'host' => env('IMAP_HOST'),
        'port' => (int) env('IMAP_PORT', 993),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
        'username' => env('IMAP_USERNAME'),
        'password' => env('IMAP_PASSWORD'),
        'timeout' => 20,
    ],

    'mcp' => [
        'hidden_folders' => str(env('MCP_HIDDEN_FOLDERS'))
            ->explode(',')
            ->map(fn (string $folder) => trim($folder))
            ->filter()
            ->values()
            ->all(),

        'token_hashes' => str(env('MCP_TOKEN_HASHES'))
            ->explode(',')
            ->filter(fn (string $pair) => str_contains($pair, ':'))
            ->mapWithKeys(function (string $pair) {
                [$client, $hash] = explode(':', trim($pair), 2);

                return [$client => $hash];
            })
            ->all(),
    ],

];

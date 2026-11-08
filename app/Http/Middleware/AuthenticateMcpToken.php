<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $this->clientFor($request->bearerToken());

        if ($client === null) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        Log::info('MCP request', ['client' => $client, 'method' => $request->json('method'), 'tool' => $request->json('params.name')]);

        return $next($request);
    }

    private function clientFor(?string $token): ?string
    {
        if (blank($token)) {
            return null;
        }

        $hash = hash('sha256', $token);

        foreach (config('services.mcp.token_hashes') as $client => $expected) {
            if (hash_equals($expected, $hash)) {
                return $client;
            }
        }

        return null;
    }
}

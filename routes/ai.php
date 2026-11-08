<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\MailServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mail', MailServer::class)
    ->middleware(['throttle:mcp', AuthenticateMcpToken::class]);

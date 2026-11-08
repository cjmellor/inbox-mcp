<?php

namespace App\Providers;

use App\Imap\MailReader;
use DirectoryTree\ImapEngine\Mailbox;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailReader::class, fn () => new MailReader(
            new Mailbox(config('services.imap')),
            config('services.mcp.hidden_folders'),
        ));
    }

    public function boot(): void
    {
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(60)->by(
            $request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip(),
        ));
    }
}

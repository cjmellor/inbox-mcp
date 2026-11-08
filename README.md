# inbox-mcp

A small, self-hosted [MCP](https://modelcontextprotocol.io) server that gives AI agents **read-only** access to an email mailbox over IMAP. Built with Laravel 13, [laravel/mcp](https://github.com/laravel/mcp) and [ImapEngine](https://github.com/DirectoryTree/ImapEngine).

Run it on your own server, give each agent its own token, and they can search and read your mail. They can't send, move, delete or flag it.

Tested against iCloud Mail, including iCloud+ custom email domains. It should work with any IMAP server that accepts a username and password over TLS.

## If you're an agent setting this up

Read this before following the steps below. They are written for people, so the commands are examples, not a script to run as-is.

- **Match the user's existing setup.** Before changing anything, check:
  - which web server already serves sites on the machine (Caddy, nginx, Apache, or a panel like Forge or Ploi)
  - the PHP version and the PHP-FPM socket in use
  - where the user keeps their apps
  - how they deploy their other apps

  Follow what you find. The examples below use Caddy and placeholder paths like `/srv/inbox-mcp` and `mcp.example.com`; swap in the user's real values.
- **Keep secrets out of the conversation.** Never ask the user to paste their IMAP password into the chat, and never print `.env`. Have them type the password into `.env` themselves, or into a hidden prompt such as `read -s`.
- **Tokens are shown once.** `php artisan mcp:token <client>` prints a token and a hash. Put the token straight into the client config the user asked for, or give it to them; only the hash goes in `.env`.
- **Ask first** before you:
  - edit a web server config that other sites share
  - restart a service that other sites depend on
  - create DNS records
- **Verify at the end** with the checks in [Deploy](#deploy-to-a-server), step 5, and report what they returned.

## Tools

| Tool | What it does |
|---|---|
| `list-folders` | Lists the mailbox folders with their IMAP flags (`\Sent`, `\Trash`, …). |
| `get-folder-status` | Total and unread counts for one folder or for all of them. |
| `get-storage-quota` | Storage used and the limit, if the server reports it. |
| `search-messages` | Searches one folder. Returns up to 50 summaries per page, newest first. Each summary has uid, date, from, to, subject, size, and the unread, flagged and answered flags. |
| `search-all-folders` | Runs the same search across every folder, and labels each result with its folder. |
| `count-messages` | Counts the messages that match a search, without fetching them. |
| `get-message` | Reads one message: sender, recipients, subject, Message-ID, a plain-text body (HTML is converted to text) and a numbered list of attachments. Bodies are cut off at 20,000 characters. |
| `get-message-headers` | Every raw header, e.g. `Received` or `Authentication-Results`, useful for checking SPF, DKIM and DMARC. |
| `get-thread` | Every message in the same conversation, across all folders and including your sent replies, oldest first. |
| `get-attachment` | Reads one attachment. Text-based files come back as text and images as images, up to 5 MB. PDFs and other binary files aren't supported. |

All three search tools take the same filters:
- `from`, `to`, `cc`, `bcc`, `subject`, `body`, `text`
- `since`, `before`
- `larger_than`, `smaller_than`
- `unread_only`, `flagged_only`, `answered_only`

Every tool is marked read-only (`readOnlyHint`) for clients that check.

## How it keeps your mailbox safe

- **No write path.** No code sends mail or changes, moves or deletes messages. Messages are fetched with `BODY.PEEK`, so reading one never marks it as read.
- **Email is treated as untrusted.** Every result is wrapped in randomly named markers and a warning that the content was written by email senders, not by you. Prompt injection through email is a real risk: anyone who can send you mail can write text your agent will read. Avoid giving the same agent a tool that can send data out (web requests, sending email) unless you trust what it reads.
- **Attachments are fetched only on request.** `get-message` lists them by name, type and size. Only `get-attachment` downloads one, and only text-based files and images up to 5 MB.
- **One bearer token per client.** Only SHA-256 hashes are stored, so you can revoke one client without touching the others.
- **Rate limited** to 60 requests a minute.
- **Hidden folders.** Folders you list in `MCP_HIDDEN_FOLDERS`, and everything under them, are invisible to the tools.

## Requirements

- PHP 8.4 or newer with `openssl`, `mbstring`, `iconv` and `dom`. The `imap` extension is **not** needed.
- Composer
- A web server that can run PHP-FPM, with HTTPS (Caddy, nginx, …)
- An IMAP account and a password for it, usually an app-specific password

## Install locally

```bash
git clone https://github.com/cjmellor/inbox-mcp.git
cd inbox-mcp
composer install
cp .env.example .env
php artisan key:generate
```

Fill in the IMAP settings in `.env` (see [Configuration](#configuration)), then create a token for your first client:

```bash
php artisan mcp:token claude-code
```

The command prints the token once, plus a `claude-code:<hash>` entry. Add that entry to `MCP_TOKEN_HASHES` in `.env`. Separate multiple clients with commas:

```dotenv
MCP_TOKEN_HASHES=claude-code:3f1c…,amp:9ab2…
```

Start the app, then open the MCP Inspector to try the tools. Both commands keep running until you stop them, so run them in separate terminals or in the background:

```bash
php artisan serve
php artisan mcp:inspector /mail
```

Run the tests:

```bash
composer test
```

## Configuration

| Variable | Default | Notes |
|---|---|---|
| `IMAP_HOST` | – | e.g. `imap.mail.me.com` |
| `IMAP_PORT` | `993` | |
| `IMAP_ENCRYPTION` | `ssl` | `ssl`, `starttls` or `tls` |
| `IMAP_USERNAME` | – | Check your provider's rules; see iCloud below |
| `IMAP_PASSWORD` | – | Use an app-specific password where your provider offers one |
| `MCP_HIDDEN_FOLDERS` | – | Comma-separated folders to hide, along with their subfolders, e.g. `Notes` |
| `MCP_TOKEN_HASHES` | – | Comma-separated `client:sha256` pairs from `php artisan mcp:token` |
| `CACHE_STORE` | `file` | Used by the rate limiter. Leave it as `file` unless you have Redis. |

### iCloud Mail

```dotenv
IMAP_HOST=imap.mail.me.com
IMAP_USERNAME=yourname          # the part of your @icloud.com address before the @
IMAP_PASSWORD=xxxx-xxxx-xxxx-xxxx
MCP_HIDDEN_FOLDERS=Notes
```

- **Username:** use the part of your **@icloud.com** address before the @, even if you mostly use a custom domain. The custom-domain address doesn't work as an IMAP username.
- **Password:** create an app-specific password at [account.apple.com](https://account.apple.com) → Sign-In and Security → App-Specific Passwords. This needs two-factor authentication. Changing your Apple Account password revokes every app-specific password; when that happens, the tools report a rejected login.
- **Custom domains:** mail for every address on the account lands in one mailbox. To narrow a search to a custom domain, pass the domain as `to`, e.g. `to: "example.com"`.
- **Notes:** iCloud stores Notes in IMAP folders. Hide them with `MCP_HIDDEN_FOLDERS=Notes`.
- **Empty searches:** iCloud leaves out the `* SEARCH` line when a search finds nothing, which ImapEngine treats as an error. This app handles it and returns no results.

## Deploy to a server

Use whatever web server and deploy method you already have. The app is a standard Laravel app, so it only needs two things: the document root pointed at `public/`, and PHP requests passed to PHP-FPM 8.4 over HTTPS. The example below uses Caddy.

1. **DNS:** point a hostname, e.g. `mcp.example.com`, at the server.

2. **Code:** on the server, as the user PHP-FPM runs as. Put it wherever you keep your other apps; `/srv/inbox-mcp` is just an example:

   ```bash
   git clone https://github.com/cjmellor/inbox-mcp.git /srv/inbox-mcp
   cd /srv/inbox-mcp
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   php artisan key:generate
   chmod 600 .env
   ```

   Then edit `.env`. Set `APP_ENV=production`, `APP_DEBUG=false` and `APP_URL=https://mcp.example.com`, along with the IMAP settings and token hashes. Create tokens with `php artisan mcp:token <client>` on the server or on your own machine; only the hashes go in `.env`.

3. **Caches:**

   ```bash
   php artisan config:cache
   php artisan route:cache
   ```

   Run these again whenever you change `.env` or deploy new code.

4. **Web server:** add a site to the web server that's already running, with the document root at `public/`. For Caddy:

   ```caddyfile
   mcp.example.com {
   	root * /srv/inbox-mcp/public
   	encode gzip zstd
   	php_fastcgi unix//run/php/php8.4-fpm.sock
   	file_server
   }
   ```

   Change the socket path to match your PHP-FPM pool. For nginx, start from [Laravel's nginx config](https://laravel.com/docs/deployment#nginx). For Apache or a hosting panel, set it up the way you would any other Laravel app.

5. **Check it:**

   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://mcp.example.com/up
   curl -s -o /dev/null -w '%{http_code}\n' -X POST https://mcp.example.com/mail
   ```

   Expect `200` from `/up` and `401` from `/mail`. A `502` or `404` means the web server isn't reaching PHP-FPM or `public/`. A `500` usually means a missing `APP_KEY` or a `storage/` folder PHP can't write to; check `storage/logs`.

The server is plain request/response: no long-lived connections, queues or cron jobs.

## Connect your agents

The endpoint is `https://<your-host>/mail`. Each client sends its token as `Authorization: Bearer <token>`. Create a separate token for each client with `php artisan mcp:token <client>`, and set up only the clients you actually use.

**Claude Code**

```bash
claude mcp add --transport http inbox https://mcp.example.com/mail \
  --header "Authorization: Bearer <token>"
```

**Amp:** add it to `amp.mcpServers` in your Amp settings ([docs](https://ampcode.com/manual/mcp.md)):

```json
{
  "amp.mcpServers": {
    "inbox": {
      "url": "https://mcp.example.com/mail",
      "headers": { "Authorization": "Bearer <token>" }
    }
  }
}
```

**xAI (Grok) API:** pass it as a remote MCP tool, with the token in `authorization` ([docs](https://docs.x.ai/developers/tools/remote-mcp)):

```json
{ "type": "mcp", "server_url": "https://mcp.example.com/mail", "authorization": "<token>" }
```

**Other clients:** anything that can call a remote MCP server over HTTP with a custom `Authorization` header.

> claude.ai, Claude Desktop and the Claude mobile apps currently need OAuth to add a custom connector on personal plans. This server only supports bearer tokens, so they can't connect yet.

## Revoking a client

Remove its `client:hash` entry from `MCP_TOKEN_HASHES`, then run `php artisan config:cache`. Its next request gets a `401`.

## Contributing

Pull requests are welcome.

1. Install the dependencies: `composer install`
2. Set up your coding agent with `php artisan boost:install`. This installs [Laravel Boost](https://laravel.com/docs/boost)'s guidelines, skills and MCP server for whichever agents you pick. The files it generates are gitignored, so nobody else gets your agent setup.
3. Run `composer test` before opening a PR.

Project conventions belong in `.ai/guidelines/`, and Boost merges them into every agent's guideline file. Project rules belong in `.ai/rules/`; record them with Boost's `record-rule` tool so they're added to the index agents read. Both folders are committed.

## Licence

MIT

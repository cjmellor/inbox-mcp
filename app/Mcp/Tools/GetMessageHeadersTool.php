<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Imap\UnknownFolder;
use App\Mcp\UntrustedContent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-message-headers')]
#[Description('Get every raw header of one message, e.g. Received, Authentication-Results, List-Unsubscribe or X-headers. Use it to check where a message came from or whether it passed SPF, DKIM and DMARC.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMessageHeadersTool extends Tool
{
    public function handle(Request $request, MailReader $reader): Response
    {
        $validated = $request->validate([
            'folder' => ['sometimes', 'string', 'max:255'],
            'uid' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $headers = $reader->headers($validated['folder'] ?? 'INBOX', (int) $validated['uid']);
        } catch (MailboxUnavailable|UnknownFolder $e) {
            return Response::error($e->getMessage());
        }

        if ($headers === null) {
            return Response::error("No message with uid {$validated['uid']} in that folder.");
        }

        return UntrustedContent::response($headers);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('Folder the message is in. Defaults to INBOX.'),
            'uid' => $schema->integer()->min(1)->description('Message uid from a search.')->required(),
        ];
    }
}

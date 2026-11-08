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

#[Name('get-thread')]
#[Description('Find every message in the same conversation as one message, across all folders (including your sent replies), oldest first. Matches on Message-ID, In-Reply-To and References headers. Returns summaries; use get-message to read each one.')]
#[IsReadOnly]
#[IsIdempotent]
class GetThreadTool extends Tool
{
    public function handle(Request $request, MailReader $reader): Response
    {
        $validated = $request->validate([
            'folder' => ['sometimes', 'string', 'max:255'],
            'uid' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $thread = $reader->thread($validated['folder'] ?? 'INBOX', (int) $validated['uid']);
        } catch (MailboxUnavailable|UnknownFolder $e) {
            return Response::error($e->getMessage());
        }

        if ($thread === null) {
            return Response::error("No message with uid {$validated['uid']} in that folder.");
        }

        return UntrustedContent::response($thread);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('Folder the starting message is in. Defaults to INBOX.'),
            'uid' => $schema->integer()->min(1)->description('Uid of any message in the conversation.')->required(),
        ];
    }
}

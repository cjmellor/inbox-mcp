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

#[Name('get-message')]
#[Description('Read one message by uid: sender, recipients, subject, Message-ID, a plain-text body (HTML is converted to text) and a numbered list of attachments for get-attachment. Long bodies are truncated. Reading does not mark mail as read.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMessageTool extends Tool
{
    public function handle(Request $request, MailReader $reader): Response
    {
        $validated = $request->validate([
            'folder' => ['sometimes', 'string', 'max:255'],
            'uid' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $message = $reader->message($validated['folder'] ?? 'INBOX', (int) $validated['uid']);
        } catch (MailboxUnavailable|UnknownFolder $e) {
            return Response::error($e->getMessage());
        }

        if ($message === null) {
            return Response::error("No message with uid {$validated['uid']} in that folder.");
        }

        return UntrustedContent::response($message);
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

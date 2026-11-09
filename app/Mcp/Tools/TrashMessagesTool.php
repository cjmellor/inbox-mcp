<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailWriter;
use App\Imap\OperationRefused;
use App\Imap\UnknownFolder;
use App\Mcp\Tools\Concerns\TargetsMessages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('trash-messages')]
#[Description('Move messages to the Trash folder. Nothing is permanently deleted; use move-messages to restore them.')]
class TrashMessagesTool extends Tool
{
    use TargetsMessages;

    public function handle(Request $request, MailWriter $writer): Response
    {
        $validated = $request->validate($this->targetRules());

        try {
            return $this->moveResponse($writer->trash($validated['folder'] ?? 'INBOX', $this->uids($validated)));
        } catch (MailboxUnavailable|UnknownFolder|OperationRefused $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->targetSchema($schema);
    }
}

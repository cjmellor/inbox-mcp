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

#[Name('move-messages')]
#[Description('Move messages to another folder. This is also how to restore mail from the Trash or Junk folder. Moved messages get new uids in the destination.')]
class MoveMessagesTool extends Tool
{
    use TargetsMessages;

    public function handle(Request $request, MailWriter $writer): Response
    {
        $validated = $request->validate([
            ...$this->targetRules(),
            'destination' => ['required', 'string', 'max:255'],
        ]);

        try {
            return $this->moveResponse($writer->move($validated['folder'] ?? 'INBOX', $this->uids($validated), $validated['destination']));
        } catch (MailboxUnavailable|UnknownFolder|OperationRefused $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->targetSchema($schema),
            'destination' => $schema->string()->description('Folder to move the messages to, from list-folders.')->required(),
        ];
    }
}

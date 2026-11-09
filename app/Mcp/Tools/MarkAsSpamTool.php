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

#[Name('mark-as-spam')]
#[Description('Move messages to the Junk folder. To mark mail as not spam, move it out of Junk with move-messages.')]
class MarkAsSpamTool extends Tool
{
    use TargetsMessages;

    public function handle(Request $request, MailWriter $writer): Response
    {
        $validated = $request->validate($this->targetRules());

        try {
            return $this->moveResponse($writer->junk($validated['folder'] ?? 'INBOX', $this->uids($validated)));
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

<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailWriter;
use App\Imap\UnknownFolder;
use App\Mcp\Tools\Concerns\TargetsMessages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update-message-flags')]
#[Description('Mark messages as read or unread, and flagged (starred) or unflagged.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateMessageFlagsTool extends Tool
{
    use TargetsMessages;

    public function handle(Request $request, MailWriter $writer): Response
    {
        $validated = $request->validate([
            ...$this->targetRules(),
            'read' => ['required_without:flagged', 'boolean'],
            'flagged' => ['required_without:read', 'boolean'],
        ]);

        try {
            $updated = $writer->setFlags(
                $validated['folder'] ?? 'INBOX',
                $this->uids($validated),
                read: isset($validated['read']) ? (bool) $validated['read'] : null,
                flagged: isset($validated['flagged']) ? (bool) $validated['flagged'] : null,
            );
        } catch (MailboxUnavailable|UnknownFolder $e) {
            return Response::error($e->getMessage());
        }

        if ($updated === 0) {
            return Response::error('No matching messages.');
        }

        return Response::json(['updated' => $updated]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->targetSchema($schema),
            'read' => $schema->boolean()->description('true marks as read, false as unread.'),
            'flagged' => $schema->boolean()->description('true flags, false unflags.'),
        ];
    }
}

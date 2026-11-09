<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailWriter;
use App\Imap\OperationRefused;
use App\Imap\UnknownFolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rename-folder')]
#[Description('Rename a mail folder. INBOX, Drafts, Sent, Trash and Junk cannot be renamed.')]
class RenameFolderTool extends Tool
{
    public function handle(Request $request, MailWriter $writer): Response
    {
        $validated = $request->validate([
            'folder' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            return Response::json(['renamed' => $writer->renameFolder($validated['folder'], $validated['name'])]);
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
            'folder' => $schema->string()->description('Current folder name, from list-folders.')->required(),
            'name' => $schema->string()->description('New folder name.')->required(),
        ];
    }
}

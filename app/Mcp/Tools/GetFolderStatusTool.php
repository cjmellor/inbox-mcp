<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Imap\UnknownFolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-folder-status')]
#[Description('Get the total and unread message counts for one folder, or for every folder if none is given.')]
#[IsReadOnly]
#[IsIdempotent]
class GetFolderStatusTool extends Tool
{
    public function handle(Request $request, MailReader $reader): Response
    {
        $validated = $request->validate([
            'folder' => ['sometimes', 'string', 'max:255'],
        ]);

        try {
            return Response::json($reader->status($validated['folder'] ?? null));
        } catch (MailboxUnavailable|UnknownFolder $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('Folder to check. Leave out for every folder.'),
        ];
    }
}

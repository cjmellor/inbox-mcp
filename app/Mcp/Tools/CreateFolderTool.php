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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create-folder')]
#[Description('Create a mail folder. Use "/" in the name to nest it inside an existing folder.')]
#[IsDestructive(false)]
class CreateFolderTool extends Tool
{
    public function handle(Request $request, MailWriter $writer): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            return Response::json(['created' => $writer->createFolder($validated['name'])]);
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
            'name' => $schema->string()->description('Name of the new folder.')->required(),
        ];
    }
}

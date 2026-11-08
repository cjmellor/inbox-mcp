<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-folders')]
#[Description('List the mail folders with their IMAP flags. Flags such as \\Sent, \\Trash and \\Junk mark special folders, whatever they are named.')]
#[IsReadOnly]
#[IsIdempotent]
class ListFoldersTool extends Tool
{
    public function handle(Request $request, MailReader $reader): Response
    {
        try {
            return Response::json($reader->folders());
        } catch (MailboxUnavailable $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

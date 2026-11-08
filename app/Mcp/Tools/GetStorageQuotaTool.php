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

#[Name('get-storage-quota')]
#[Description('Get the mailbox storage used and the limit. STORAGE values are in kilobytes (KiB), as IMAP reports them.')]
#[IsReadOnly]
#[IsIdempotent]
class GetStorageQuotaTool extends Tool
{
    public function handle(Request $request, MailReader $reader): Response
    {
        try {
            return Response::json($reader->quota());
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

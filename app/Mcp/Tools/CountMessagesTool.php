<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Imap\UnknownFolder;
use App\Mcp\Tools\Concerns\FiltersMessages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('count-messages')]
#[Description('Count the messages in a folder that match the filters, without fetching them. Takes the same filters as search-messages.')]
#[IsReadOnly]
#[IsIdempotent]
class CountMessagesTool extends Tool
{
    use FiltersMessages;

    public function handle(Request $request, MailReader $reader): Response
    {
        $validated = $request->validate([
            'folder' => ['sometimes', 'string', 'max:255'],
            ...$this->filterRules(),
        ]);

        try {
            $count = $reader->count($validated['folder'] ?? 'INBOX', $this->filters($validated));
        } catch (MailboxUnavailable|UnknownFolder $e) {
            return Response::error($e->getMessage());
        }

        return Response::json(['count' => $count]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('Folder to count in. Defaults to INBOX.'),
            ...$this->filterSchema($schema),
        ];
    }
}

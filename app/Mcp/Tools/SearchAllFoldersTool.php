<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Mcp\Tools\Concerns\FiltersMessages;
use App\Mcp\UntrustedContent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-all-folders')]
#[Description('Search every folder at once and return the newest matching message summaries, each with its folder. Takes the same filters as search-messages. Slower than searching one folder.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchAllFoldersTool extends Tool
{
    use FiltersMessages;

    public function handle(Request $request, MailReader $reader): Response
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            ...$this->filterRules(),
        ]);

        try {
            $messages = $reader->searchAllFolders($this->filters($validated), $validated['limit'] ?? 20);
        } catch (MailboxUnavailable $e) {
            return Response::error($e->getMessage());
        }

        return UntrustedContent::response($messages);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum results across all folders, 1 to 50. Defaults to 20.'),
            ...$this->filterSchema($schema),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Imap\UnknownFolder;
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

#[Name('search-messages')]
#[Description('Search one folder and return message summaries (uid, date, from, to, subject, size, unread, flagged, answered), newest first. All filters are combined with AND. Use page for more results, and get-message with a uid to read one. Searching does not mark mail as read.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchMessagesTool extends Tool
{
    use FiltersMessages;

    public function handle(Request $request, MailReader $reader): Response
    {
        $validated = $request->validate([
            'folder' => ['sometimes', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$this->filterRules(),
        ]);

        try {
            $messages = $reader->search(
                $validated['folder'] ?? 'INBOX',
                $this->filters($validated),
                $validated['limit'] ?? 20,
                $validated['page'] ?? 1,
            );
        } catch (MailboxUnavailable|UnknownFolder $e) {
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
            'folder' => $schema->string()->description('Folder to search. Defaults to INBOX.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Results per page, 1 to 50. Defaults to 20.'),
            'page' => $schema->integer()->min(1)->description('Page of results, starting at 1.'),
            ...$this->filterSchema($schema),
        ];
    }
}

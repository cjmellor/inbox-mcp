<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;

trait TargetsMessages
{
    /**
     * @return array<string, list<string>>
     */
    protected function targetRules(): array
    {
        return [
            'folder' => ['sometimes', 'string', 'max:255'],
            'uids' => ['required', 'array', 'min:1', 'max:50'],
            'uids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function targetSchema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('Folder the messages are in. Defaults to INBOX.'),
            'uids' => $schema->array()->items($schema->integer()->min(1))->min(1)->max(50)->description('Message uids from a search, up to 50.')->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<int>
     */
    protected function uids(array $validated): array
    {
        return array_values(array_unique(array_map('intval', $validated['uids'])));
    }

    /**
     * @param  array{moved: int, folder: string, expunged: bool}  $result
     */
    protected function moveResponse(array $result): Response
    {
        if ($result['moved'] === 0) {
            return Response::error('No matching messages.');
        }

        return Response::json([
            ...$result,
            ...($result['expunged'] ? [] : ['note' => 'Copied, and the originals are marked deleted in the source folder. They were not expunged because other messages there are also marked deleted.']),
        ]);
    }
}

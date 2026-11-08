<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;

trait FiltersMessages
{
    /**
     * @return array<string, list<string>>
     */
    protected function filterRules(): array
    {
        return [
            'from' => ['sometimes', 'string', 'max:255'],
            'to' => ['sometimes', 'string', 'max:255'],
            'cc' => ['sometimes', 'string', 'max:255'],
            'bcc' => ['sometimes', 'string', 'max:255'],
            'subject' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:255'],
            'text' => ['sometimes', 'string', 'max:255'],
            'since' => ['sometimes', 'date_format:Y-m-d'],
            'before' => ['sometimes', 'date_format:Y-m-d'],
            'larger_than' => ['sometimes', 'integer', 'min:0'],
            'smaller_than' => ['sometimes', 'integer', 'min:1'],
            'unread_only' => ['sometimes', 'boolean'],
            'flagged_only' => ['sometimes', 'boolean'],
            'answered_only' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function filters(array $validated): array
    {
        return Arr::only($validated, array_keys($this->filterRules()));
    }

    /**
     * @return array<string, Type>
     */
    protected function filterSchema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Match sender address or name.'),
            'to' => $schema->string()->description('Match recipient, e.g. "example.com" for mail sent to any address at that domain.'),
            'cc' => $schema->string()->description('Match a Cc recipient.'),
            'bcc' => $schema->string()->description('Match a Bcc recipient (only present on mail you sent).'),
            'subject' => $schema->string()->description('Match text in the subject.'),
            'body' => $schema->string()->description('Match text in the body only.'),
            'text' => $schema->string()->description('Match text anywhere in the headers or body.'),
            'since' => $schema->string()->description('Only messages received on or after this date (YYYY-MM-DD).'),
            'before' => $schema->string()->description('Only messages received before this date (YYYY-MM-DD).'),
            'larger_than' => $schema->integer()->min(0)->description('Only messages larger than this many bytes.'),
            'smaller_than' => $schema->integer()->min(1)->description('Only messages smaller than this many bytes.'),
            'unread_only' => $schema->boolean()->description('Only unread messages.'),
            'flagged_only' => $schema->boolean()->description('Only flagged (starred) messages.'),
            'answered_only' => $schema->boolean()->description('Only messages that have been replied to.'),
        ];
    }
}

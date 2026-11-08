<?php

namespace App\Mcp\Tools;

use App\Imap\AttachmentTooLarge;
use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Imap\UnknownFolder;
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

#[Name('get-attachment')]
#[Description('Read one attachment by its index from get-message. Text-based files (plain text, CSV, HTML, JSON, XML, calendar invites) come back as text and images as images, up to 5 MB. Other types, such as PDFs, are not supported.')]
#[IsReadOnly]
#[IsIdempotent]
class GetAttachmentTool extends Tool
{
    private const int MAX_TEXT_CHARACTERS = 100_000;

    private const array TEXT_TYPES = [
        'application/json',
        'application/xml',
        'application/ics',
        'application/x-yaml',
        'application/yaml',
        'message/rfc822',
    ];

    private const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function handle(Request $request, MailReader $reader): Response
    {
        $validated = $request->validate([
            'folder' => ['sometimes', 'string', 'max:255'],
            'uid' => ['required', 'integer', 'min:1'],
            'index' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $attachment = $reader->attachment($validated['folder'] ?? 'INBOX', (int) $validated['uid'], (int) $validated['index']);
        } catch (MailboxUnavailable|UnknownFolder|AttachmentTooLarge $e) {
            return Response::error($e->getMessage());
        }

        if ($attachment === null) {
            return Response::error('No attachment with that index on that message. Use get-message to list attachments.');
        }

        $type = strtolower($attachment['type']);

        if (in_array($type, self::IMAGE_TYPES, true)) {
            return Response::image($attachment['data'], $type);
        }

        if (str_starts_with($type, 'text/') || in_array($type, self::TEXT_TYPES, true)) {
            $text = str($attachment['text'] ?? mb_convert_encoding($attachment['data'], 'UTF-8', 'UTF-8'));

            return UntrustedContent::response([
                'filename' => $attachment['filename'],
                'type' => $type,
                'content' => $text->substr(0, self::MAX_TEXT_CHARACTERS)->toString(),
                'truncated' => $text->length() > self::MAX_TEXT_CHARACTERS,
            ]);
        }

        return Response::error("Attachments of type {$type} can't be read by this server. Only text-based files and PNG, JPEG, GIF and WebP images are supported.");
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('Folder the message is in. Defaults to INBOX.'),
            'uid' => $schema->integer()->min(1)->description('Message uid from a search.')->required(),
            'index' => $schema->integer()->min(0)->description('Attachment index from get-message.')->required(),
        ];
    }
}

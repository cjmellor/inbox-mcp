<?php

namespace App\Mcp\Tools\Concerns;

use App\Imap\MailReader;
use App\Imap\OperationRefused;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

trait ComposesMessages
{
    /**
     * @return array<string, list<string>>
     */
    protected function compositionRules(): array
    {
        return [
            'to' => ['required', 'array', 'min:1', 'max:20'],
            'to.*' => ['email'],
            'cc' => ['sometimes', 'array', 'max:20'],
            'cc.*' => ['email'],
            'bcc' => ['sometimes', 'array', 'max:20'],
            'bcc.*' => ['email'],
            'subject' => ['required_without:reply_to_uid', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:100000'],
            'reply_to_uid' => ['sometimes', 'integer', 'min:1'],
            'reply_to_folder' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function compositionSchema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->array()->items($schema->string())->min(1)->max(20)->description('Recipient email addresses.')->required(),
            'cc' => $schema->array()->items($schema->string())->max(20)->description('Cc email addresses.'),
            'bcc' => $schema->array()->items($schema->string())->max(20)->description('Bcc email addresses.'),
            'subject' => $schema->string()->description('Subject. Optional for a reply, which defaults to "Re: " and the original subject.'),
            'body' => $schema->string()->description('Plain-text body.')->required(),
            'reply_to_uid' => $schema->integer()->min(1)->description('Uid of the message being replied to, so the reply joins its thread.'),
            'reply_to_folder' => $schema->string()->description('Folder of the message being replied to. Defaults to INBOX.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function compose(Email $email, array $validated, MailReader $reader): Email
    {
        $subject = $validated['subject'] ?? null;

        if (isset($validated['reply_to_uid'])) {
            $original = $reader->replyHeaders($validated['reply_to_folder'] ?? 'INBOX', (int) $validated['reply_to_uid']);

            if ($original === null) {
                throw new OperationRefused("No message with uid {$validated['reply_to_uid']} to reply to in that folder.");
            }

            $subject ??= Str::startsWith(Str::lower($original['subject'] ?? ''), 're:')
                ? $original['subject']
                : 'Re: '.($original['subject'] ?? '');

            if ($original['message_id'] !== null) {
                $email->getHeaders()->addIdHeader('In-Reply-To', $original['message_id']);
                $email->getHeaders()->addIdHeader('References', $original['references']);
            }
        }

        return $email
            ->to(...$validated['to'])
            ->cc(...($validated['cc'] ?? []))
            ->bcc(...($validated['bcc'] ?? []))
            ->subject($subject)
            ->text($validated['body']);
    }
}

<?php

namespace App\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Imap\MailWriter;
use App\Imap\OperationRefused;
use App\Imap\UnknownFolder;
use App\Mcp\Tools\Concerns\ComposesMessages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[Name('create-draft')]
#[Description('Save a plain-text email to the Drafts folder without sending it. Give reply_to_uid to draft a reply in the original\'s thread. Read drafts with search-messages and get-message on the Drafts folder.')]
#[IsDestructive(false)]
class CreateDraftTool extends Tool
{
    use ComposesMessages;

    public function handle(Request $request, MailReader $reader, MailWriter $writer): Response
    {
        $validated = $request->validate($this->compositionRules());

        try {
            $email = $this->compose(
                new Email()->from(new Address(config('mail.from.address'), config('mail.from.name') ?? '')),
                $validated,
                $reader,
            );

            // Symfony strips Bcc when serialising, so it is written back for the draft to keep it.
            $bcc = collect($email->getBcc())->map(fn (Address $address) => $address->toString())->implode(', ');
            $raw = ($bcc === '' ? '' : "Bcc: {$bcc}\r\n").$email->toString();

            return Response::json(['folder' => 'Drafts', 'uid' => $writer->saveDraft($raw)]);
        } catch (MailboxUnavailable|UnknownFolder|OperationRefused $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->compositionSchema($schema);
    }
}

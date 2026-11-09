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
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

#[Name('send-email')]
#[Description('Send a plain-text email immediately and save a copy to the Sent folder. Give reply_to_uid to reply in the original\'s thread and mark it as answered. Confirm the recipients and text with the user before calling this.')]
#[IsOpenWorld]
class SendEmailTool extends Tool
{
    use ComposesMessages;

    public function handle(Request $request, MailReader $reader, MailWriter $writer): Response
    {
        $validated = $request->validate($this->compositionRules());

        try {
            $sent = Mail::raw($validated['body'], fn (Message $message) => $this->compose($message->getSymfonyMessage(), $validated, $reader));
        } catch (MailboxUnavailable|UnknownFolder|OperationRefused $e) {
            return Response::error($e->getMessage());
        } catch (TransportExceptionInterface) {
            return Response::error('The mail server did not accept the message, so it was not sent.');
        }

        if ($sent === null) {
            return Response::error('The mailer is not configured to deliver mail, so it was not sent.');
        }

        try {
            $writer->saveSent($sent->toString());

            if (isset($validated['reply_to_uid'])) {
                $writer->markAnswered($validated['reply_to_folder'] ?? 'INBOX', (int) $validated['reply_to_uid']);
            }
        } catch (MailboxUnavailable|UnknownFolder) {
            return Response::json(['sent' => true, 'note' => 'Sent, but the copy could not be saved to the Sent folder.']);
        }

        return Response::json(['sent' => true, 'message_id' => $sent->getMessageId()]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->compositionSchema($schema);
    }
}

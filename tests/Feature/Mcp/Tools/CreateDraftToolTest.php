<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailReader;
use App\Imap\MailWriter;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\CreateDraftTool;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateDraftToolTest extends TestCase
{
    public function test_a_draft_keeps_its_bcc_recipients(): void
    {
        config(['mail.from' => ['address' => 'me@example.com', 'name' => 'Me']]);

        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('saveDraft')
            ->withArgs(fn (string $raw) => str_starts_with($raw, "Bcc: secret@example.com\r\n")
                && str_contains($raw, 'From: Me <me@example.com>')
                && str_contains($raw, 'Subject: Lunch'))
            ->andReturn(77));

        MailServer::tool(CreateDraftTool::class, [
            'to' => ['friend@example.com'],
            'bcc' => ['secret@example.com'],
            'subject' => 'Lunch',
            'body' => 'Friday?',
        ])
            ->assertOk()
            ->assertSee('"uid":77');
    }

    public function test_a_reply_draft_joins_the_original_thread(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('replyHeaders')
            ->with('INBOX', 42)
            ->andReturn(['subject' => 'Lunch', 'message_id' => 'b@example.com', 'references' => ['a@example.com', 'b@example.com']]));

        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('saveDraft')
            ->withArgs(fn (string $raw) => str_contains($raw, 'Subject: Re: Lunch')
                && str_contains($raw, 'In-Reply-To: <b@example.com>')
                && str_contains($raw, 'References: <a@example.com> <b@example.com>'))
            ->andReturn(78));

        MailServer::tool(CreateDraftTool::class, ['to' => ['friend@example.com'], 'body' => 'Yes', 'reply_to_uid' => 42])
            ->assertOk();
    }

    public function test_replying_to_a_missing_message_is_an_error(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock->expects('replyHeaders')->andReturnNull());
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock->shouldNotReceive('saveDraft'));

        MailServer::tool(CreateDraftTool::class, ['to' => ['friend@example.com'], 'body' => 'Yes', 'reply_to_uid' => 42])
            ->assertHasErrors(['No message with uid 42 to reply to in that folder.']);
    }

    public function test_recipients_must_be_email_addresses(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock->shouldNotReceive('saveDraft'));

        MailServer::tool(CreateDraftTool::class, ['to' => ['not an address'], 'subject' => 'Hi', 'body' => 'Hi'])
            ->assertHasErrors(['The to.0 field must be a valid email address.']);
    }
}

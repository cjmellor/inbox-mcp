<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailboxUnavailable;
use App\Imap\MailReader;
use App\Imap\MailWriter;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\SendEmailTool;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SendEmailToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array', 'mail.from' => ['address' => 'me@example.com', 'name' => 'Me']]);
    }

    public function test_a_sent_email_is_copied_to_sent(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('saveSent')
            ->withArgs(fn (string $raw) => str_contains($raw, 'Subject: Lunch') && str_contains($raw, 'To: friend@example.com'))
            ->andReturn(12));

        MailServer::tool(SendEmailTool::class, ['to' => ['friend@example.com'], 'subject' => 'Lunch', 'body' => 'Friday?'])
            ->assertOk()
            ->assertSee('"sent":true');

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $sent);
        $this->assertSame('Friday?', $sent->getTextBody());
    }

    public function test_a_reply_marks_the_original_as_answered(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('replyHeaders')
            ->andReturn(['subject' => 'Lunch', 'message_id' => 'b@example.com', 'references' => ['b@example.com']]));

        $this->mock(MailWriter::class, function (MockInterface $mock) {
            $mock->expects('saveSent')->andReturn(12);
            $mock->expects('markAnswered')->with('Archive', 42);
        });

        MailServer::tool(SendEmailTool::class, ['to' => ['friend@example.com'], 'body' => 'Yes', 'reply_to_uid' => 42, 'reply_to_folder' => 'Archive'])
            ->assertOk();

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('Re: Lunch', $sent->getSubject());
    }

    public function test_a_failed_sent_copy_still_reports_the_email_as_sent(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('saveSent')
            ->andThrow(new MailboxUnavailable('Could not reach the mail server. Try again shortly.')));

        MailServer::tool(SendEmailTool::class, ['to' => ['friend@example.com'], 'subject' => 'Lunch', 'body' => 'Friday?'])
            ->assertOk()
            ->assertSee('the copy could not be saved to the Sent folder');
    }
}

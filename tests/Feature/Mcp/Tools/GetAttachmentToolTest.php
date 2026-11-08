<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\AttachmentTooLarge;
use App\Imap\MailReader;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\GetAttachmentTool;
use Mockery\MockInterface;
use Tests\TestCase;

class GetAttachmentToolTest extends TestCase
{
    public function test_text_attachments_are_returned_as_fenced_text(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('attachment')
            ->with('INBOX', 5, 0)
            ->andReturn(['filename' => 'menu.txt', 'type' => 'text/plain', 'data' => "caf\xE9", 'text' => 'café']));

        MailServer::tool(GetAttachmentTool::class, ['uid' => 5, 'index' => 0])
            ->assertOk()
            ->assertSee(['<untrusted-email-content-', 'menu.txt', 'café']);
    }

    public function test_images_are_returned_as_images(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('attachment')
            ->andReturn(['filename' => 'photo.png', 'type' => 'image/PNG', 'data' => "\x89PNG", 'text' => null]));

        MailServer::tool(GetAttachmentTool::class, ['uid' => 5, 'index' => 0])
            ->assertOk()
            ->assertSee(base64_encode("\x89PNG"));
    }

    public function test_an_oversized_attachment_is_an_error(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('attachment')
            ->andThrow(new AttachmentTooLarge('That attachment is over 5 MB.')));

        MailServer::tool(GetAttachmentTool::class, ['uid' => 5, 'index' => 0])
            ->assertHasErrors(['That attachment is over 5 MB.']);
    }

    public function test_unsupported_attachment_types_are_an_error(): void
    {
        $this->mock(MailReader::class, fn (MockInterface $mock) => $mock
            ->expects('attachment')
            ->andReturn(['filename' => 'invoice.pdf', 'type' => 'application/pdf', 'data' => '%PDF', 'text' => null]));

        MailServer::tool(GetAttachmentTool::class, ['uid' => 5, 'index' => 0])
            ->assertHasErrors(["Attachments of type application/pdf can't be read by this server. Only text-based files and PNG, JPEG, GIF and WebP images are supported."]);
    }
}

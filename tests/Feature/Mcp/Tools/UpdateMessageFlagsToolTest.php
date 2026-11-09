<?php

namespace Tests\Feature\Mcp\Tools;

use App\Imap\MailWriter;
use App\Mcp\Servers\MailServer;
use App\Mcp\Tools\UpdateMessageFlagsTool;
use Mockery\MockInterface;
use Tests\TestCase;

class UpdateMessageFlagsToolTest extends TestCase
{
    public function test_flags_are_updated_on_the_given_messages(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock
            ->expects('setFlags')
            ->with('Archive', [5, 6], true, null)
            ->andReturn(2));

        MailServer::tool(UpdateMessageFlagsTool::class, ['folder' => 'Archive', 'uids' => [5, 6, 5], 'read' => true])
            ->assertOk()
            ->assertSee('"updated":2');
    }

    public function test_no_matching_messages_is_an_error(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock->expects('setFlags')->andReturn(0));

        MailServer::tool(UpdateMessageFlagsTool::class, ['uids' => [99], 'flagged' => true])
            ->assertHasErrors(['No matching messages.']);
    }

    public function test_a_flag_to_change_is_required(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock->shouldNotReceive('setFlags'));

        MailServer::tool(UpdateMessageFlagsTool::class, ['uids' => [5]])
            ->assertHasErrors(['The read field is required when flagged is not present.']);
    }

    public function test_at_most_fifty_messages_can_be_changed_at_once(): void
    {
        $this->mock(MailWriter::class, fn (MockInterface $mock) => $mock->shouldNotReceive('setFlags'));

        MailServer::tool(UpdateMessageFlagsTool::class, ['uids' => range(1, 51), 'read' => true])
            ->assertHasErrors(['The uids field must not have more than 50 items.']);
    }
}

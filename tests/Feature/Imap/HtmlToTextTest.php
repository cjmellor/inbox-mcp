<?php

namespace Tests\Feature\Imap;

use App\Imap\HtmlToText;
use Tests\TestCase;

class HtmlToTextTest extends TestCase
{
    public function test_markup_becomes_readable_text(): void
    {
        $html = '<html><head><title>Ignored</title><style>p { color: red }</style></head><body>'
            .'<p>Hi&nbsp;Chris,</p><p>Your order &amp; receipt:<br>'
            .'<a href="https://example.com/order">View order</a></p>'
            .'<script>alert(1)</script><div>Thanks</div></body></html>';

        $this->assertSame(
            "Hi Chris,\nYour order & receipt:\nView order (https://example.com/order)\nThanks",
            HtmlToText::convert($html),
        );
    }
}

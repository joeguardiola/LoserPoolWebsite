<?php

namespace LoserPool\Tests\Unit;

use LoserPool\Mail\ResendMailer;
use PHPUnit\Framework\TestCase;

/*
 * The parts of the mailer that can be wrong without the network being
 * involved. Sending itself is exercised by playing the reminder job through a
 * stub mailer; these are the string transforms that shape the message.
 */
final class ResendMailerTest extends TestCase
{
    public function testAnAddressIsExtractedFromADisplayNameForm(): void
    {
        $this->assertSame('pool@example.com', ResendMailer::addressOf('Loser Pool <pool@example.com>'));
        $this->assertSame('pool@example.com', ResendMailer::addressOf('pool@example.com'));
        $this->assertSame('pool@example.com', ResendMailer::addressOf('  pool@example.com  '));
    }

    /*
     * The List-Unsubscribe header is built from this. A display name left in
     * it makes the header invalid, and an invalid header is worse than none:
     * it is the header Gmail is checking for.
     */
    public function testTheExtractedAddressCarriesNoDisplayName(): void
    {
        $this->assertStringNotContainsString('<', ResendMailer::addressOf('A B <a@b.com>'));
        $this->assertStringNotContainsString('Loser', ResendMailer::addressOf('Loser Pool <pool@example.com>'));
    }

    public function testTextBecomesParagraphsOfHtml(): void
    {
        $html = ResendMailer::asHtml("First line.\n\nSecond block.\nSame block.\n");

        $this->assertStringContainsString('<p>First line.</p>', $html);
        $this->assertStringContainsString('Second block.<br', $html, 'a single newline stays inside the paragraph');
        $this->assertSame(2, substr_count($html, '<p>'));
    }

    /* Nothing from the message body may become markup. */
    public function testHtmlIsEscaped(): void
    {
        $html = ResendMailer::asHtml('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}

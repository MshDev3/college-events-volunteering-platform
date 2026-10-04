<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** L-7: stored email addresses must not be able to inject recipients or headers into mailto: links. */
final class MailtoTest extends TestCase
{
    public function testPlainAddressAndSubject(): void
    {
        self::assertSame('mailto:student@college.test', mailto_href('student@college.test'));
        self::assertSame('mailto:student@college.test?subject=Re%3A%20%D8%A7%D9%84%D8%AA%D8%B3%D8%AC%D9%8A%D9%84%20%26%20more',
            mailto_href('student@college.test', 'Re: التسجيل & more'));
    }

    public function testInjectedQueryInTheAddressIsEncoded(): void
    {
        $href = mailto_href('a?bcc=spy@evil.com', 'Re: hello');
        self::assertSame('mailto:a%3Fbcc%3Dspy@evil.com?subject=Re%3A%20hello', $href);
        self::assertSame(1, substr_count($href, '?'), 'only our own ?subject= query remains');

        foreach (['x@a.com&cc=spy@evil.com', "x@a.com\r\nBcc: spy@evil.com", 'x@a.com?body=click%20here', '"a b"@a.com'] as $email) {
            $href = mailto_href($email);
            self::assertDoesNotMatchRegularExpression('/[?&\s"]/', $href, $email);
        }
    }

    public function testHtmlEscapingStillApplies(): void
    {
        self::assertStringNotContainsString('"', e(mailto_href('"><script>@x.com')));
    }
}

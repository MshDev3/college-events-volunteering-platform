<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** S1: only plain app-relative paths may be used as a redirect target taken from user input or stored data. */
final class LocalPathTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptedPaths(): iterable
    {
        yield 'root' => ['/'];
        yield 'page' => ['/events/3'];
        yield 'query' => ['/events?page=2&q=a%20b'];
        yield 'fragment' => ['/events#top'];
        yield 'encoded tab stays encoded' => ['/events/%09x'];
        yield 'arabic' => ['/events?q=' . 'ورشة'];
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['events'];
        yield 'protocol-relative' => ['//evil.com'];
        yield 'absolute' => ['https://evil.com'];
        yield 'backslash' => ['/\\evil.com'];
        yield 'backslash later' => ['/a\\b'];
        yield 'tab after slash' => ["/\t/evil.com"];
        yield 'newline after slash' => ["/\n/evil.com"];
        yield 'carriage return' => ["/\r/evil.com"];
        yield 'nul byte' => ["/\0/evil.com"];
        yield 'other control char' => ["/\x01/evil.com"];
        yield 'delete char' => ["/\x7f/evil.com"];
        yield 'space-led scheme' => [' /events'];
    }

    #[DataProvider('acceptedPaths')]
    public function testAcceptsPlainLocalPaths(string $path): void
    {
        self::assertSame($path, Response::localPath($path));
    }

    #[DataProvider('rejectedPaths')]
    public function testRejectsAnythingElse(string $path): void
    {
        self::assertNull(Response::localPath($path));
    }

    public function testRedirectNeverEmitsControlCharactersInLocation(): void
    {
        $location = Response::redirect("/\t/evil.com")->header('Location');

        self::assertNotNull($location);
        self::assertSame(0, preg_match('/[\x00-\x1f\x7f\\\\]/', $location), 'Location must not carry control characters or backslashes');
        self::assertStringEndsWith('/', $location, 'a rejected target falls back to the application root');
    }

    public function testRedirectKeepsNormalTargets(): void
    {
        self::assertStringEndsWith('/events/3?x=1', (string) Response::redirect('/events/3?x=1')->header('Location'));
        self::assertSame('https://example.test/a', Response::redirect('https://example.test/a')->header('Location'));
    }
}

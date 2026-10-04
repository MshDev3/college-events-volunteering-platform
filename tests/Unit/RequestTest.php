<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Request;
use PHPUnit\Framework\TestCase;

/** HTTPS / client-IP detection must only trust X-Forwarded-* from configured proxies. */
final class RequestTest extends TestCase
{
    private mixed $originalProxies;
    private mixed $originalUrl;

    protected function setUp(): void
    {
        $this->originalProxies = config('app.trusted_proxies');
        $this->originalUrl = config('app.url');
    }

    protected function tearDown(): void
    {
        app(Config::class)->set('app.trusted_proxies', $this->originalProxies);
        app(Config::class)->set('app.url', $this->originalUrl);
    }

    /** @param array<string, string> $server */
    private function request(array $server): Request
    {
        return new Request('GET', '/', [], [], [], [], $server);
    }

    public function testHstsOnlyForRealHttpsOutsideLocalDevelopment(): void
    {
        app(Config::class)->set('app.trusted_proxies', []);
        $https = $this->request(['REMOTE_ADDR' => '10.0.0.5', 'HTTPS' => 'on']);
        $spoofed = $this->request(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertTrue(\App\Core\App::sendsHsts($https, 'production'));
        self::assertFalse(\App\Core\App::sendsHsts($this->request(['REMOTE_ADDR' => '10.0.0.5']), 'production'), 'not over plain http');
        self::assertFalse(\App\Core\App::sendsHsts($spoofed, 'production'), 'not for an untrusted X-Forwarded-Proto');
        self::assertFalse(\App\Core\App::sendsHsts($https, 'local'), 'never pinned on a developer machine');
    }

    public function testForwardedHeadersAreIgnoredWithoutTrustedProxies(): void
    {
        app(Config::class)->set('app.trusted_proxies', []);
        $r = $this->request(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1']);

        self::assertFalse($r->isSecure(), 'a client cannot claim HTTPS');
        self::assertSame('10.0.0.5', $r->ip(), 'a client cannot spoof its IP');
        self::assertFalse($r->isLoopback(), 'a LAN client cannot pretend to be localhost');
    }

    public function testTrustedProxyIsHonoured(): void
    {
        app(Config::class)->set('app.trusted_proxies', ['10.0.0.1']);
        $r = $this->request(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.1']);

        self::assertTrue($r->isSecure());
        self::assertSame('203.0.113.9', $r->ip(), 'right-most untrusted address in the chain');
        self::assertTrue($r->cookieSecure());
    }

    public function testUntrustedPeerCannotUseForwardedHeadersEvenIfProxiesAreConfigured(): void
    {
        app(Config::class)->set('app.trusted_proxies', ['10.0.0.1']);
        $r = $this->request(['REMOTE_ADDR' => '10.0.0.99', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1']);

        self::assertFalse($r->isSecure());
        self::assertSame('10.0.0.99', $r->ip());
    }

    public function testDirectHttpsAndHttpsAppUrlMakeCookiesSecure(): void
    {
        app(Config::class)->set('app.trusted_proxies', []);
        self::assertTrue($this->request(['REMOTE_ADDR' => '1.2.3.4', 'HTTPS' => 'on'])->isSecure());
        self::assertFalse($this->request(['REMOTE_ADDR' => '1.2.3.4', 'HTTPS' => 'off'])->isSecure());

        app(Config::class)->set('app.url', 'https://portal.example.edu');
        self::assertTrue($this->request(['REMOTE_ADDR' => '1.2.3.4'])->cookieSecure());
        app(Config::class)->set('app.url', 'http://localhost/project');
        self::assertFalse($this->request(['REMOTE_ADDR' => '1.2.3.4'])->cookieSecure());
    }

    public function testLoopbackDetection(): void
    {
        app(Config::class)->set('app.trusted_proxies', []);
        self::assertTrue($this->request(['REMOTE_ADDR' => '127.0.0.1'])->isLoopback());
        self::assertTrue($this->request(['REMOTE_ADDR' => '::1'])->isLoopback());
        self::assertFalse($this->request(['REMOTE_ADDR' => '192.0.2.10'])->isLoopback()); // RFC 5737 documentation address
    }

    /** S10: state-changing flags must be sent explicitly; "missing" must not silently mean "false". */
    public function testExplicitBooleanDistinguishesMissingFromFalse(): void
    {
        $post = static fn (array $body): Request => new Request('POST', '/x', [], $body, [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        foreach (['1', 'true', 'on', 'yes'] as $yes) {
            self::assertTrue($post(['active' => $yes])->explicitBoolean('active'), $yes);
        }
        foreach (['0', 'false', 'off', 'no'] as $no) {
            self::assertFalse($post(['active' => $no])->explicitBoolean('active'), $no);
        }
        foreach ([[], ['active' => ''], ['active' => 'banana'], ['active' => ['1']], ['other' => '1']] as $i => $body) {
            self::assertNull($post($body)->explicitBoolean('active'), "case $i is neither true nor false");
        }
        self::assertFalse($post([])->boolean('active'), 'boolean() keeps treating a missing checkbox as false');
    }
}

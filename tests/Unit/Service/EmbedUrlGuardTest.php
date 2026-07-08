<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Service\EmbedUrlGuard;

#[CoversClass(EmbedUrlGuard::class)]
final class EmbedUrlGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function schemeAndLiteralProvider(): iterable
    {
        // Non-HTTP schemes and metadata/loopback/private IP literals need no DNS.
        yield 'https public IP literal' => ['https://93.184.216.34/oembed', true];
        yield 'http public IP literal' => ['http://8.8.8.8/', true];
        yield 'file scheme' => ['file:///etc/passwd', false];
        yield 'gopher scheme' => ['gopher://evil/', false];
        yield 'ftp scheme' => ['ftp://host/file', false];
        yield 'scheme-relative (no scheme)' => ['//example.com/x', false];
        yield 'empty string' => ['', false];
        yield 'garbage' => ['not a url', false];
        yield 'cloud metadata IP' => ['http://169.254.169.254/latest/meta-data/', false];
        yield 'loopback IPv4' => ['http://127.0.0.1/', false];
        yield 'loopback IPv6' => ['http://[::1]/', false];
        yield 'private 10/8' => ['http://10.0.0.5/', false];
        yield 'private 192.168/16' => ['https://192.168.1.1/admin', false];
        yield 'private 172.16/12' => ['http://172.16.0.1/', false];
        yield 'localhost hostname' => ['http://localhost/', false];
        yield 'subdomain of localhost' => ['http://api.localhost/', false];
    }

    #[DataProvider('schemeAndLiteralProvider')]
    public function testSchemeAndIpLiteralHandling(string $url, bool $expected): void
    {
        // A resolver that must never be called for IP-literal / scheme cases; if it
        // were invoked, the fixed map still keeps the assertions deterministic.
        $guard = new EmbedUrlGuard(static fn (string $host): array => []);

        self::assertSame($expected, $guard->isSafe($url));
    }

    public function testHostnameResolvingToPublicAddressIsSafe(): void
    {
        $guard = new EmbedUrlGuard(static fn (string $host): array => ['93.184.216.34']);

        self::assertTrue($guard->isSafe('https://www.youtube.com/watch?v=abc'));
    }

    public function testHostnameResolvingToPrivateAddressIsRejected(): void
    {
        // DNS rebinding / split-horizon: a public-looking host resolving to an
        // internal address must be rejected.
        $guard = new EmbedUrlGuard(static fn (string $host): array => ['10.0.0.9']);

        self::assertFalse($guard->isSafe('https://internal.example.com/'));
    }

    public function testHostnameWithAnyPrivateAddressIsRejected(): void
    {
        // If a host resolves to multiple addresses, one private address is enough
        // to reject it (the crawler could connect to any of them).
        $guard = new EmbedUrlGuard(static fn (string $host): array => ['93.184.216.34', '127.0.0.1']);

        self::assertFalse($guard->isSafe('https://mixed.example.com/'));
    }

    public function testUnresolvableHostnameIsRejected(): void
    {
        $guard = new EmbedUrlGuard(static fn (string $host): array => []);

        self::assertFalse($guard->isSafe('https://does-not-resolve.invalid/'));
    }
}

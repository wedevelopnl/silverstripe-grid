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

        // IPv6-mapped IPv4 and other v6 literals for internal targets.
        yield 'IPv6-mapped loopback' => ['http://[::ffff:127.0.0.1]/', false];
        yield 'IPv6-mapped metadata IP' => ['http://[::ffff:169.254.169.254]/', false];
        yield 'IPv6 link-local' => ['http://[fe80::1]/', false];
        yield 'IPv6 unique-local' => ['http://[fc00::1]/', false];

        // Ranges PHP's NO_PRIV_RANGE|NO_RES_RANGE flags treat as public but that
        // are not publicly routable destinations.
        yield 'CGNAT 100.64.0.0/10 lower bound' => ['http://100.64.0.1/', false];
        yield 'CGNAT 100.64.0.0/10 upper bound' => ['http://100.127.255.255/', false];
        yield 'below CGNAT range is public' => ['http://100.63.255.255/', true];
        yield 'above CGNAT range is public' => ['http://100.128.0.1/', true];
        yield 'IETF protocol assignments 192.0.0.0/24' => ['http://192.0.0.170/', false];
        yield 'above 192.0.0.0/24 is public' => ['http://192.0.1.1/', true];
        yield 'benchmarking 198.18.0.0/15 lower' => ['http://198.18.0.1/', false];
        yield 'benchmarking 198.18.0.0/15 upper' => ['http://198.19.255.255/', false];
        yield 'below benchmarking range is public' => ['http://198.17.255.255/', true];
        yield 'above benchmarking range is public' => ['http://198.20.0.1/', true];
        yield 'NAT64 64:ff9b::/96 loopback' => ['http://[64:ff9b::7f00:1]/', false];
        yield 'NAT64 64:ff9b::/96 arbitrary v4' => ['http://[64:ff9b::102:304]/', false];

        // Alternative numeric IP spellings the system resolver would normalize to
        // an address. No registrable hostname has an all-numeric/hex final label,
        // so these are rejected outright — before any DNS lookup.
        yield 'decimal IPv4 encoding' => ['http://2130706433/', false];
        yield 'hex IPv4 encoding' => ['http://0x7f000001/', false];
        yield 'octal IPv4 encoding' => ['http://017700000001/', false];
        yield 'dotted-octal IPv4 encoding' => ['http://0177.0.0.1/', false];
        yield 'dotted-hex IPv4 encoding' => ['http://0x7f.0x0.0x0.0x1/', false];
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function numericHostProvider(): iterable
    {
        yield 'decimal' => ['http://2130706433/'];
        yield 'hex' => ['http://0x7f000001/'];
        yield 'octal' => ['http://017700000001/'];
        yield 'dotted octal' => ['http://0177.0.0.1/'];
        yield 'dotted hex' => ['http://0x7f.0x0.0x0.0x1/'];
        yield 'five dotted groups' => ['http://1.2.3.4.5/'];
    }

    /**
     * Numeric IP spellings are normalized to an address by the system resolver,
     * which an injected test resolver cannot reproduce. They must therefore be
     * rejected before DNS resolution — even a resolver claiming a public address
     * must not make them safe.
     */
    #[DataProvider('numericHostProvider')]
    public function testNumericHostSpellingsAreRejectedBeforeDns(string $url): void
    {
        $guard = new EmbedUrlGuard(static function (string $host): array {
            self::fail("DNS resolution must not be attempted for numeric host spellings (got: {$host})");
        });

        self::assertFalse($guard->isSafe($url));
    }
}

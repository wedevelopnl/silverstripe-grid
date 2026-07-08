<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use Closure;

/**
 * Guards the server-side oEmbed fetch against SSRF.
 *
 * The MediaField oEmbed lookup performs a synchronous HTTP GET of a user-supplied
 * URL. Without a guard, a CMS editor could point it at internal services or the
 * cloud metadata endpoint (169.254.169.254) and exfiltrate the reflected response.
 * This validates that a URL is HTTP(S) and resolves only to publicly routable
 * addresses before any fetch is attempted.
 *
 * Redirects are covered by GuardedEmbedClient, which runs this guard against
 * every request the embed crawler makes (initial URL, each redirect hop, and
 * the oEmbed endpoints discovered inside fetched HTML).
 *
 * Residual risk: DNS rebinding. This guard resolves a hostname to validate it,
 * and curl resolves it again to connect — an attacker operating an
 * authoritative low-TTL zone can answer publicly for the first lookup and
 * privately for the second. Pinning the validated IP to the connection needs
 * CURLOPT_RESOLVE, which the embed library's dispatcher does not expose.
 * Accepted because exploitation requires attacker-controlled DNS and exposure
 * is bounded to the oEmbed-parsed metadata fields.
 */
final readonly class EmbedUrlGuard
{
    /** @var Closure(string): list<string> resolves a hostname to its IP addresses */
    private Closure $resolveIps;

    /**
     * @param (callable(string): list<string>)|null $resolveIps Injectable resolver
     *     (tests supply a deterministic map). Defaults to real DNS resolution.
     */
    public function __construct(?callable $resolveIps = null)
    {
        $this->resolveIps = $resolveIps !== null
            ? Closure::fromCallable($resolveIps)
            : static function (string $host): array {
                $ipv4 = gethostbynamel($host);
                $aaaa = dns_get_record($host, DNS_AAAA);
                /** @var list<string> $ipv6 */
                $ipv6 = \is_array($aaaa) ? array_column($aaaa, 'ipv6') : [];

                return array_values(array_filter([
                    ...(\is_array($ipv4) ? $ipv4 : []),
                    ...$ipv6,
                ]));
            };
    }

    /**
     * True when $url is an HTTP(S) URL whose host resolves only to publicly
     * routable IP addresses. Non-HTTP schemes, missing hosts, localhost, IP
     * literals in private/reserved ranges, and unresolvable hosts are all unsafe.
     */
    public function isSafe(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        // Strip IPv6 literal brackets, e.g. "[::1]" → "::1".
        $host = trim($parts['host'], '[]');
        $lowerHost = strtolower($host);
        if ($host === '' || $lowerHost === 'localhost' || str_ends_with($lowerHost, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        // Alternative numeric IP spellings (decimal 2130706433, hex 0x7f000001,
        // octal 017700000001, dotted variants) are not valid IPs to filter_var but
        // ARE normalized to an address by the system resolver — behavior an
        // injected resolver cannot reproduce and tests cannot pin. No registrable
        // hostname consists solely of numeric/hex labels (TLDs are alphabetic),
        // so reject these outright before any DNS lookup.
        if (preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/i', $host) === 1) {
            return false;
        }

        $ips = ($this->resolveIps)($host);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when $ip is a valid address outside every private and reserved range.
     *
     * PHP's NO_PRIV_RANGE|NO_RES_RANGE flags cover loopback (127/8, ::1),
     * link-local 169.254/16 (incl. the cloud metadata IP), RFC1918, and fc00::/7.
     * They do NOT cover CGNAT 100.64.0.0/10, IETF protocol assignments
     * 192.0.0.0/24, benchmarking 198.18.0.0/15, or NAT64 64:ff9b::/96 — all of
     * which can reach non-public targets — so those are checked explicitly.
     */
    private function isPublicIp(string $ip): bool
    {
        $valid = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        if ($valid === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            return $long === false || !$this->isInUnroutableIpv4Range($long);
        }

        // NAT64 (64:ff9b::/96) translates the embedded IPv4 in the low 32 bits —
        // an oEmbed provider is never legitimately behind NAT64, so reject the
        // whole prefix rather than decode and re-validate the embedded address.
        $packed = inet_pton($ip);

        return $packed === false
            || \strlen($packed) !== 16
            || !str_starts_with($packed, "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00");
    }

    /**
     * True when the IPv4 address (as a long) falls in a range that is formally
     * "public" to PHP's filter flags but not publicly routable: CGNAT
     * 100.64.0.0/10, IETF protocol assignments 192.0.0.0/24, and benchmarking
     * 198.18.0.0/15.
     */
    private function isInUnroutableIpv4Range(int $long): bool
    {
        /** @var list<array{int, int}> $rangesAndMasks [network, mask] pairs */
        $rangesAndMasks = [
            [0x64400000, 0xFFC00000], // 100.64.0.0/10
            [0xC0000000, 0xFFFFFF00], // 192.0.0.0/24
            [0xC6120000, 0xFFFE0000], // 198.18.0.0/15
        ];

        foreach ($rangesAndMasks as [$network, $mask]) {
            if (($long & $mask) === $network) {
                return true;
            }
        }

        return false;
    }
}

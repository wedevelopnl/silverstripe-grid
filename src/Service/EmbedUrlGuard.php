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
 * Residual risk: the underlying crawler follows redirects, so a public host that
 * 3xx-redirects to an internal address is not caught here — fully closing that
 * needs a redirect-validating HTTP client. This guard closes the direct vector
 * (an internal URL typed straight into the field), which is the common case.
 */
final class EmbedUrlGuard
{
    /** @var Closure(string): list<string> resolves a hostname to its IP addresses */
    private readonly Closure $resolveIps;

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
     * True when $ip is a valid address outside every private and reserved range
     * (covers loopback 127/8 and ::1, link-local 169.254/16 incl. the cloud
     * metadata IP, RFC1918, and fc00::/7).
     */
    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}

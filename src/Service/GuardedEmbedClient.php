<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use WeDevelop\Grid\Exception\UnsafeEmbedUrlException;

/**
 * PSR-18 decorator that validates every request the embed crawler makes —
 * including redirect hops and the oEmbed endpoint URLs the library discovers
 * inside fetched (attacker-influenced) HTML — against the EmbedUrlGuard.
 *
 * The inner client MUST have its own redirect following disabled
 * (CurlClient settings: follow_location=false); redirects are followed here so
 * each hop's target is validated before it is fetched. curl-level following
 * would bypass the guard entirely.
 *
 * Residual risk: DNS rebinding. The guard resolves a hostname to validate it,
 * then curl resolves it again to connect — an attacker controlling a low-TTL
 * zone can answer differently each time. Pinning the validated IP to the
 * connection needs CURLOPT_RESOLVE, which the embed library's dispatcher does
 * not expose. Accepted: exploitation requires attacker-authoritative DNS, and
 * exposure is bounded to oEmbed-parsed metadata fields.
 */
final readonly class GuardedEmbedClient implements ClientInterface
{
    private const int MAX_REDIRECTS = 5;

    public function __construct(
        private ClientInterface $inner,
        private EmbedUrlGuard $guard,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $redirects = 0;

        while (true) {
            if (!$this->guard->isSafe((string) $request->getUri())) {
                throw UnsafeEmbedUrlException::unsafeUrl();
            }

            $response = $this->inner->sendRequest($request);

            $status = $response->getStatusCode();
            if ($status < 300 || $status >= 400 || !$response->hasHeader('Location')) {
                return $response;
            }

            if (++$redirects > self::MAX_REDIRECTS) {
                throw UnsafeEmbedUrlException::tooManyRedirects(self::MAX_REDIRECTS);
            }

            $request = $this->redirectedRequest($request, $response->getHeaderLine('Location'), $status);
        }
    }

    /**
     * Build the follow-up request for a redirect. Absolute, protocol-relative,
     * and root-relative Location values are supported; anything else (notably
     * path-relative references, which need a full RFC 3986 resolver) is refused
     * rather than guessed at.
     */
    private function redirectedRequest(RequestInterface $request, string $location, int $status): RequestInterface
    {
        $next = $request->withUri($this->resolveLocation($request->getUri(), $location));

        // 303 means "fetch the result with GET" regardless of the original
        // method; legacy clients treat 301/302 on POST the same way.
        if ($status === 303 || ($status !== 307 && $status !== 308 && $next->getMethod() === 'POST')) {
            return $next->withMethod('GET');
        }

        return $next;
    }

    private function resolveLocation(UriInterface $current, string $location): UriInterface
    {
        $parts = parse_url($location);
        if (!\is_array($parts)) {
            throw UnsafeEmbedUrlException::unresolvableRedirect();
        }

        // Absolute (scheme://host/...) or protocol-relative (//host/...): take
        // host & co from the Location, inheriting the scheme when absent. The
        // fragment is dropped and any userinfo deliberately discarded — the
        // guard validates hosts, not credentials.
        if (isset($parts['host'])) {
            return $current
                ->withScheme($parts['scheme'] ?? $current->getScheme())
                ->withUserInfo('')
                ->withHost($parts['host'])
                ->withPort($parts['port'] ?? null)
                ->withPath($parts['path'] ?? '')
                ->withQuery($parts['query'] ?? '')
                ->withFragment('');
        }

        // Root-relative (/path?query): same authority, new path.
        if (str_starts_with($location, '/')) {
            return $current
                ->withPath($parts['path'] ?? '')
                ->withQuery($parts['query'] ?? '')
                ->withFragment('');
        }

        throw UnsafeEmbedUrlException::unresolvableRedirect();
    }
}

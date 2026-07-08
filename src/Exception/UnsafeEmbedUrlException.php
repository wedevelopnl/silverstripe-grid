<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Thrown by GuardedEmbedClient when an oEmbed fetch would reach a URL the
 * EmbedUrlGuard rejects, or when a redirect chain cannot be safely followed.
 *
 * Implements the PSR-18 client exception contract so the embed library treats
 * it like any other transport failure. Messages intentionally omit the
 * offending URL — it is editor-supplied (or attacker-redirected) data and the
 * caller logs exception messages.
 */
final class UnsafeEmbedUrlException extends RuntimeException implements ClientExceptionInterface
{
    public static function unsafeUrl(): self
    {
        return new self('Refusing oEmbed fetch: URL is not an HTTP(S) URL on a publicly routable host.');
    }

    public static function unresolvableRedirect(): self
    {
        return new self('Refusing oEmbed fetch: redirect target cannot be safely resolved.');
    }

    public static function tooManyRedirects(int $limit): self
    {
        return new self(sprintf('Refusing oEmbed fetch: more than %d redirects.', $limit));
    }
}

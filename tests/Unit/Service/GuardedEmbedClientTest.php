<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WeDevelop\Grid\Exception\UnsafeEmbedUrlException;
use WeDevelop\Grid\Service\EmbedUrlGuard;
use WeDevelop\Grid\Service\GuardedEmbedClient;

#[CoversClass(GuardedEmbedClient::class)]
final class GuardedEmbedClientTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Guard with a deterministic resolver: *.public.example resolves publicly,
     * internal.example resolves to a private address.
     */
    private static function guard(): EmbedUrlGuard
    {
        return new EmbedUrlGuard(static fn (string $host): array => match (true) {
            str_ends_with($host, 'public.example') => ['93.184.216.34'],
            $host === 'internal.example' => ['10.0.0.9'],
            default => [],
        });
    }

    /**
     * @param list<ResponseInterface> $responses
     * @return ClientInterface&object{requests: list<RequestInterface>}
     */
    private static function innerClient(array $responses): ClientInterface
    {
        return new class($responses) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            /** @param list<ResponseInterface> $responses */
            public function __construct(private array $responses)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                $response = array_shift($this->responses);
                \assert($response instanceof ResponseInterface);

                return $response;
            }
        };
    }

    public function testUnsafeInitialUrlIsRejectedWithoutFetching(): void
    {
        $inner = self::innerClient([]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $this->expectException(UnsafeEmbedUrlException::class);

        try {
            $client->sendRequest(new Request('GET', 'https://internal.example/page'));
        } finally {
            self::assertSame([], $inner->requests, 'The inner client must never be reached for an unsafe URL');
        }
    }

    public function testNonRedirectResponseIsReturnedAsIs(): void
    {
        $inner = self::innerClient([new Response(200, [], 'body')]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $response = $client->sendRequest(new Request('GET', 'https://video.public.example/watch'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $inner->requests);
    }

    public function testRedirectToSafeAbsoluteUrlIsFollowed(): void
    {
        $inner = self::innerClient([
            new Response(302, ['Location' => 'https://cdn.public.example/real']),
            new Response(200, [], 'final'),
        ]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $response = $client->sendRequest(new Request('GET', 'https://video.public.example/watch'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $inner->requests);
        self::assertSame('https://cdn.public.example/real', (string) $inner->requests[1]->getUri());
    }

    public function testRedirectToPrivateHostIsRejected(): void
    {
        $inner = self::innerClient([
            new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $this->expectException(UnsafeEmbedUrlException::class);

        try {
            $client->sendRequest(new Request('GET', 'https://video.public.example/watch'));
        } finally {
            self::assertCount(1, $inner->requests, 'The redirect target must not be fetched');
        }
    }

    public function testRedirectToPrivateResolvingHostnameIsRejected(): void
    {
        $inner = self::innerClient([
            new Response(301, ['Location' => 'https://internal.example/secret']),
        ]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $this->expectException(UnsafeEmbedUrlException::class);

        try {
            $client->sendRequest(new Request('GET', 'https://video.public.example/watch'));
        } finally {
            self::assertCount(1, $inner->requests);
        }
    }

    public function testRootRelativeRedirectResolvesAgainstCurrentHost(): void
    {
        $inner = self::innerClient([
            new Response(302, ['Location' => '/oembed?format=json']),
            new Response(200),
        ]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $client->sendRequest(new Request('GET', 'https://video.public.example/watch?v=1'));

        self::assertSame(
            'https://video.public.example/oembed?format=json',
            (string) $inner->requests[1]->getUri(),
        );
    }

    public function testProtocolRelativeRedirectKeepsCurrentScheme(): void
    {
        $inner = self::innerClient([
            new Response(302, ['Location' => '//cdn.public.example/real']),
            new Response(200),
        ]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $client->sendRequest(new Request('GET', 'https://video.public.example/watch'));

        self::assertSame('https://cdn.public.example/real', (string) $inner->requests[1]->getUri());
    }

    public function testPathRelativeRedirectIsRefused(): void
    {
        // A path-relative Location cannot be resolved without a full RFC 3986
        // reference resolver; refuse what cannot be confidently validated.
        $inner = self::innerClient([
            new Response(302, ['Location' => 'other/page']),
        ]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $this->expectException(UnsafeEmbedUrlException::class);

        $client->sendRequest(new Request('GET', 'https://video.public.example/watch'));
    }

    public function testSeeOtherRedirectSwitchesToGet(): void
    {
        $inner = self::innerClient([
            new Response(303, ['Location' => 'https://cdn.public.example/result']),
            new Response(200),
        ]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $client->sendRequest(new Request('POST', 'https://video.public.example/submit'));

        self::assertSame('GET', $inner->requests[1]->getMethod());
    }

    public function testRedirectChainBeyondLimitIsRefused(): void
    {
        $responses = [];
        for ($i = 0; $i < 6; $i++) {
            $responses[] = new Response(302, ['Location' => "https://hop{$i}.public.example/next"]);
        }
        $inner = self::innerClient($responses);
        $client = new GuardedEmbedClient($inner, self::guard());

        $this->expectException(UnsafeEmbedUrlException::class);
        $this->expectExceptionMessage('redirects');

        $client->sendRequest(new Request('GET', 'https://video.public.example/watch'));
    }

    public function testRedirectStatusWithoutLocationIsReturnedAsIs(): void
    {
        $inner = self::innerClient([new Response(304)]);
        $client = new GuardedEmbedClient($inner, self::guard());

        $response = $client->sendRequest(new Request('GET', 'https://video.public.example/watch'));

        self::assertSame(304, $response->getStatusCode());
        self::assertCount(1, $inner->requests);
    }
}

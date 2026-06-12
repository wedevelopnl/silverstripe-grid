<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Exception;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeDevelop\Grid\Exception\GridDomainException;
use WeDevelop\Grid\Exception\InvalidGridValueException;

#[CoversClass(InvalidGridValueException::class)]
final class InvalidGridValueExceptionTest extends TestCase
{
    public function testIsInstanceOfGridDomainException(): void
    {
        $exception = InvalidGridValueException::forEmptyViewports();

        self::assertInstanceOf(GridDomainException::class, $exception);
    }

    public function testIsInstanceOfRuntimeException(): void
    {
        $exception = InvalidGridValueException::forEmptyViewports();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testExceptionCodeIsZero(): void
    {
        $exception = InvalidGridValueException::forViewport('xxl');

        self::assertSame(0, $exception->getCode());
    }

    /**
     * Each case: [factory, expectedStatusCode, expectedUserMessage, expectedMessageFragment].
     *
     * @return iterable<string, array{Closure(): InvalidGridValueException, int, string, string}>
     */
    public static function factoryProvider(): iterable
    {
        yield 'forViewport' => [
            static fn (): InvalidGridValueException => InvalidGridValueException::forViewport('xxxl'),
            422,
            'The specified viewport is not recognised.',
            'xxxl',
        ];

        yield 'forEmptyViewports' => [
            static fn (): InvalidGridValueException => InvalidGridValueException::forEmptyViewports(),
            422,
            'At least one viewport must be enabled.',
            'enabled_viewports',
        ];

        yield 'forContainerMaxWidth' => [
            static fn (): InvalidGridValueException => InvalidGridValueException::forContainerMaxWidth(-100),
            422,
            'The configured container max width is invalid.',
            '-100',
        ];

        yield 'forColumnCount (string)' => [
            static fn (): InvalidGridValueException => InvalidGridValueException::forColumnCount('not-a-number'),
            422,
            'The configured column count is invalid.',
            'not-a-number',
        ];

        yield 'forColumnCount (float reports type)' => [
            static fn (): InvalidGridValueException => InvalidGridValueException::forColumnCount(3.14),
            422,
            'The configured column count is invalid.',
            'float',
        ];

        yield 'forMalformedViewportPayload' => [
            static fn (): InvalidGridValueException => InvalidGridValueException::forMalformedViewportPayload('overrides["md"]', 'missing width'),
            422,
            'The grid settings payload is malformed.',
            'overrides["md"]',
        ];

        yield 'forAspectRatioClass' => [
            static fn (): InvalidGridValueException => InvalidGridValueException::forAspectRatioClass('Some\\Adapter', '16x9'),
            422,
            'The configured aspect ratio mapping is incomplete.',
            '16x9',
        ];

        yield 'forOverrideStrategy' => [
            static fn (): InvalidGridValueException => InvalidGridValueException::forOverrideStrategy('merge'),
            422,
            'The configured override strategy is invalid.',
            'merge',
        ];

        yield 'forMalformedViewportDefinition' => [
            static fn (): InvalidGridValueException => InvalidGridValueException::forMalformedViewportDefinition('md', 'missing label'),
            422,
            'A configured viewport is malformed.',
            'viewport_definitions',
        ];
    }

    /**
     * @param Closure(): InvalidGridValueException $factory
     */
    #[DataProvider('factoryProvider')]
    public function testFactoryProducesExpectedException(
        Closure $factory,
        int $expectedStatusCode,
        string $expectedUserMessage,
        string $expectedMessageFragment,
    ): void {
        $exception = $factory();

        self::assertInstanceOf(InvalidGridValueException::class, $exception);
        self::assertSame($expectedStatusCode, $exception->statusCode);
        self::assertSame($expectedUserMessage, $exception->userMessage);
        self::assertStringContainsString($expectedMessageFragment, $exception->getMessage());
    }
}

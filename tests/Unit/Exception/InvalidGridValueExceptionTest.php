<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testForViewportStatusCode(): void
    {
        $exception = InvalidGridValueException::forViewport('xxxl');

        self::assertSame(422, $exception->statusCode);
    }

    public function testForViewportUserMessage(): void
    {
        $exception = InvalidGridValueException::forViewport('xxxl');

        self::assertSame('The specified viewport is not recognised.', $exception->userMessage);
    }

    public function testForViewportDetailedMessageContainsKey(): void
    {
        $exception = InvalidGridValueException::forViewport('xxxl');

        self::assertStringContainsString('xxxl', $exception->getMessage());
    }

    public function testForEmptyViewportsStatusCode(): void
    {
        $exception = InvalidGridValueException::forEmptyViewports();

        self::assertSame(422, $exception->statusCode);
    }

    public function testForEmptyViewportsUserMessage(): void
    {
        $exception = InvalidGridValueException::forEmptyViewports();

        self::assertSame('At least one viewport must be enabled.', $exception->userMessage);
    }

    public function testForEmptyViewportsDetailedMessage(): void
    {
        $exception = InvalidGridValueException::forEmptyViewports();

        self::assertStringContainsString('enabled_viewports', $exception->getMessage());
    }

    public function testForContainerMaxWidthStatusCode(): void
    {
        $exception = InvalidGridValueException::forContainerMaxWidth(-100);

        self::assertSame(422, $exception->statusCode);
    }

    public function testForContainerMaxWidthUserMessage(): void
    {
        $exception = InvalidGridValueException::forContainerMaxWidth(-100);

        self::assertSame('The configured container max width is invalid.', $exception->userMessage);
    }

    public function testForContainerMaxWidthDetailedMessageContainsValue(): void
    {
        $exception = InvalidGridValueException::forContainerMaxWidth(-100);

        self::assertStringContainsString('-100', $exception->getMessage());
    }

    public function testForColumnCountStatusCode(): void
    {
        $exception = InvalidGridValueException::forColumnCount('not-a-number');

        self::assertSame(422, $exception->statusCode);
    }

    public function testForColumnCountUserMessage(): void
    {
        $exception = InvalidGridValueException::forColumnCount('not-a-number');

        self::assertSame('The configured column count is invalid.', $exception->userMessage);
    }

    public function testForColumnCountDetailedMessageContainsVarExport(): void
    {
        $exception = InvalidGridValueException::forColumnCount('not-a-number');

        // var_export('not-a-number', true) produces: 'not-a-number'
        self::assertStringContainsString('not-a-number', $exception->getMessage());
    }

    public function testForColumnCountDetailedMessageContainsType(): void
    {
        $exception = InvalidGridValueException::forColumnCount(3.14);

        self::assertStringContainsString('float', $exception->getMessage());
    }

    public function testForOverrideStrategyStatusCode(): void
    {
        $exception = InvalidGridValueException::forOverrideStrategy('merge');

        self::assertSame(422, $exception->statusCode);
    }

    public function testForOverrideStrategyUserMessage(): void
    {
        $exception = InvalidGridValueException::forOverrideStrategy('merge');

        self::assertSame('The configured override strategy is invalid.', $exception->userMessage);
    }

    public function testForOverrideStrategyDetailedMessageContainsValue(): void
    {
        $exception = InvalidGridValueException::forOverrideStrategy('merge');

        self::assertStringContainsString('merge', $exception->getMessage());
    }
}

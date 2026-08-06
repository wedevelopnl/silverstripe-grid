<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeDevelop\Grid\Service\Transactional;
use WeDevelop\Grid\Value\Result;

#[CoversClass(Transactional::class)]
final class TransactionalTest extends TestCase
{
    public function testRunsOperationDirectlyWhenNoConnection(): void
    {
        $expected = Result::ok('done');
        $result = Transactional::run(static fn (): Result => $expected);

        self::assertSame($expected, $result);
    }

    public function testPropagatesExceptionFromOperationWhenNoConnection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        // Bound, never read: run() is #[\NoDiscard] and PHP 8.5 warns on a
        // discarded call even when it throws, which failOnWarning turns red.
        // The (void) cast the warning suggests is 8.5-only syntax and would
        // break the 8.3 floor, so bind instead.
        $neverReturned = Transactional::run(static function (): Result {
            throw new RuntimeException('boom');
        });
    }
}

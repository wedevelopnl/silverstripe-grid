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

        Transactional::run(static function (): Result {
            throw new RuntimeException('boom');
        });
    }
}

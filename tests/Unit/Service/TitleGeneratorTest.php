<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Service\TitleGenerator;

#[CoversClass(TitleGenerator::class)]
final class TitleGeneratorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titleProvider(): iterable
    {
        yield 'plain title gets copy suffix' => ['My Block', 'My Block copy'];
        yield 'copy title gets number 2' => ['My Block copy', 'My Block copy 2'];
        yield 'copy 3 increments to copy 4' => ['My Block copy 3', 'My Block copy 4'];
        yield 'copy 99 increments to copy 100' => ['My Block copy 99', 'My Block copy 100'];
        yield 'empty string gets copy suffix' => ['', ' copy'];
    }

    #[DataProvider('titleProvider')]
    public function testGenerateCopyTitle(string $input, string $expected): void
    {
        $this->assertSame($expected, TitleGenerator::generateCopyTitle($input));
    }
}

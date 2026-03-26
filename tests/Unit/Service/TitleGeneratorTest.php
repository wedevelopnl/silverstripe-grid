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
        yield 'copy 1 increments to copy 2' => ['My Block copy 1', 'My Block copy 2'];
        yield 'empty string gets copy suffix' => ['', ' copy'];
        yield 'copy embedded mid-string not treated as suffix' => ['My copy machine', 'My copy machine copy'];
        yield 'title ending with number but no copy' => ['Block 5', 'Block 5 copy'];
        yield 'copy followed by text not treated as suffix' => ['Block copy extra', 'Block copy extra copy'];
        yield 'copy without space prefix not matched' => ['Blockcopy', 'Blockcopy copy'];
        yield 'multiple copy patterns increments trailing' => ['Block copy copy', 'Block copy copy 2'];
    }

    #[DataProvider('titleProvider')]
    public function testGenerateCopyTitle(string $input, string $expected): void
    {
        $this->assertSame($expected, TitleGenerator::generateCopyTitle($input));
    }
}

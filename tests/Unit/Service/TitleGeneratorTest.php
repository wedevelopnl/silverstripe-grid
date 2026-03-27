<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Service\TitleGenerator;

#[CoversClass(TitleGenerator::class)]
final class TitleGeneratorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function copyTitleProvider(): iterable
    {
        yield 'plain title appends copy' => ['My Block', 'My Block copy'];
        yield 'copy suffix becomes copy 2' => ['My Block copy', 'My Block copy 2'];
        yield 'copy 2 increments to copy 3' => ['My Block copy 2', 'My Block copy 3'];
        yield 'copy 99 increments to copy 100' => ['My Block copy 99', 'My Block copy 100'];
        yield 'trailing number without copy appends copy' => ['Block 5', 'Block 5 copy'];
        yield 'word copy alone appends copy' => ['copy', 'copy copy'];
        yield 'double copy becomes copy 2' => ['A copy copy', 'A copy copy 2'];
        yield 'double copy 2 increments to copy 3' => ['A copy copy 2', 'A copy copy 3'];
        yield 'copy mid-string is not a suffix' => ['My copy world', 'My copy world copy'];
        yield 'trailing text after copy pattern' => ['Block copy 2 extra', 'Block copy 2 extra copy'];
    }

    #[Test]
    #[DataProvider('copyTitleProvider')]
    public function generateCopyTitleProducesExpectedResult(string $input, string $expected): void
    {
        self::assertSame($expected, TitleGenerator::generateCopyTitle($input));
    }
}

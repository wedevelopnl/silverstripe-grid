<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ElementStatus;

#[CoversClass(ElementStatus::class)]
final class ElementStatusTest extends TestCase
{
    private const array LABEL = ['text' => 'flag', 'title' => 'flag'];

    /**
     * @return array<string, array{array<string, array{text: string, title: string}>, ElementStatus}>
     */
    public static function fromStatusFlagsProvider(): array
    {
        return [
            'empty flags default to published' => [[], ElementStatus::Published],
            'addedtodraft flag yields draft' => [['addedtodraft' => self::LABEL], ElementStatus::Draft],
            'modified flag yields modified' => [['modified' => self::LABEL], ElementStatus::Modified],
            'removedfromdraft flag yields removed' => [['removedfromdraft' => self::LABEL], ElementStatus::Removed],
            'removed wins over draft and modified' => [
                [
                    'removedfromdraft' => self::LABEL,
                    'addedtodraft' => self::LABEL,
                    'modified' => self::LABEL,
                ],
                ElementStatus::Removed,
            ],
            'draft wins over modified' => [
                ['addedtodraft' => self::LABEL, 'modified' => self::LABEL],
                ElementStatus::Draft,
            ],
            'unknown flag keys collapse to published' => [
                ['scheduled' => self::LABEL, 'locked' => self::LABEL],
                ElementStatus::Published,
            ],
        ];
    }

    /**
     * @param array<string, array{text: string, title: string}> $flags
     */
    #[DataProvider('fromStatusFlagsProvider')]
    public function testFromStatusFlagsDerivesPublicationState(array $flags, ElementStatus $expected): void
    {
        self::assertSame($expected, ElementStatus::fromStatusFlags($flags));
    }
}

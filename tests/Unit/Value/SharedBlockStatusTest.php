<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ElementStatus;
use WeDevelop\Grid\Value\SharedBlockStatus;

#[CoversClass(SharedBlockStatus::class)]
final class SharedBlockStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, list<ElementStatus>, SharedBlockStatus}>
     */
    public static function computeProvider(): iterable
    {
        yield 'never published, subtree in sync' => [
            false,
            false,
            [ElementStatus::Published],
            SharedBlockStatus::NotPublished,
        ];

        yield 'never published outranks a clean subtree' => [
            false,
            false,
            [],
            SharedBlockStatus::NotPublished,
        ];

        yield 'published block with its own pending changes' => [
            true,
            true,
            [ElementStatus::Published],
            SharedBlockStatus::Modified,
        ];

        yield 'published block with a draft element in the subtree' => [
            true,
            false,
            [ElementStatus::Published, ElementStatus::Draft],
            SharedBlockStatus::Modified,
        ];

        yield 'published block with a modified element in the subtree' => [
            true,
            false,
            [ElementStatus::Modified],
            SharedBlockStatus::Modified,
        ];

        yield 'published block with an element removed from draft' => [
            true,
            false,
            [ElementStatus::Published, ElementStatus::Removed],
            SharedBlockStatus::Modified,
        ];

        yield 'published block fully in sync' => [
            true,
            false,
            [ElementStatus::Published, ElementStatus::Published],
            SharedBlockStatus::Published,
        ];

        yield 'published block with an empty subtree' => [
            true,
            false,
            [],
            SharedBlockStatus::Published,
        ];
    }

    /**
     * @param list<ElementStatus> $subtreeStatuses
     */
    #[DataProvider('computeProvider')]
    public function testCompute(
        bool $blockIsPublished,
        bool $blockStagesDiffer,
        array $subtreeStatuses,
        SharedBlockStatus $expected,
    ): void {
        self::assertSame(
            $expected,
            SharedBlockStatus::compute($blockIsPublished, $blockStagesDiffer, $subtreeStatuses),
        );
    }
}

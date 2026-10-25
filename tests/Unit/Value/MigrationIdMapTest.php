<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\MigrationIdMap;

#[CoversClass(MigrationIdMap::class)]
final class MigrationIdMapTest extends TestCase
{
    public function testRecordElementStoresAllThreeMaps(): void
    {
        $map = new MigrationIdMap();
        $map->recordElement(legacyId: 42, newElementId: 7, columnId: 3, draftSort: 1);

        self::assertTrue($map->hasElement(42));
        self::assertSame(7, $map->newElementId(42));
        self::assertSame(3, $map->newColumnId(42));
        self::assertSame(1, $map->draftSort(42));
    }

    public function testHasElementIsFalseForUnknownLegacyId(): void
    {
        self::assertFalse((new MigrationIdMap())->hasElement(99));
    }

    public function testRecordElementOverwritesExistingLegacyId(): void
    {
        $map = new MigrationIdMap();
        $map->recordElement(1, 10, 100, 1);
        $map->recordElement(1, 11, 101, 2);

        self::assertSame(11, $map->newElementId(1));
        self::assertSame(101, $map->newColumnId(1));
        self::assertSame(2, $map->draftSort(1));
    }

    public function testContainerPublishedTrackingIsIndependentOfElements(): void
    {
        $map = new MigrationIdMap();
        self::assertFalse($map->isContainerPublished(5));

        $map->markContainerPublished(5);
        self::assertTrue($map->isContainerPublished(5));
        self::assertFalse($map->hasElement(5));
    }

    public function testALegacyRowIsRecordedAgainstItsRowAndSectionSeparately(): void
    {
        $map = new MigrationIdMap();
        $map->recordRow(legacyRowId: 8, rowId: 20);
        $map->recordRow(legacyRowId: 9, rowId: 21);
        $map->recordSection(legacyRowId: 8, sectionId: 30);

        self::assertSame([20, 21], [$map->newRowId(8), $map->newRowId(9)]);
        self::assertSame(30, $map->newSectionId(8));
        self::assertNull($map->newSectionId(9));
        self::assertNull($map->newRowId(99));
    }
}

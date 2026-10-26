<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\FirstCopyRegistry;
use WeDevelop\Grid\Value\MigrationIdMap;

#[CoversClass(FirstCopyRegistry::class)]
final class FirstCopyRegistryTest extends TestCase
{
    public function testALegacyElementHasNoFirstCopyBeforeAnyPageCommitted(): void
    {
        self::assertNull((new FirstCopyRegistry())->firstCopy(42));
    }

    public function testACommittedPageRecordsEachElementItWrote(): void
    {
        $page = new MigrationIdMap();
        $page->recordElement(legacyId: 42, newElementId: 7, columnId: 3, draftSort: 1);
        $page->recordElement(legacyId: 43, newElementId: 9, columnId: 8, draftSort: 1);

        $registry = new FirstCopyRegistry();
        $registry->commit($page, 'nl_NL');

        self::assertSame(['id' => 7, 'locale' => 'nl_NL'], $registry->firstCopy(42));
        self::assertSame(['id' => 9, 'locale' => 'nl_NL'], $registry->firstCopy(43));
    }

    public function testTheFirstCommittedCopyWinsOverLaterOnes(): void
    {
        $pageA = new MigrationIdMap();
        $pageA->recordElement(legacyId: 42, newElementId: 7, columnId: 3, draftSort: 1);
        $pageB = new MigrationIdMap();
        $pageB->recordElement(legacyId: 42, newElementId: 12, columnId: 11, draftSort: 1);

        $registry = new FirstCopyRegistry();
        $registry->commit($pageA, null);
        $registry->commit($pageB, 'de_DE');

        self::assertSame(['id' => 7, 'locale' => null], $registry->firstCopy(42));
    }

    public function testAPageThatNeverCommittedLeavesNoEntry(): void
    {
        $rolledBack = new MigrationIdMap();
        $rolledBack->recordElement(legacyId: 42, newElementId: 7, columnId: 3, draftSort: 1);

        $registry = new FirstCopyRegistry();
        $committed = new MigrationIdMap();
        $committed->recordElement(legacyId: 50, newElementId: 8, columnId: 4, draftSort: 1);
        $registry->commit($committed, null);

        self::assertNull($registry->firstCopy(42));
    }
}

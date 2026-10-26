<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\Model\MigratedFirstCopy;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Value\MigrationIdMap;

#[CoversClass(MigratedFirstCopy::class)]
final class MigratedFirstCopyTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testALegacyElementNoPageWroteHasNoFirstCopy(): void
    {
        self::assertNull(MigratedFirstCopy::of(42));
    }

    public function testALaterPageDoesNotReplaceTheFirstCopy(): void
    {
        $first = $this->element();
        $later = $this->element();
        $other = $this->element();

        MigratedFirstCopy::record($this->page([42 => $first]), null);
        MigratedFirstCopy::record($this->page([42 => $later, 43 => $other]), null);

        self::assertSame($first, (int) MigratedFirstCopy::of(42)?->ID);
        self::assertSame($other, (int) MigratedFirstCopy::of(43)?->ID);
    }

    /**
     * @return positive-int
     */
    private function element(): int
    {
        /** @var positive-int */
        return ContentElement::create()->write();
    }

    /**
     * @param array<int, positive-int> $elementIds legacy element id → new element ID
     */
    private function page(array $elementIds): MigrationIdMap
    {
        $page = new MigrationIdMap();
        foreach ($elementIds as $legacyId => $elementId) {
            $page->recordElement($legacyId, $elementId, 1, 1);
        }

        return $page;
    }
}

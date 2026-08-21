<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(GridPageExtension::class)]
final class GridZoneTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableAutoScaffolding();

        Versioned::set_stage(Versioned::DRAFT);
    }

    private function populatedBlock(): SharedBlock
    {
        $block = GridTreeFactory::sharedBlock('Shared block');
        GridTreeFactory::section($block, zone: '', title: 'Shared section');

        return $block;
    }

    /** @return list<int> */
    private function zoneIds(Page $page, string $zone): array
    {
        return array_map(
            static fn (GridElement $element): int => (int) $element->ID,
            $page->GridZone($zone)->toArray(),
        );
    }

    public function testGridZoneMergesSectionsAndReferencesBySort(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $first = GridTreeFactory::section($page, zone: 'main', sort: 1, title: 'First');
        $reference = GridTreeFactory::reference($page, $this->populatedBlock(), zone: 'main', sort: 2);
        $third = GridTreeFactory::section($page, zone: 'main', sort: 3, title: 'Third');

        self::assertSame(
            [(int) $first->ID, (int) $reference->ID, (int) $third->ID],
            $this->zoneIds($page, 'main'),
        );
    }

    public function testGridZoneFiltersByZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $mainSection = GridTreeFactory::section($page, zone: 'main', sort: 1);
        GridTreeFactory::section($page, zone: 'sidebar', sort: 1);
        $sidebarReference = GridTreeFactory::reference($page, $this->populatedBlock(), zone: 'sidebar', sort: 2);

        self::assertSame([(int) $mainSection->ID], $this->zoneIds($page, 'main'));
        self::assertContains((int) $sidebarReference->ID, $this->zoneIds($page, 'sidebar'));
        self::assertNotContains((int) $sidebarReference->ID, $this->zoneIds($page, 'main'));
    }

    public function testGridZoneIsEmptyForAnUnusedZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page, zone: 'main');

        self::assertSame([], $this->zoneIds($page, 'footer'));
    }

    public function testGridZoneScopesToItsOwnPage(): void
    {
        $pageA = $this->objFromFixture(Page::class, 'test_page');
        $pageB = $this->objFromFixture(Page::class, 'test_page_2');

        $onA = GridTreeFactory::section($pageA, zone: 'main');
        GridTreeFactory::reference($pageB, $this->populatedBlock(), zone: 'main');

        self::assertSame([(int) $onA->ID], $this->zoneIds($pageA, 'main'));
    }

    public function testSectionsRelationStillReturnsOnlySections(): void
    {
        // Regression: $Sections stays a Section-only relation for backwards
        // compatibility, so it must never start yielding references.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main', sort: 1);
        GridTreeFactory::reference($page, $this->populatedBlock(), zone: 'main', sort: 2);

        $sections = $page->Sections()->toArray();

        self::assertCount(1, $sections);
        self::assertSame((int) $section->ID, (int) $sections[0]->ID);
    }

    public function testGridRootsRelationCoversBothRootClasses(): void
    {
        // The single OWNED relation: ownership must not be split across two
        // has_many relations to the same polymorphic Parent.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main', sort: 1);
        $reference = GridTreeFactory::reference($page, $this->populatedBlock(), zone: 'main', sort: 2);

        $rootIds = array_map(
            static fn (GridElement $element): int => (int) $element->ID,
            $page->GridRoots()->toArray(),
        );

        self::assertContains((int) $section->ID, $rootIds);
        self::assertContains((int) $reference->ID, $rootIds);
    }

    public function testPagePublishCarriesAPlacement(): void
    {
        // Regression for the ownership split: a placement is page content and
        // must reach live with its page.
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $reference = GridTreeFactory::reference($page, $this->populatedBlock(), zone: 'main');

        $page->publishRecursive();

        Versioned::set_stage(Versioned::LIVE);
        self::assertNotNull(SharedBlockReference::get()->byID($reference->ID));
    }

    public function testPagePublishStillCarriesASection(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');

        $page->publishRecursive();

        Versioned::set_stage(Versioned::LIVE);
        self::assertNotNull(Section::get()->byID($section->ID));
    }
}

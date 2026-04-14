<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridElementService;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\ContainerType;

#[CoversClass(GridElementService::class)]
final class GridElementServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private GridElementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        $this->service = new GridElementService();
    }

    // ─── createElement ──────────────────────────────────────────

    public function testCreateSectionUnderPage(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        $result = $this->service->createElement($page, ContainerType::Section, 'main', null);

        self::assertTrue($result->isOk());

        $section = $result->unwrap();
        self::assertInstanceOf(Section::class, $section);
        self::assertSame((int) $page->ID, $section->ParentID);
        self::assertSame(SiteTree::class, $section->ParentClass);
        self::assertSame('main', $section->Zone);
    }

    public function testCreateRowUnderSection(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $result = $this->service->createElement($section, ContainerType::Row, 'main', null);

        self::assertTrue($result->isOk());

        $row = $result->unwrap();
        self::assertInstanceOf(Row::class, $row);
        self::assertSame((int) $section->ID, $row->ParentID);
        self::assertSame(Section::class, $row->ParentClass);
    }

    public function testCreateElementInsertAfterSibling(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);

        $result = $this->service->createElement($section, ContainerType::Row, 'main', $row1->ID);

        self::assertTrue($result->isOk());

        $newRow = $result->unwrap();

        // Reload to verify persisted sort order
        $row1 = GridElement::get()->byID($row1->ID);
        $newRow = GridElement::get()->byID($newRow->ID);
        $row2 = GridElement::get()->byID($row2->ID);

        self::assertGreaterThan($row1->Sort, $newRow->Sort);
        self::assertGreaterThan($newRow->Sort, $row2->Sort);
    }

    // ─── createContentElement ───────────────────────────────────

    public function testCreateContentElementUnderColumn(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $result = $this->service->createContentElement($column, ContentElement::class, null);

        self::assertTrue($result->isOk());

        $element = $result->unwrap();
        self::assertInstanceOf(ContentElement::class, $element);
        self::assertSame((int) $column->ID, $element->ParentID);
        self::assertSame(Column::class, $element->ParentClass);
    }

    public function testCreateContentElementInsertAfterSibling(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $existing1 = GridTreeFactory::contentElement($column, title: 'First');
        $existing2 = GridTreeFactory::contentElement($column, title: 'Second');

        $result = $this->service->createContentElement($column, ContentElement::class, $existing1->ID);

        self::assertTrue($result->isOk());

        $newElement = $result->unwrap();

        $existing1 = GridElement::get()->byID($existing1->ID);
        $newElement = GridElement::get()->byID($newElement->ID);
        $existing2 = GridElement::get()->byID($existing2->ID);

        self::assertGreaterThan($existing1->Sort, $newElement->Sort);
        self::assertGreaterThan($newElement->Sort, $existing2->Sort);
    }

    // ─── duplicateElement ───────────────────────────────────────

    public function testDuplicateElementCreatesShallowCopyWithCopyTitle(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'My Section');

        $result = $this->service->duplicateElement($section);

        self::assertTrue($result->isOk());

        $clone = $result->unwrap();
        self::assertSame('My Section copy', $clone->Title);
        self::assertSame($section->ParentID, $clone->ParentID);
        self::assertSame($section->ParentClass, $clone->ParentClass);
        self::assertNotSame((int) $section->ID, (int) $clone->ID);
    }

    public function testDuplicateElementInsertsAfterOriginal(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section, title: 'Row 1');
        $row2 = GridTreeFactory::row($section, title: 'Row 2');

        $result = $this->service->duplicateElement($row1);

        self::assertTrue($result->isOk());

        $clone = $result->unwrap();

        $row1 = GridElement::get()->byID($row1->ID);
        $clone = GridElement::get()->byID($clone->ID);
        $row2 = GridElement::get()->byID($row2->ID);

        self::assertGreaterThan($row1->Sort, $clone->Sort);
        self::assertGreaterThan($clone->Sort, $row2->Sort);
    }

    // ─── duplicateElementTo ─────────────────────────────────────

    public function testDuplicateElementToAnotherPage(): void
    {
        $page1 = $this->objFromFixture(SiteTree::class, 'test_page');
        $page2 = $this->objFromFixture(SiteTree::class, 'test_page_2');
        $section = GridTreeFactory::section($page1, title: 'Original');

        $result = $this->service->duplicateElementTo(
            $section,
            $page2,
            (int) $page2->ID,
            'main',
        );

        self::assertTrue($result->isOk());

        $clone = $result->unwrap();
        self::assertInstanceOf(Section::class, $clone);
        self::assertSame('Original copy', $clone->Title);
        self::assertSame((int) $page2->ID, $clone->ParentID);
        self::assertSame('main', $clone->Zone);
        self::assertNotSame((int) $section->ID, (int) $clone->ID);
    }

    public function testDuplicateRowToAnotherSection(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $sectionA = GridTreeFactory::section($page);
        $sectionB = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($sectionA, title: 'Original Row');

        $result = $this->service->duplicateElementTo(
            $row,
            $sectionB,
            (int) $page->ID,
            'main',
        );

        self::assertTrue($result->isOk());

        $clone = $result->unwrap();
        self::assertInstanceOf(Row::class, $clone);
        self::assertSame('Original Row copy', $clone->Title);
        self::assertSame((int) $sectionB->ID, $clone->ParentID);
        self::assertSame(Section::class, $clone->ParentClass);
    }

    public function testDuplicateElementToFailsWhenTargetParentNotOnClaimedPage(): void
    {
        $page1 = $this->objFromFixture(SiteTree::class, 'test_page');
        $page2 = $this->objFromFixture(SiteTree::class, 'test_page_2');

        $sectionOnPage1 = GridTreeFactory::section($page1);
        $rowOnPage1 = GridTreeFactory::row($sectionOnPage1, title: 'Row');

        $result = $this->service->duplicateElementTo(
            $rowOnPage1,
            $sectionOnPage1,
            (int) $page2->ID,
            'main',
        );

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testDuplicateElementToFailsWhenTargetParentNotInClaimedZone(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');
        $row = GridTreeFactory::row($section, title: 'Row');

        $result = $this->service->duplicateElementTo(
            $row,
            $section,
            (int) $page->ID,
            'sidebar',
        );

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testDuplicateElementToFailsWhenHierarchyViolated(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $rowToCopy = GridTreeFactory::row($section, title: 'Rogue Row');

        $result = $this->service->duplicateElementTo(
            $rowToCopy,
            $column,
            (int) $page->ID,
            'main',
        );

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testDuplicateElementToFailsWhenSectionTargetParentMismatchesPageId(): void
    {
        $page1 = $this->objFromFixture(SiteTree::class, 'test_page');
        $page2 = $this->objFromFixture(SiteTree::class, 'test_page_2');
        $section = GridTreeFactory::section($page1, title: 'Section');

        $result = $this->service->duplicateElementTo(
            $section,
            $page1,
            (int) $page2->ID,
            'main',
        );

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testViolationErrorHasTranslationKey(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $rowToCopy = GridTreeFactory::row($section, title: 'Rogue Row');

        // Row inside Column violates hierarchy — triggers HIERARCHY_REJECTED
        $result = $this->service->duplicateElementTo(
            $rowToCopy,
            $column,
            (int) $page->ID,
            'main',
        );

        self::assertTrue($result->isErr());

        $error = $result->errors()[0];
        self::assertSame(GridElementService::class . '.HIERARCHY_REJECTED', $error->key);
        self::assertArrayHasKey('element', $error->params);
        self::assertArrayHasKey('parent', $error->params);
    }
}

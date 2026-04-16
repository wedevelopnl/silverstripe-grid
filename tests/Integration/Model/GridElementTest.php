<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use SilverStripe\VersionedAdmin\Forms\HistoryViewerField;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(GridElement::class)]
final class GridElementTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    // ── Sort assignment ─────────────────────────────────────────

    public function testEnsureSortSetAssignsSequentialSort(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $first = GridTreeFactory::contentElement($column);
        $second = GridTreeFactory::contentElement($column);

        self::assertSame(1, $first->Sort);
        self::assertSame(2, $second->Sort);
    }

    public function testEnsureSortSetPreservesExplicitSort(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = GridTreeFactory::contentElement($column, sort: 42);

        self::assertSame(42, $element->Sort);
    }

    // ── Default title ───────────────────────────────────────────

    public function testEnsureDefaultTitleWhenEmpty(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = GridTreeFactory::contentElement($column);

        self::assertStringContainsString('Content element', $element->Title);
        self::assertStringContainsString('1', $element->Title);
    }

    public function testEnsureDefaultTitlePreservesExplicit(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = GridTreeFactory::contentElement($column, title: 'My Custom Title');

        self::assertSame('My Custom Title', $element->Title);
    }

    public function testEnsureDefaultTitleCountsOnlySameTypeSiblings(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $first = GridTreeFactory::contentElement($column);
        $second = GridTreeFactory::contentElement($column);

        self::assertStringContainsString('1', $first->Title);
        self::assertStringContainsString('2', $second->Title);
    }

    // ── insertAfterSibling ──────────────────────────────────────

    public function testInsertAfterSiblingBumpsSort(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $a = GridTreeFactory::contentElement($column, title: 'A');
        $b = GridTreeFactory::contentElement($column, title: 'B');
        $c = GridTreeFactory::contentElement($column, title: 'C');

        // Create a new element and insert after A
        $inserted = ContentElement::create();
        $inserted->Title = 'Inserted';
        $inserted->ParentID = $column->ID;
        $inserted->ParentClass = $column::class;
        $inserted->write();
        $inserted->insertAfterSibling($a->ID);

        // Reload all from DB
        $a = ContentElement::get()->byID($a->ID);
        $b = ContentElement::get()->byID($b->ID);
        $c = ContentElement::get()->byID($c->ID);
        $inserted = ContentElement::get()->byID($inserted->ID);

        self::assertSame(1, $a->Sort);
        self::assertSame(2, $inserted->Sort);
        self::assertSame(3, $b->Sort);
        self::assertSame(4, $c->Sort);
        self::assertSame('Inserted', $inserted->Title);
    }

    public function testInsertAfterSiblingNonexistentReference(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = GridTreeFactory::contentElement($column);
        $originalSort = $element->Sort;

        $element->insertAfterSibling(999999);

        $reloaded = ContentElement::get()->byID($element->ID);
        self::assertSame($originalSort, $reloaded->Sort);
    }

    // ── getPage() ───────────────────────────────────────────────

    public function testGetPageWalksParentChain(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $leaf = GridTreeFactory::contentElement($column);

        $result = $leaf->getPage();

        self::assertInstanceOf(SiteTree::class, $result);
        self::assertSame((int) $page->ID, (int) $result->ID);
    }

    public function testGetPageFromOrphan(): void
    {
        $element = ContentElement::create();
        // Not written, no parent

        self::assertNull($element->getPage());
    }

    // ── Permissions ─────────────────────────────────────────────

    public function testCanViewDelegatesToPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertTrue($section->canView());
    }

    public function testCanEditDelegatesToPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertTrue($section->canEdit());
    }

    public function testCanDeleteDelegatesToPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertTrue($section->canDelete());
    }

    public function testCanCreateChecksCmsAccess(): void
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        self::assertTrue(ContentElement::singleton()->canCreate());

        $this->logOut();
        self::assertFalse(ContentElement::singleton()->canCreate());
    }

    // ── Simple class name ───────────────────────────────────────

    public function testGetSimpleClassNameReturnsShortName(): void
    {
        $section = Section::create();

        self::assertSame('Section', $section->getSimpleClassName());
    }

    // ── CMS edit link ───────────────────────────────────────────

    public function testGetCMSEditLinkWithPage(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $contentElement = GridTreeFactory::contentElement($column);

        $link = $contentElement->getCMSEditLink();

        self::assertNotNull($link);
        self::assertStringContainsString((string) $contentElement->ID, $link);
        self::assertStringContainsString('item/' . $contentElement->ID, $link);
    }

    public function testGetCMSEditLinkOrphanReturnsNull(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        // Orphan via raw SQL to bypass polymorphic has_one validation
        DB::query(sprintf(
            "UPDATE \"GridElement\" SET \"ParentID\" = 0, \"ParentClass\" = '' WHERE \"ID\" = %d",
            $element->ID,
        ));

        // Reload from DB to pick up the orphaned state
        $element = ContentElement::get()->byID($element->ID);

        self::assertNull($element->getCMSEditLink());
    }

    // ── Anchor ──────────────────────────────────────────────────

    public function testGetAnchorContainsElementId(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        self::assertSame('grid-element-' . $element->ID, $element->getAnchor());
    }

    // ── Type name ───────────────────────────────────────────────

    public function testGetTypeNameReplacesBackslashes(): void
    {
        $element = ContentElement::create();
        $typeName = $element->getTypeName();

        self::assertStringNotContainsString('\\', $typeName);
        self::assertStringContainsString('ContentElement', $typeName);
    }

    // ── Block schema ────────────────────────────────────────────

    public function testGetBlockSchemaReturnsExpectedKeys(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column, title: 'My Block');

        $schema = $element->getBlockSchema();

        self::assertArrayHasKey('id', $schema);
        self::assertArrayHasKey('typeName', $schema);
        self::assertArrayHasKey('type', $schema);
        self::assertArrayHasKey('title', $schema);
        self::assertSame($element->ID, $schema['id']);
        self::assertSame('My Block', $schema['title']);
        self::assertSame('Content element', $schema['type']);
        self::assertStringNotContainsString('\\', $schema['typeName']);
    }

    // ── Title size class ────────────────────────────────────────

    public function testGetTitleSizeClassReturnsStoredValue(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = ContentElement::create();
        $element->Title = 'Test';
        $element->TitleClass = 'display-3';
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        self::assertSame('display-3', $element->getTitleSizeClass());
    }

    public function testGetTitleSizeClassReturnsEmptyWhenNotSet(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        self::assertSame('', $element->getTitleSizeClass());
    }

    // ── getCMSFields ────────────────────────────────────────────

    public function testGetCMSFieldsContainsTitleGroup(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $fields = $element->getCMSFields();

        self::assertNotNull($fields->fieldByName('Root.Main.TitleSettings'));
    }

    public function testGetCMSFieldsContainsHistoryViewerFieldWhenSaved(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $fields = $element->getCMSFields();

        self::assertInstanceOf(
            HistoryViewerField::class,
            $fields->fieldByName('Root.History.ElementHistory'),
        );
    }

    public function testGetCMSFieldsOmitsHistoryViewerFieldWhenUnsaved(): void
    {
        $element = ContentElement::create();

        $fields = $element->getCMSFields();

        self::assertNull($fields->fieldByName('Root.History.ElementHistory'));
    }

    public function testGetCMSFieldsExcludesScaffoldedFields(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $fields = $element->getCMSFields();

        self::assertNull($fields->dataFieldByName('Sort'));
        self::assertNull($fields->dataFieldByName('ParentID'));
        self::assertNull($fields->dataFieldByName('ExtraClass'));
        self::assertNull($fields->dataFieldByName('Style'));
        self::assertNull($fields->dataFieldByName('ParentClass'));
    }

    public function testGetCMSFieldsIncludesTitleClassWhenEnabled(): void
    {
        Config::modify()->set(GridElement::class, 'enable_custom_title_classes', true);

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $fields = $element->getCMSFields();

        self::assertNotNull($fields->dataFieldByName('TitleClass'));
    }

    public function testGetCMSFieldsExcludesTitleClassWhenDisabled(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $fields = $element->getCMSFields();

        self::assertNull($fields->dataFieldByName('TitleClass'));
    }

    // ── Orphan permission fallback ──────────────────────────────

    public function testCanViewFallsBackToPermissionCheckForOrphan(): void
    {
        $element = $this->createOrphanElement();

        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        self::assertTrue($element->canView());

        $this->logOut();
        self::assertFalse($element->canView());
    }

    public function testCanEditFallsBackToPermissionCheckForOrphan(): void
    {
        $element = $this->createOrphanElement();

        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        self::assertTrue($element->canEdit());

        $this->logOut();
        self::assertFalse($element->canEdit());
    }

    public function testCanDeleteFallsBackToPermissionCheckForOrphan(): void
    {
        $element = $this->createOrphanElement();

        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        self::assertTrue($element->canDelete());

        $this->logOut();
        self::assertFalse($element->canDelete());
    }

    // ── Sort guard boundary ────────────────────────────────────

    public function testEnsureSortSetGuardPreservesPositiveSort(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = GridTreeFactory::contentElement($column, sort: 5);

        // Reload from DB to confirm persistence
        $reloaded = ContentElement::get()->byID($element->ID);
        self::assertSame(5, $reloaded->Sort);
    }

    // ── getType ─────────────────────────────────────────────────

    public function testGetTypeReturnsConfiguredSingularName(): void
    {
        $section = Section::create();
        self::assertSame('Section', $section->getType());

        $content = ContentElement::create();
        self::assertSame('Content element', $content->getType());
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Creates a persisted ContentElement then orphans it via raw SQL
     * to bypass SS6's polymorphic has_one validation.
     */
    private function createOrphanElement(): ContentElement
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        DB::query(sprintf(
            "UPDATE \"GridElement\" SET \"ParentID\" = 0, \"ParentClass\" = '' WHERE \"ID\" = %d",
            $element->ID,
        ));

        $reloaded = ContentElement::get()->byID($element->ID);
        self::assertInstanceOf(ContentElement::class, $reloaded);

        return $reloaded;
    }

    // ── forTemplate ─────────────────────────────────────────────

    public function testForTemplateReturnsString(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $result = $element->forTemplate();

        self::assertIsString($result);
    }
}

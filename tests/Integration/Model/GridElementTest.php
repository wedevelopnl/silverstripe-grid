<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use SilverStripe\VersionedAdmin\Forms\HistoryViewerField;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\CustomSchemaContentElement;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\PermissionDenyingPage;

#[CoversClass(GridElement::class)]
final class GridElementTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<class-string> */
    protected static $extra_dataobjects = [
        CustomSchemaContentElement::class,
        PermissionDenyingPage::class,
    ];

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
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $first = GridTreeFactory::contentElement($column);
        $second = GridTreeFactory::contentElement($column);

        self::assertSame(1, $first->Sort);
        self::assertSame(2, $second->Sort);
    }

    public function testEnsureSortSetSpansMixedElementClassesInAColumn(): void
    {
        // Sort is one sequence across ALL element classes under a parent. Regression
        // for the late-static-binding bug: static::get() scoped the max to the
        // element's own subclass, so the first element of a NEW type computed max
        // over an empty set and collided at Sort 1 with an existing sibling.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $first = GridTreeFactory::contentElement($column);

        $second = CustomSchemaContentElement::create();
        $second->ParentID = (int) $column->ID;
        $second->ParentClass = $column::class;
        $second->write();

        self::assertSame(1, $first->Sort);
        self::assertSame(
            2,
            $second->Sort,
            'A new element type must append after existing siblings, not collide at Sort 1',
        );
    }

    public function testEnsureSortSetPreservesExplicitSort(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = GridTreeFactory::contentElement($column, sort: 42);

        self::assertSame(42, $element->Sort);
    }

    // ── Default title ───────────────────────────────────────────

    public function testEnsureDefaultTitleWhenEmpty(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = GridTreeFactory::contentElement($column);

        self::assertStringContainsString('Content element', $element->Title);
        self::assertStringContainsString('1', $element->Title);
    }

    public function testEnsureDefaultTitlePreservesExplicit(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = GridTreeFactory::contentElement($column, title: 'My Custom Title');

        self::assertSame('My Custom Title', $element->Title);
    }

    public function testEnsureDefaultTitleCountsOnlySameTypeSiblings(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $first = GridTreeFactory::contentElement($column);
        $second = GridTreeFactory::contentElement($column);

        self::assertStringContainsString('1', $first->Title);
        self::assertStringContainsString('2', $second->Title);
    }

    public function testEnsureDefaultTitleAvoidsDuplicateAfterDeletion(): void
    {
        // Numbering must use the highest existing suffix, not the sibling count:
        // deleting an earlier element must not make the next one reuse a title.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $first = GridTreeFactory::contentElement($column, title: '');
        $second = GridTreeFactory::contentElement($column, title: '');
        self::assertStringContainsString('1', $first->Title);
        self::assertStringContainsString('2', $second->Title);

        $first->delete();

        // One sibling remains ("… 2"); the next element must be "… 3", not "… 2".
        $third = GridTreeFactory::contentElement($column, title: '');
        self::assertStringContainsString('3', $third->Title);
        self::assertNotSame($second->Title, $third->Title);
    }

    // ── Polymorphic parent-ID isolation ─────────────────────────────
    // Pin the `'ParentID' => $this->ParentID` filters in ensureSortSet and
    // ensureDefaultTitle. Without that key, sibling queries would return
    // elements from *every* parent of the same class — a correctness bug
    // hidden by tests that only use a single parent. The equivalent
    // isolation guarantee for placement is covered in
    // ElementPlacementServiceTest::testInsertAfterBumpsOnlySameParentSiblings.

    public function testEnsureSortSetIsolatedPerParent(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Two columns under same row, each a distinct parent for content elements
        $columnA = GridTreeFactory::column($row);
        $columnB = GridTreeFactory::column($row);

        // Column B has 3 elements, Column A has 1
        GridTreeFactory::contentElement($columnB, title: 'B1');
        GridTreeFactory::contentElement($columnB, title: 'B2');
        GridTreeFactory::contentElement($columnB, title: 'B3');
        $a1 = GridTreeFactory::contentElement($columnA, title: 'A1');
        self::assertSame(1, $a1->Sort, 'Column A sibling count is 0 → new element Sort=1');

        // Adding another under A must continue from A's own max (=1), not B's (=3)
        $a2 = GridTreeFactory::contentElement($columnA, title: 'A2');
        self::assertSame(
            2,
            $a2->Sort,
            'Sort must be scoped to Column A; removing ParentID from the filter would yield 4',
        );
    }

    public function testEnsureDefaultTitleCountsOnlySameParentSiblings(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $columnA = GridTreeFactory::column($row);
        $columnB = GridTreeFactory::column($row);

        // Column B already has 5 unnamed content elements
        for ($i = 0; $i < 5; ++$i) {
            GridTreeFactory::contentElement($columnB, title: '');
        }

        // First default-titled element under Column A should be "Content element 1"
        // (NOT "Content element 6" which would happen if the filter ignored ParentID)
        $a1 = GridTreeFactory::contentElement($columnA, title: '');
        self::assertStringContainsString('1', $a1->Title);
        self::assertStringNotContainsString('6', $a1->Title);
    }

    public function testEnsureDefaultTitleExcludesSelfOnResave(): void
    {
        // Pins the `->exclude(['ID' => $this->ID])` in ensureDefaultTitle: without the
        // self-exclusion, a re-save of a title-cleared element would count itself
        // among siblings, off-by-one in the generated title.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $e1 = GridTreeFactory::contentElement($column, title: '');
        self::assertStringContainsString('1', $e1->Title);

        // Clear the title and re-save: sibling count is 0 (itself excluded) → still "1"
        $e1->Title = '';
        $e1->write();

        self::assertStringContainsString('1', $e1->Title);
        self::assertStringNotContainsString('2', $e1->Title);
    }

    // ── getPage() ───────────────────────────────────────────────

    public function testGetPageWalksParentChain(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
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

    public function testGetPageReturnsNullWhenParentDoesNotExist(): void
    {
        // Pins the `!$parent->exists()` guard: a parent reference pointing at a
        // non-existent record must resolve to null, not be walked as a real page.
        // The mutant that removes the early `return null;` would fall through.
        $element = ContentElement::create();
        $element->ParentClass = Section::class;
        $element->ParentID = 999999;

        self::assertNull($element->getPage());
    }

    // ── Permissions ─────────────────────────────────────────────

    public function testCanViewDelegatesToPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertTrue($section->canView());
    }

    public function testCanEditDelegatesToPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertTrue($section->canEdit());
    }

    public function testCanDeleteDelegatesToPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertTrue($section->canDelete());
    }

    /**
     * Pins the page delegation in canView/canEdit/canDelete: when the element's
     * owning page DENIES the permission, the element must report false even
     * though the member holds CMS access (which the orphan fallback would grant).
     * A mutant that skips the `$page->can*()` delegation and falls through to the
     * `Permission::check('CMS_ACCESS', ...)` fallback would return true.
     *
     * @param 'canView'|'canEdit'|'canDelete' $method
     */
    #[DataProvider('pageDenyingPermissionProvider')]
    public function testCanPermissionFollowsDenyingPage(string $method): void
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $page = PermissionDenyingPage::create();
        $page->Title = 'Denied';
        $page->write();

        $section = GridTreeFactory::section($page);

        self::assertFalse(
            $section->{$method}(),
            sprintf('%s must follow the denying page, not the CMS_ACCESS fallback', $method),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pageDenyingPermissionProvider(): iterable
    {
        yield 'canView' => ['canView'];
        yield 'canEdit' => ['canEdit'];
        yield 'canDelete' => ['canDelete'];
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
        $page = $this->objFromFixture(Page::class, 'test_page');
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
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        // Orphan via raw SQL to bypass polymorphic has_one validation
        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            "UPDATE \"%s\" SET \"ParentID\" = 0, \"ParentClass\" = '' WHERE \"ID\" = %d",
            $table,
            $element->ID,
        ));

        // Reload from DB to pick up the orphaned state
        $element = ContentElement::get()->byID($element->ID);

        self::assertNull($element->getCMSEditLink());
    }

    // ── Anchor ──────────────────────────────────────────────────

    public function testGetAnchorContainsElementId(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
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
        $page = $this->objFromFixture(Page::class, 'test_page');
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

    public function testGetBlockSchemaMergesSubclassProvidedKeys(): void
    {
        // Pins the array_merge in getBlockSchema: a mutant that drops the merged
        // map (returning only the base schema) loses the subclass key.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = CustomSchemaContentElement::create();
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        $schema = $element->getBlockSchema();

        self::assertArrayHasKey('custom', $schema);
        self::assertSame('x', $schema['custom']);
        // Base keys must remain present alongside the merged ones.
        self::assertArrayHasKey('id', $schema);
        self::assertArrayHasKey('type', $schema);
    }

    // ── Title size class ────────────────────────────────────────

    public function testGetTitleSizeClassReturnsStoredValue(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
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
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        self::assertSame('', $element->getTitleSizeClass());
    }

    // ── getCMSFields ────────────────────────────────────────────

    public function testGetCMSFieldsContainsTitleGroup(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $fields = $element->getCMSFields();

        self::assertNotNull($fields->fieldByName('Root.Main.TitleSettings'));
    }

    public function testGetCMSFieldsContainsHistoryViewerFieldWhenSaved(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
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
        $page = $this->objFromFixture(Page::class, 'test_page');
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

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $fields = $element->getCMSFields();

        self::assertNotNull($fields->dataFieldByName('TitleClass'));
    }

    public function testGetCMSFieldsExcludesTitleClassWhenDisabled(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
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
        $page = $this->objFromFixture(Page::class, 'test_page');
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
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            "UPDATE \"%s\" SET \"ParentID\" = 0, \"ParentClass\" = '' WHERE \"ID\" = %d",
            $table,
            $element->ID,
        ));

        $reloaded = ContentElement::get()->byID($element->ID);
        self::assertInstanceOf(ContentElement::class, $reloaded);

        return $reloaded;
    }

    // ── forTemplate ─────────────────────────────────────────────

    public function testForTemplateReturnsString(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        $result = $element->forTemplate();

        self::assertIsString($result);
    }

    // ── getHolderClasses ────────────────────────────────────────
    // ContentElement uses the base provideHolderClasses() ([]), so the holder
    // classes derive solely from Style + ExtraClass.

    public function testGetHolderClassesEmptyWhenNoSourceClasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $element = GridTreeFactory::contentElement($column);

        self::assertSame('', $element->getHolderClasses());
    }

    public function testGetHolderClassesIncludesStyleAndExtraClass(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = ContentElement::create();
        $element->Title = 'Has classes';
        $element->Style = 'bg-light';
        $element->ExtraClass = 'mt-3';
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        $classes = $element->getHolderClasses();

        // Order is Style then ExtraClass.
        self::assertSame('bg-light mt-3', $classes);
    }

    public function testGetHolderClassesFiltersEmptyParts(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        // Style set, ExtraClass empty — the empty part must be filtered so there
        // is no leading/trailing/doubled separator.
        $element = ContentElement::create();
        $element->Title = 'Only style';
        $element->Style = 'shadow';
        $element->ExtraClass = '';
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        self::assertSame('shadow', $element->getHolderClasses());
    }

    // ── Leaf write does not scaffold children ───────────────────

    public function testWritingContentElementDoesNotScaffoldChildren(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $countBefore = GridElement::get()->count();

        GridTreeFactory::contentElement($column, title: 'Leaf');

        $countAfter = GridElement::get()->count();

        self::assertSame(
            $countBefore + 1,
            $countAfter,
            'writing a leaf content element must create exactly one record — no scaffold children',
        );
    }

    public function testWritingLeafShortCircuitsBeforeContainerScaffolding(): void
    {
        // Pins the `!$this instanceof ContainerInterface` early return in
        // onAfterWrite. With auto_scaffold enabled on the leaf class, removing
        // that return would let onAfterWrite reach getContainerType() — a method
        // ContentElement does not have — raising an Error and failing the write.
        // The leaf guard must short-circuit first, so the write succeeds and
        // produces exactly one record.
        Config::modify()->set(ContentElement::class, 'auto_scaffold', true);

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $countBefore = GridElement::get()->count();

        // Reaches getContainerType() and raises an Error if the leaf guard is removed.
        $element = GridTreeFactory::contentElement($column, title: 'Leaf');

        self::assertTrue($element->isInDB());
        self::assertSame(
            $countBefore + 1,
            GridElement::get()->count(),
            'leaf write must succeed and create exactly one record despite auto_scaffold being enabled',
        );
    }
}

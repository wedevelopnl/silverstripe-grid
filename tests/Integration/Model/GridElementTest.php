<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

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
}

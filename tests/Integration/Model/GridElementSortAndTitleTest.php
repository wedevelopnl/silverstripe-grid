<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;

#[CoversClass(GridElement::class)]
#[CoversMethod(GridElement::class, 'ensureSortSet')]
#[CoversMethod(GridElement::class, 'ensureDefaultTitle')]
#[CoversMethod(GridElement::class, 'insertAfterSibling')]
#[CoversMethod(Section::class, 'ensureSortSet')]
final class GridElementSortAndTitleTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** @var list<class-string> */
    protected static $extra_dataobjects = [
        TestPage::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        TestPage::class => [
            GridPageExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    private function createPage(string $title = 'Test'): TestPage
    {
        $page = TestPage::create();
        $page->Title = $title;
        $page->write();

        return $page;
    }

    private function createSection(TestPage $page, string $zone = 'main', string $title = ''): Section
    {
        $section = Section::create();
        $section->Title = $title;
        $section->Zone = $zone;
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        return $section;
    }

    private function createRow(Section $section, string $title = ''): Row
    {
        $row = Row::create();
        $row->Title = $title;
        $row->ParentID = $section->ID;
        $row->ParentClass = $section::class;
        $row->write();

        return $row;
    }

    // --- ensureSortSet (GridElement) ---

    public function testEnsureSortSetAssignsOneWhenNoSiblings(): void
    {
        $page = $this->createPage();
        $section = $this->createSection($page);

        $row = $this->createRow($section);

        $this->assertSame(1, $row->Sort);
    }

    public function testEnsureSortSetAssignsNextSortAfterMaxSibling(): void
    {
        $page = $this->createPage();
        $section = $this->createSection($page);

        $row1 = $this->createRow($section);
        $row2 = $this->createRow($section);

        $this->assertSame(1, $row1->Sort);
        $this->assertSame(2, $row2->Sort);
    }

    public function testEnsureSortSetPreservesExplicitPositiveSort(): void
    {
        $page = $this->createPage();
        $section = $this->createSection($page);

        $row = Row::create();
        $row->Title = 'Explicit Sort';
        $row->Sort = 5;
        $row->ParentID = $section->ID;
        $row->ParentClass = $section::class;
        $row->write();

        $this->assertSame(5, $row->Sort);
    }

    public function testEnsureSortSetFiltersByParentId(): void
    {
        $page = $this->createPage();
        $sectionA = $this->createSection($page);
        $sectionB = $this->createSection($page);

        $rowA = $this->createRow($sectionA);
        $rowB = $this->createRow($sectionB);

        // Each row is first in its parent, so both get Sort=1
        $this->assertSame(1, $rowA->Sort);
        $this->assertSame(1, $rowB->Sort);
    }

    // --- ensureSortSet (Section — zone-aware) ---

    public function testSectionEnsureSortSetFiltersByZone(): void
    {
        $page = $this->createPage();

        $mainSection = $this->createSection($page, 'main');
        $sidebarSection = $this->createSection($page, 'sidebar');

        // Independent zones → both get Sort=1
        $this->assertSame(1, $mainSection->Sort);
        $this->assertSame(1, $sidebarSection->Sort);
    }

    public function testSectionEnsureSortSetAssignsNextSortInSameZone(): void
    {
        $page = $this->createPage();

        $section1 = $this->createSection($page, 'main');
        $section2 = $this->createSection($page, 'main');

        $this->assertSame(1, $section1->Sort);
        $this->assertSame(2, $section2->Sort);
    }

    // --- ensureDefaultTitle ---

    public function testDefaultTitleGeneratedWhenEmpty(): void
    {
        $page = $this->createPage();
        $section = $this->createSection($page);

        $row = $this->createRow($section);

        $this->assertStringContainsString('Row', $row->Title);
        $this->assertStringContainsString('1', $row->Title);
    }

    public function testExplicitTitlePreserved(): void
    {
        $page = $this->createPage();
        $section = $this->createSection($page);

        $row = $this->createRow($section, 'My Custom Row');

        $this->assertSame('My Custom Row', $row->Title);
    }

    public function testDefaultTitleCountReflectsSiblings(): void
    {
        $page = $this->createPage();
        $section = $this->createSection($page);

        $row1 = $this->createRow($section);
        $row2 = $this->createRow($section);

        // Second row gets count=2 (1 existing sibling + 1)
        $this->assertStringContainsString('2', $row2->Title);
        // Verify they're different
        $this->assertNotSame($row1->Title, $row2->Title);
    }

    public function testDefaultTitleCountIndependentPerParent(): void
    {
        $page = $this->createPage();
        $sectionA = $this->createSection($page);
        $sectionB = $this->createSection($page);

        $rowA = $this->createRow($sectionA);
        $rowB = $this->createRow($sectionB);

        // Both are first in their parent → both get count=1
        $this->assertSame($rowA->Title, $rowB->Title);
    }

    // --- insertAfterSibling ---

    public function testInsertAfterSiblingBumpsSubsequentSortValues(): void
    {
        $page = $this->createPage();
        $section = $this->createSection($page);

        $row1 = $this->createRow($section);
        $row2 = $this->createRow($section);
        $row3 = $this->createRow($section);

        $this->assertSame(1, $row1->Sort);
        $this->assertSame(2, $row2->Sort);
        $this->assertSame(3, $row3->Sort);

        // Create a new column inside section's first row (need a sibling context)
        $newRow = Row::create();
        $newRow->Title = 'Inserted';
        $newRow->ParentID = $section->ID;
        $newRow->ParentClass = $section::class;
        $newRow->write();

        // Insert after row1 — should push row2 and row3 down
        $newRow->insertAfterSibling($row1->ID);

        /** @var Row $row1 */
        $row1 = Row::get()->byID($row1->ID);
        /** @var Row $row2 */
        $row2 = Row::get()->byID($row2->ID);
        /** @var Row $row3 */
        $row3 = Row::get()->byID($row3->ID);
        /** @var Row $newRow */
        $newRow = Row::get()->byID($newRow->ID);

        $this->assertSame(1, $row1->Sort);
        $this->assertSame(2, $newRow->Sort);
        $this->assertSame(3, $row2->Sort);
        $this->assertSame(4, $row3->Sort);
    }

    public function testInsertAfterNonExistentSiblingIsNoOp(): void
    {
        $page = $this->createPage();
        $section = $this->createSection($page);

        $row = $this->createRow($section);
        $originalSort = $row->Sort;

        $row->insertAfterSibling(999999);

        /** @var Row $row */
        $row = Row::get()->byID($row->ID);
        $this->assertSame($originalSort, $row->Sort);
    }
}

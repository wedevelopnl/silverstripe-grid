<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Repository\OrmGridElementRepository;
use WeDevelop\Grid\Service\ReorderService;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;
use WeDevelop\Grid\Validation\ReorderValidator;

#[CoversClass(ReorderService::class)]
final class ReorderServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** @var list<class-string> */
    protected static $extra_dataobjects = [
        TestPage::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        TestPage::class => [GridPageExtension::class],
    ];

    private ReorderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->service = new ReorderService(
            new ReorderValidator(),
            new OrmGridElementRepository(),
        );
    }

    public function testSameParentReorderMovesRowToFirstPosition(): void
    {
        $section = Section::create();
        $section->write();

        // Auto-scaffolded Row + Column
        $row1 = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row1);

        $row2 = Row::create();
        $row2->ParentID = $section->ID;
        $row2->ParentClass = $section::class;
        $row2->write();

        $row3 = Row::create();
        $row3->ParentID = $section->ID;
        $row3->ParentClass = $section::class;
        $row3->write();

        // Move row3 to first position (before row1)
        $result = $this->service->reorder($row3, $section, null);

        $this->assertTrue($result->isOk());
        $this->assertSame($row3->ID, $result->unwrap()->ID);

        // Reload from DB to verify persistence
        /** @var Row $row1 */
        $row1 = Row::get()->byID($row1->ID);
        /** @var Row $row2 */
        $row2 = Row::get()->byID($row2->ID);
        /** @var Row $row3 */
        $row3 = Row::get()->byID($row3->ID);

        $this->assertSame(1, $row3->Sort);
        $this->assertSame(2, $row1->Sort);
        $this->assertSame(3, $row2->Sort);
    }

    public function testCrossParentReorderMovesColumnBetweenRows(): void
    {
        $section = Section::create();
        $section->write();

        $row1 = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row1);

        // row1 has auto-scaffolded column1
        $column1 = $row1->getChildren()->first();
        $this->assertInstanceOf(Column::class, $column1);

        // Add a second column to row1
        $column2 = Column::create();
        $column2->ParentID = $row1->ID;
        $column2->ParentClass = $row1::class;
        $column2->write();

        // Create row2 with its auto-scaffolded column
        $row2 = Row::create();
        $row2->ParentID = $section->ID;
        $row2->ParentClass = $section::class;
        $row2->write();

        $targetColumn = $row2->getChildren()->first();
        $this->assertInstanceOf(Column::class, $targetColumn);

        // Move column2 from row1 to row2, after targetColumn
        $result = $this->service->reorder($column2, $row2, $targetColumn->ID);

        $this->assertTrue($result->isOk());

        // Reload from DB
        /** @var Column $column2 */
        $column2 = Column::get()->byID($column2->ID);

        $this->assertSame($row2->ID, $column2->ParentID);
        $this->assertSame($row2::class, $column2->ParentClass);
        $this->assertSame(2, $column2->Sort);

        // Source row still has column1
        /** @var Column $column1 */
        $column1 = Column::get()->byID($column1->ID);
        $this->assertSame($row1->ID, $column1->ParentID);
        $this->assertSame(1, $column1->Sort);
    }

    public function testCrossParentValidationFailureRejectsRowToPageLevel(): void
    {
        $page = TestPage::create();
        $page->write();

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        // Row cannot be placed at page level (can_be_root = false)
        $result = $this->service->reorder($row, $page, null);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('cannot be placed at page level', $result->errors()[0]->message);

        // Verify row was NOT moved
        /** @var Row $row */
        $row = Row::get()->byID($row->ID);
        $this->assertSame($section->ID, $row->ParentID);
    }

    public function testAfterElementNotFoundReturnsError(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $result = $this->service->reorder($row, $section, 999999);

        $this->assertTrue($result->isErr());
        $this->assertSame('afterElementID', $result->errors()[0]->field);
    }

    public function testSamePositionReorderIsNoOp(): void
    {
        $section = Section::create();
        $section->write();

        $row1 = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row1);

        $row2 = Row::create();
        $row2->ParentID = $section->ID;
        $row2->ParentClass = $section::class;
        $row2->write();

        $originalSort1 = $row1->Sort;
        $originalSort2 = $row2->Sort;

        // Move row2 after row1 — already in that position
        $result = $this->service->reorder($row2, $section, $row1->ID);

        $this->assertTrue($result->isOk());

        // Reload and verify no Sort changes
        /** @var Row $row1 */
        $row1 = Row::get()->byID($row1->ID);
        /** @var Row $row2 */
        $row2 = Row::get()->byID($row2->ID);

        $this->assertSame($originalSort1, $row1->Sort);
        $this->assertSame($originalSort2, $row2->Sort);
    }

    public function testSameZoneSectionReorderFiltersByZone(): void
    {
        $page = TestPage::create();
        $page->Title = 'Test';
        $page->write();

        // Create 2 sections in 'main' zone and 1 in 'sidebar' zone
        $main1 = Section::create();
        $main1->ParentID = $page->ID;
        $main1->ParentClass = $page::class;
        $main1->Zone = 'main';
        $main1->write();

        $main2 = Section::create();
        $main2->ParentID = $page->ID;
        $main2->ParentClass = $page::class;
        $main2->Zone = 'main';
        $main2->write();

        $sidebar1 = Section::create();
        $sidebar1->ParentID = $page->ID;
        $sidebar1->ParentClass = $page::class;
        $sidebar1->Zone = 'sidebar';
        $sidebar1->write();

        // Reorder main1 after main2 — should only affect 'main' zone siblings
        $result = $this->service->reorder($main1, $page, $main2->ID);

        $this->assertTrue($result->isOk());

        // Reload and verify
        /** @var Section $main1 */
        $main1 = Section::get()->byID($main1->ID);
        /** @var Section $main2 */
        $main2 = Section::get()->byID($main2->ID);
        /** @var Section $sidebar1 */
        $sidebar1 = Section::get()->byID($sidebar1->ID);

        $this->assertSame(1, $main2->Sort);
        $this->assertSame(2, $main1->Sort);
        // Sidebar section unaffected
        $this->assertSame(1, $sidebar1->Sort);
    }
}

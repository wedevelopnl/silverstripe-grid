<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;
use WeDevelop\Grid\Validation\HierarchyValidationService;
use WeDevelop\Grid\Validation\HierarchyValidatorInterface;

#[CoversClass(HierarchyValidationService::class)]
final class HierarchyValidationServiceTest extends SapphireTest
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

    private HierarchyValidatorInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->service = Injector::inst()->get(HierarchyValidatorInterface::class);
    }

    public function testOrphanElementReturnsOk(): void
    {
        $section = Section::create();
        $section->write();

        $result = $this->service->validate($section);

        $this->assertTrue($result->isOk());
    }

    public function testSectionOnPageReturnsOk(): void
    {
        $page = TestPage::create();
        $page->write();

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        $result = $this->service->validate($section);

        $this->assertTrue($result->isOk());
    }

    public function testRowOnPageReturnsFail(): void
    {
        $page = TestPage::create();
        $page->write();

        $row = Row::create();
        $row->ParentID = $page->ID;
        $row->ParentClass = $page::class;

        $result = $this->service->validate($row);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('cannot be placed at page level', $result->errors()[0]->message);
    }

    public function testColumnOnPageReturnsFail(): void
    {
        $page = TestPage::create();
        $page->write();

        $column = Column::create();
        $column->ParentID = $page->ID;
        $column->ParentClass = $page::class;

        $result = $this->service->validate($column);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('cannot be placed at page level', $result->errors()[0]->message);
    }

    public function testRowInsideSectionReturnsOk(): void
    {
        $section = Section::create();
        $section->write();

        $row = Row::create();
        $row->ParentID = $section->ID;
        $row->ParentClass = $section::class;

        $result = $this->service->validate($row);

        $this->assertTrue($result->isOk());
    }

    public function testColumnInsideSectionReturnsFail(): void
    {
        $section = Section::create();
        $section->write();

        $column = Column::create();
        $column->ParentID = $section->ID;
        $column->ParentClass = $section::class;

        $result = $this->service->validate($column);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('cannot be placed inside', $result->errors()[0]->message);
    }

    public function testColumnInsideRowReturnsOk(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $column = Column::create();
        $column->ParentID = $row->ID;
        $column->ParentClass = $row::class;

        $result = $this->service->validate($column);

        $this->assertTrue($result->isOk());
    }

    public function testSectionInsideColumnReturnsFail(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $column = $row->getChildren()->first();
        $this->assertInstanceOf(Column::class, $column);

        $nested = Section::create();
        $nested->ParentID = $column->ID;
        $nested->ParentClass = $column::class;

        $result = $this->service->validate($nested);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('cannot be placed inside', $result->errors()[0]->message);
    }

    public function testRowInsideColumnReturnsFail(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $column = $row->getChildren()->first();
        $this->assertInstanceOf(Column::class, $column);

        $nested = Row::create();
        $nested->ParentID = $column->ID;
        $nested->ParentClass = $column::class;

        $result = $this->service->validate($nested);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('cannot be placed inside', $result->errors()[0]->message);
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;
use WeDevelop\Grid\Validation\ReorderValidator;

#[CoversClass(ReorderValidator::class)]
final class ReorderValidatorTest extends SapphireTest
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

    private ReorderValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->validator = new ReorderValidator();
    }

    public function testSameParentMoveReturnsOk(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $result = $this->validator->validate($row, $section);

        $this->assertTrue($result->isOk());
    }

    public function testCrossParentValidMoveReturnsOk(): void
    {
        $sectionA = Section::create();
        $sectionA->write();

        $sectionB = Section::create();
        $sectionB->write();

        $rowA = $sectionA->getChildren()->first();
        $this->assertInstanceOf(Row::class, $rowA);

        $columnA = $rowA->getChildren()->first();
        $this->assertInstanceOf(Column::class, $columnA);

        $rowB = $sectionB->getChildren()->first();
        $this->assertInstanceOf(Row::class, $rowB);

        // Column from Row A to Row B — valid move
        $result = $this->validator->validate($columnA, $rowB);

        $this->assertTrue($result->isOk());
    }

    public function testCrossParentInvalidCanBeRootReturnsFail(): void
    {
        $page = TestPage::create();
        $page->write();

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        // Row to page — invalid, can_be_root is false
        $result = $this->validator->validate($row, $page);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('cannot be placed at page level', $result->errors()[0]->message);
    }

    public function testCrossParentInvalidDisallowedReturnsFail(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $column = $row->getChildren()->first();
        $this->assertInstanceOf(Column::class, $column);

        // Section into Column — disallowed
        $nestedSection = Section::create();
        $nestedSection->write();

        $result = $this->validator->validate($nestedSection, $column);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('cannot be placed inside', $result->errors()[0]->message);
    }
}

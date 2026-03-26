<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;

#[CoversClass(GridElement::class)]
final class GridElementCmsEditLinkTest extends SapphireTest
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
    }

    public function testEditLinkRoutesThroughPageEditor(): void
    {
        $page = TestPage::create();
        $page->Title = 'Breadcrumb Test';
        $page->write();

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        $editLink = $section->getCMSEditLink();

        $this->assertNotNull($editLink);
        $this->assertStringContainsString('admin/pages/edit/EditForm', $editLink);
        $this->assertStringContainsString((string) $page->ID, $editLink);
        $this->assertStringContainsString('field/GridEditor/item/' . $section->ID . '/edit', $editLink);
    }

    public function testEditLinkReturnsNullForOrphanedElement(): void
    {
        $section = Section::create();
        $section->Title = 'Orphan';
        $section->write();

        $this->assertNull($section->getCMSEditLink());
    }

    public function testNestedElementEditLinkResolvesToOwningPage(): void
    {
        $page = TestPage::create();
        $page->Title = 'Nested Test';
        $page->write();

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        // Auto-scaffolding creates Row → Column
        $row = $section->getChildren()->first();
        $this->assertNotNull($row, 'Section should auto-scaffold a Row');

        $column = $row->getChildren()->first();
        $this->assertNotNull($column, 'Row should auto-scaffold a Column');

        $editLink = $column->getCMSEditLink();

        $this->assertNotNull($editLink);
        $this->assertStringContainsString((string) $page->ID, $editLink);
        $this->assertStringContainsString('field/GridEditor/item/' . $column->ID . '/edit', $editLink);
    }
}

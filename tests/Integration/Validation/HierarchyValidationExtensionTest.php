<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;
use WeDevelop\Grid\Validation\HierarchyValidationExtension;

#[CoversClass(HierarchyValidationExtension::class)]
final class HierarchyValidationExtensionTest extends SapphireTest
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

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testValidWriteSucceeds(): void
    {
        $page = TestPage::create();
        $page->write();

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;

        // Should not throw — Section is allowed at page level
        $section->write();

        $this->assertGreaterThan(0, $section->ID);
    }

    public function testInvalidWriteThrowsValidationException(): void
    {
        $page = TestPage::create();
        $page->write();

        $row = Row::create();
        $row->ParentID = $page->ID;
        $row->ParentClass = $page::class;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot be placed at page level');

        $row->write();
    }

    public function testOrphanWriteSucceeds(): void
    {
        $section = Section::create();

        // No parent set — should write without exception
        $section->write();

        $this->assertGreaterThan(0, $section->ID);
    }
}

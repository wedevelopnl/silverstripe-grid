<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Validation\HierarchyValidationExtension;

#[CoversClass(HierarchyValidationExtension::class)]
final class HierarchyValidationExtensionTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    public function testValidWriteSucceeds(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Section at page level is valid — write should not throw
        $section = GridTreeFactory::section($page);

        self::assertGreaterThan(0, $section->ID);
    }

    public function testInvalidWriteThrowsValidationException(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        $this->expectException(ValidationException::class);

        // Row at page level violates can_be_root: false
        $row = Row::create();
        $row->ParentID = $page->ID;
        $row->ParentClass = $page::class;
        $row->write();
    }
}

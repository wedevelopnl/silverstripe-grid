<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Dev\MultiZonePage;
use WeDevelop\Grid\Forms\GridEditorField;

#[CoversClass(MultiZonePage::class)]
final class MultiZonePageTest extends SapphireTest
{
    /** Every test writes a MultiZonePage, so SapphireTest must provision the temp DB. */
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testGetCMSFieldsHasMainZoneEditor(): void
    {
        $page = MultiZonePage::create();
        $page->Title = 'Test';
        $page->write();

        $fields = $page->getCMSFields();

        self::assertNotNull($fields->dataFieldByName('GridEditorMain'));
        self::assertInstanceOf(GridEditorField::class, $fields->dataFieldByName('GridEditorMain'));
    }

    public function testGetCMSFieldsHasSidebarZoneEditor(): void
    {
        $page = MultiZonePage::create();
        $page->Title = 'Test';
        $page->write();

        $fields = $page->getCMSFields();

        self::assertNotNull($fields->dataFieldByName('GridEditorSidebar'));
        self::assertInstanceOf(GridEditorField::class, $fields->dataFieldByName('GridEditorSidebar'));
    }

    public function testGetCMSFieldsRemovesDefaultContent(): void
    {
        $page = MultiZonePage::create();
        $page->Title = 'Test';
        $page->write();

        $fields = $page->getCMSFields();

        self::assertNull($fields->dataFieldByName('Content'));
        self::assertNull($fields->dataFieldByName('GridEditor'));
    }
}

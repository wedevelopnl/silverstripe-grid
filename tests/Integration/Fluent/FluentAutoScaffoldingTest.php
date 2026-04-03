<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

final class FluentAutoScaffoldingTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/locales.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $extra_extensions = [
        GridElement::class => [FluentIsolatedExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        // Default to English for each test
        $locale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($locale->Locale);
    }

    /**
     * Auto-scaffolded Row and Column children must inherit the LocaleID
     * that was active when the parent Section was written.
     *
     * Writing a Section in English triggers Section → Row → Column scaffolding.
     * All three nodes must carry the same English LocaleID.
     */
    public function testAutoScaffoldedChildrenInheritLocale(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $english = $this->objFromFixture(Locale::class, 'en');
        $expectedLocaleId = (int) $english->ID;

        $section = Section::create();
        $section->Title = 'Auto-scaffold Section';
        $section->Zone = 'main';
        $section->Sort = 0;
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        // Section itself must be assigned to English
        self::assertSame($expectedLocaleId, (int) $section->LocaleID);

        // Auto-scaffolded Row must inherit English
        $row = $section->getChildren()->first();
        self::assertInstanceOf(Row::class, $row);
        self::assertSame($expectedLocaleId, (int) $row->LocaleID);

        // Auto-scaffolded Column must inherit English
        $column = $row->getChildren()->first();
        self::assertInstanceOf(Column::class, $column);
        self::assertSame($expectedLocaleId, (int) $column->LocaleID);
    }

    /**
     * Auto-scaffolded children written in English must be invisible when
     * querying in Dutch.
     *
     * After creating a full Section → Row → Column tree in English, switching
     * to Dutch and querying by the page's ParentID must return zero results
     * for all three levels.
     */
    public function testAutoScaffoldedChildrenScopedToLocale(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        $section = Section::create();
        $section->Title = 'Scoped Section';
        $section->Zone = 'main';
        $section->Sort = 0;
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        // Confirm the tree was scaffolded correctly in English
        self::assertCount(1, $section->getChildren());
        $row = $section->getChildren()->first();
        self::assertInstanceOf(Row::class, $row);
        self::assertCount(1, $row->getChildren());

        // Switch to Dutch
        $dutch = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutch->Locale);

        // No sections visible for this page in Dutch
        $dutchSections = Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => $page::class,
        ]);
        self::assertCount(0, $dutchSections);

        // No rows visible for this section in Dutch
        $dutchRows = Row::get()->filter([
            'ParentID' => $section->ID,
            'ParentClass' => $section::class,
        ]);
        self::assertCount(0, $dutchRows);

        // No columns visible for the scaffolded row in Dutch
        $dutchColumns = Column::get()->filter([
            'ParentID' => $row->ID,
            'ParentClass' => $row::class,
        ]);
        self::assertCount(0, $dutchColumns);
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\Service\CopyToLocaleService;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Verifies that copy-to-locale duplicates the full grid hierarchy
 * (Section → Row → Column → Content) into the target locale.
 *
 * Pages are created manually (not via fixture) because FluentExtension
 * needs an active FluentState during write to create localised records.
 */
final class FluentCopyToLocaleTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/locales.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [FluentIsolatedExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        Locale::clearCached();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        FluentState::singleton()->setLocale('en_US');
    }

    private function createPage(string $title = 'Test Page'): SiteTree
    {
        $page = SiteTree::create();
        $page->Title = $title;
        $page->URLSegment = 'fluent-copy-test';
        $page->writeToStage(Versioned::DRAFT);

        return $page;
    }

    /**
     * When a page is copied to a new locale via CopyToLocaleService,
     * the entire grid hierarchy must be duplicated into the target locale.
     */
    public function testCopyDuplicatesFullGridTree(): void
    {
        $page = $this->createPage();

        // Build a full hierarchy in English
        $enSection = GridTreeFactory::section($page, title: 'Hero Section');
        $enRow = GridTreeFactory::row($enSection, title: 'Hero Row');
        $enCol = GridTreeFactory::column($enRow);
        GridTreeFactory::contentElement($enCol, title: 'Hero Content');

        // Copy page to Dutch
        CopyToLocaleService::singleton()->copyToLocale(
            SiteTree::class,
            (int) $page->ID,
            'en_US',
            'nl_NL',
        );

        // Switch to Dutch and verify the grid was copied
        FluentState::singleton()->setLocale('nl_NL');

        $nlSections = Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => SiteTree::class,
        ]);
        self::assertCount(1, $nlSections, 'Dutch should have one Section after copy');

        /** @var Section $nlSection */
        $nlSection = $nlSections->first();
        self::assertSame('Hero Section', $nlSection->Title);
        self::assertNotSame((int) $enSection->ID, (int) $nlSection->ID, 'Should be a new record');

        // Verify Row
        $nlRows = Row::get()->filter([
            'ParentID' => $nlSection->ID,
            'ParentClass' => Section::class,
        ]);
        self::assertCount(1, $nlRows, 'Dutch Section should have one Row');

        // Verify Column
        /** @var Row $nlRow */
        $nlRow = $nlRows->first();
        $nlColumns = Column::get()->filter([
            'ParentID' => $nlRow->ID,
            'ParentClass' => Row::class,
        ]);
        self::assertCount(1, $nlColumns, 'Dutch Row should have one Column');

        // Verify ContentElement
        /** @var Column $nlCol */
        $nlCol = $nlColumns->first();
        $nlContent = ContentElement::get()->filter([
            'ParentID' => $nlCol->ID,
            'ParentClass' => Column::class,
        ]);
        self::assertCount(1, $nlContent, 'Dutch Column should have one ContentElement');
        self::assertSame('Hero Content', $nlContent->first()->Title);

        // Verify English tree is unchanged
        FluentState::singleton()->setLocale('en_US');
        self::assertCount(1, Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => SiteTree::class,
        ]), 'English should still have exactly one Section');
    }

    /**
     * Copy should handle multiple zones — both 'main' and 'sidebar'
     * sections should be duplicated.
     */
    public function testCopyDuplicatesMultipleZones(): void
    {
        $page = $this->createPage();

        $mainSection = GridTreeFactory::section($page, zone: 'main', title: 'Main Section');
        GridTreeFactory::row($mainSection);

        $sidebarSection = GridTreeFactory::section($page, zone: 'sidebar', title: 'Sidebar Section');
        GridTreeFactory::row($sidebarSection);

        // Copy to Dutch
        CopyToLocaleService::singleton()->copyToLocale(
            SiteTree::class,
            (int) $page->ID,
            'en_US',
            'nl_NL',
        );

        FluentState::singleton()->setLocale('nl_NL');

        $nlSections = Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => SiteTree::class,
        ]);
        self::assertCount(2, $nlSections, 'Dutch should have both zones');

        $zones = $nlSections->column('Zone');
        self::assertContains('main', $zones);
        self::assertContains('sidebar', $zones);
    }
}

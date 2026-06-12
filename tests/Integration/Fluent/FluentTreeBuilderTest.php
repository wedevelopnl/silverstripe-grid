<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridTreeBuilder;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\ContainerType;

/**
 * Verifies that GridTreeBuilder correctly batch-loads, maps parent keys,
 * and assembles recursive trees when Fluent locale filtering is active.
 */
#[CoversClass(GridTreeBuilder::class)]
final class FluentTreeBuilderTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/locales.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [FluentIsolatedExtension::class],
    ];

    private GridTreeBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        // Clear cached locale records so fixture-loaded locales are visible
        Locale::clearCached();

        $locale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($locale->Locale);

        $this->builder = Injector::inst()->get(GridTreeBuilder::class);
    }

    private function createPage(string $title = 'Test Page'): SiteTree
    {
        $page = SiteTree::create();
        $page->Title = $title;
        $page->URLSegment = 'fluent-tree-builder-test';
        $page->writeToStage(Versioned::DRAFT);

        return $page;
    }

    /**
     * Build full-depth hierarchies in two locales and verify the tree builder
     * returns the correct multi-level tree for each. Asserts at every level:
     * section titles, row count, column count, content element titles.
     */
    public function testFullDepthTreePerLocale(): void
    {
        $page = $this->createPage();

        // English: Section → Row → Column → ContentElement
        $enSection = GridTreeFactory::section($page, title: 'EN Section');
        $enRow = GridTreeFactory::row($enSection, title: 'EN Row');
        $enCol = GridTreeFactory::column($enRow);
        GridTreeFactory::contentElement($enCol, title: 'EN Content');

        // Dutch: Section → Row → 2 Columns (different structure)
        $dutch = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutch->Locale);

        $nlSection = GridTreeFactory::section($page, title: 'NL Section');
        $nlRow = GridTreeFactory::row($nlSection, title: 'NL Row');
        GridTreeFactory::column($nlRow);
        GridTreeFactory::column($nlRow);

        // Verify Dutch tree — full depth
        $nlTree = $this->builder->buildForPage($page, 'main');
        self::assertArrayHasKey($page->ID, $nlTree);

        $nlSections = $nlTree[$page->ID];
        self::assertCount(1, $nlSections);
        self::assertSame('NL Section', $nlSections[0]->title);
        self::assertSame(ContainerType::Section, $nlSections[0]->containerType);

        $nlRows = $nlSections[0]->children;
        self::assertNotNull($nlRows);
        self::assertCount(1, $nlRows);
        self::assertSame('NL Row', $nlRows[0]->title);
        self::assertSame(ContainerType::Row, $nlRows[0]->containerType);

        $nlColumns = $nlRows[0]->children;
        self::assertNotNull($nlColumns);
        self::assertCount(2, $nlColumns, 'Dutch row should have 2 columns');

        // Verify English tree — full depth
        $english = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($english->Locale);

        $enTree = $this->builder->buildForPage($page, 'main');
        $enSections = $enTree[$page->ID];
        self::assertCount(1, $enSections);
        self::assertSame('EN Section', $enSections[0]->title);

        $enRows = $enSections[0]->children;
        self::assertNotNull($enRows);
        self::assertCount(1, $enRows);

        $enColumns = $enRows[0]->children;
        self::assertNotNull($enColumns);
        self::assertCount(1, $enColumns, 'English row should have 1 column');

        $enContent = $enColumns[0]->children;
        self::assertNotNull($enContent);
        self::assertCount(1, $enContent);
        self::assertSame('EN Content', $enContent[0]->title);
    }

    /**
     * Multiple zones in one locale should each return correct data,
     * while the other locale returns empty for both zones.
     */
    public function testMultiZoneTreePerLocale(): void
    {
        $page = $this->createPage();

        // English: elements in both 'main' and 'sidebar' zones
        $mainSection = GridTreeFactory::section($page, zone: 'main', title: 'EN Main');
        $mainRow = GridTreeFactory::row($mainSection);
        GridTreeFactory::column($mainRow);

        $sidebarSection = GridTreeFactory::section($page, zone: 'sidebar', title: 'EN Sidebar');
        $sidebarRow = GridTreeFactory::row($sidebarSection);
        GridTreeFactory::column($sidebarRow);

        // English main zone
        $mainTree = $this->builder->buildForPage($page, 'main');
        self::assertArrayHasKey($page->ID, $mainTree);
        self::assertCount(1, $mainTree[$page->ID]);
        self::assertSame('EN Main', $mainTree[$page->ID][0]->title);

        // English sidebar zone
        $sidebarTree = $this->builder->buildForPage($page, 'sidebar');
        self::assertArrayHasKey($page->ID, $sidebarTree);
        self::assertCount(1, $sidebarTree[$page->ID]);
        self::assertSame('EN Sidebar', $sidebarTree[$page->ID][0]->title);

        // Dutch: both zones should be empty
        $dutch = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutch->Locale);

        $nlMain = $this->builder->buildForPage($page, 'main');
        $hasSections = isset($nlMain[$page->ID]) && count($nlMain[$page->ID]) > 0;
        self::assertFalse($hasSections, 'Dutch main zone should have no sections');

        $nlSidebar = $this->builder->buildForPage($page, 'sidebar');
        $hasSections = isset($nlSidebar[$page->ID]) && count($nlSidebar[$page->ID]) > 0;
        self::assertFalse($hasSections, 'Dutch sidebar zone should have no sections');
    }
}

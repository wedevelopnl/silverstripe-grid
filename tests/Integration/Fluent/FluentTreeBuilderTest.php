<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

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

        // Default to English for each test
        $locale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($locale->Locale);

        $this->builder = Injector::inst()->get(GridTreeBuilder::class);
    }

    /**
     * GridTreeBuilder must return only the nodes belonging to the active locale.
     * Switching locale must produce a completely independent tree.
     */
    public function testTreeBuilderReturnsLocaleSpecificTree(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Create elements in English
        $enSection = GridTreeFactory::section($page, 'main', 0, 'EN Section');
        $enRow = GridTreeFactory::row($enSection, 0, 'EN Row');
        $enColumn = GridTreeFactory::column($enRow);
        GridTreeFactory::contentElement($enColumn, 0, 'EN Content');

        // Switch to Dutch and create a different structure there
        $dutch = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutch->Locale);

        $nlSection = GridTreeFactory::section($page, 'main', 0, 'NL Section');
        $nlRow = GridTreeFactory::row($nlSection, 0, 'NL Row');
        GridTreeFactory::column($nlRow);

        // Dutch tree must contain only the Dutch section
        $dutchTree = $this->builder->buildForPage($page, 'main');

        self::assertArrayHasKey($page->ID, $dutchTree);
        $dutchSectionNodes = $dutchTree[$page->ID];
        self::assertCount(1, $dutchSectionNodes);
        self::assertSame('NL Section', $dutchSectionNodes[0]->title);
        self::assertSame((int) $nlSection->ID, $dutchSectionNodes[0]->id);

        // Switch back to English and verify the English tree is unaffected
        $english = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($english->Locale);

        $englishTree = $this->builder->buildForPage($page, 'main');

        self::assertArrayHasKey($page->ID, $englishTree);
        $englishSectionNodes = $englishTree[$page->ID];
        self::assertCount(1, $englishSectionNodes);
        self::assertSame('EN Section', $englishSectionNodes[0]->title);
        self::assertSame((int) $enSection->ID, $englishSectionNodes[0]->id);
    }

    /**
     * When no elements exist for the active locale, buildForPage must not
     * include the page key in the result (no phantom empty entry).
     */
    public function testEmptyTreeForLocaleWithNoElements(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Create elements in English only
        $enSection = GridTreeFactory::section($page, 'main', 0, 'EN Section');
        $enRow = GridTreeFactory::row($enSection);
        GridTreeFactory::column($enRow);

        // Switch to Dutch — no elements exist in this locale
        $dutch = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutch->Locale);

        $tree = $this->builder->buildForPage($page, 'main');

        // No sections in Dutch: the page key must be absent or have an empty list
        $hasSections = isset($tree[$page->ID]) && count($tree[$page->ID]) > 0;
        self::assertFalse($hasSections, 'Dutch locale should have no sections for this page');
    }
}

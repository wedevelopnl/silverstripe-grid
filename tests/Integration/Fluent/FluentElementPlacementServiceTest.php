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
use WeDevelop\Grid\Service\ElementPlacementService;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Verifies that ElementPlacementService correctly reorders elements within a locale
 * without cross-locale interference when Fluent locale filtering is active.
 */
final class FluentElementPlacementServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/locales.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [FluentIsolatedExtension::class],
    ];

    private ElementPlacementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        // Clear cached locale records so fixture-loaded locales are visible
        Locale::clearCached();

        $locale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($locale->Locale);

        $this->service = Injector::inst()->get(ElementPlacementService::class);
    }

    private function createPage(string $title = 'Test Page'): SiteTree
    {
        $page = SiteTree::create();
        $page->Title = $title;
        $page->URLSegment = 'fluent-reorder-test';
        $page->writeToStage(Versioned::DRAFT);

        return $page;
    }

    /**
     * Reordering sections within a single locale must correctly recalculate
     * Sort values, just as it does without Fluent.
     */
    public function testReorderWithinLocalePreservesSort(): void
    {
        $page = $this->createPage();

        $section1 = GridTreeFactory::section($page, title: 'First');
        $section2 = GridTreeFactory::section($page, title: 'Second');
        $section3 = GridTreeFactory::section($page, title: 'Third');

        // Move section3 to the front
        $result = $this->service->reorder($section3, $page, null);
        self::assertTrue($result->isOk());

        // Reload and verify new order
        $section1 = GridElement::get()->byID($section1->ID);
        $section2 = GridElement::get()->byID($section2->ID);
        $section3 = GridElement::get()->byID($section3->ID);

        self::assertSame(1, (int) $section3->Sort, 'Third should now be first');
        self::assertSame(2, (int) $section1->Sort, 'First should now be second');
        self::assertSame(3, (int) $section2->Sort, 'Second should now be third');
    }

    /**
     * Reordering elements in one locale must not affect the Sort order
     * of elements in a different locale for the same page and zone.
     */
    public function testReorderInOneLocaleDoesNotAffectAnother(): void
    {
        $page = $this->createPage();

        // English sections
        $enSection1 = GridTreeFactory::section($page, title: 'EN First');
        $enSection2 = GridTreeFactory::section($page, title: 'EN Second');

        // Dutch sections
        $dutch = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutch->Locale);

        $nlSection1 = GridTreeFactory::section($page, title: 'NL First');
        $nlSection2 = GridTreeFactory::section($page, title: 'NL Second');

        // Record Dutch sort order before English reorder
        $nlSort1Before = (int) $nlSection1->Sort;
        $nlSort2Before = (int) $nlSection2->Sort;

        // Switch to English and reorder
        $english = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($english->Locale);

        $result = $this->service->reorder($enSection2, $page, null);
        self::assertTrue($result->isOk());

        // Switch to Dutch and verify sort order is unchanged
        FluentState::singleton()->setLocale($dutch->Locale);

        $nlSection1 = GridElement::get()->byID($nlSection1->ID);
        $nlSection2 = GridElement::get()->byID($nlSection2->ID);

        self::assertSame($nlSort1Before, (int) $nlSection1->Sort, 'Dutch section 1 sort should be unchanged');
        self::assertSame($nlSort2Before, (int) $nlSection2->Sort, 'Dutch section 2 sort should be unchanged');
    }
}

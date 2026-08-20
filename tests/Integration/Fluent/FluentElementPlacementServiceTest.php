<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Service\ElementPlacementService;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Verifies that ElementPlacementService correctly reorders elements within a locale
 * without cross-locale interference when Fluent locale filtering is active.
 */
#[CoversClass(ElementPlacementService::class)]
final class FluentElementPlacementServiceTest extends FluentGridTestCase
{
    private ElementPlacementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Injector::inst()->get(ElementPlacementService::class);
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

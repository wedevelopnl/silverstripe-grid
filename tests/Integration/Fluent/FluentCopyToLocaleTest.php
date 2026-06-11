<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
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

    private function createPage(string $title = 'Test Page'): Page
    {
        $page = Page::create();
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
            Page::class,
            (int) $page->ID,
            'en_US',
            'nl_NL',
        );

        // Switch to Dutch and verify the grid was copied
        FluentState::singleton()->setLocale('nl_NL');

        $nlSections = Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => Page::class,
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
            'ParentClass' => Page::class,
        ]), 'English should still have exactly one Section');
    }

    /**
     * `findSourceLocale` is the decision core of `onAfterLocalisedCopy`.
     * The mutants at line 155 (NotIdentical/LogicalAnd/negation) all permute
     * `$defaultLocale !== null && $defaultLocale->Locale !== $targetLocale`.
     * This test exercises the private method via reflection so we can pin
     * every branch independently of the CMS copy-button trigger.
     */
    public function testFindSourceLocaleReturnsDefaultWhenDefaultHasSections(): void
    {
        $page = $this->createPage();
        // Section created in en_US (the global default)
        GridTreeFactory::section($page, title: 'EN Section');

        $extension = new \WeDevelop\Grid\Extensions\FluentGridPageExtension();
        $extension->setOwner($page);

        $method = new \ReflectionMethod($extension, 'findSourceLocale');
        $method->setAccessible(true);

        $result = $method->invoke($extension, (int) $page->ID, Page::class, 'nl_NL');

        self::assertSame('en_US', $result);
    }

    public function testFindSourceLocaleSkipsDefaultWhenTargetEqualsDefault(): void
    {
        $page = $this->createPage();
        GridTreeFactory::section($page, title: 'EN Section');

        $extension = new \WeDevelop\Grid\Extensions\FluentGridPageExtension();
        $extension->setOwner($page);

        $method = new \ReflectionMethod($extension, 'findSourceLocale');
        $method->setAccessible(true);

        // Target == default → the default branch must be skipped.
        // en_US is the only locale with sections, and it equals the target, so
        // after the loop also excludes it we end up with null.
        $result = $method->invoke($extension, (int) $page->ID, Page::class, 'en_US');

        self::assertNull($result);
    }

    public function testFindSourceLocaleFallsThroughWhenDefaultHasNoSections(): void
    {
        $page = $this->createPage();

        // Sections in nl_NL only, not in en_US (default)
        FluentState::singleton()->withState(function (FluentState $state) use ($page): void {
            $state->setLocale('nl_NL');
            GridTreeFactory::section($page, title: 'NL Section');
        });

        $extension = new \WeDevelop\Grid\Extensions\FluentGridPageExtension();
        $extension->setOwner($page);

        $method = new \ReflectionMethod($extension, 'findSourceLocale');
        $method->setAccessible(true);

        // Target is en_US (default). Default has no sections → falls through to
        // the Locale::getCached() loop, finds nl_NL with sections.
        $result = $method->invoke($extension, (int) $page->ID, Page::class, 'en_US');

        self::assertSame('nl_NL', $result);
    }

    public function testFindSourceLocaleReturnsNullWhenNoLocaleHasSections(): void
    {
        $page = $this->createPage(); // page has no sections anywhere

        $extension = new \WeDevelop\Grid\Extensions\FluentGridPageExtension();
        $extension->setOwner($page);

        $method = new \ReflectionMethod($extension, 'findSourceLocale');
        $method->setAccessible(true);

        $result = $method->invoke($extension, (int) $page->ID, Page::class, 'nl_NL');

        self::assertNull($result, 'No locale has sections → nothing to copy from');
    }

    /**
     * Polymorphic-parent filter on Section::get() — the ArrayItemRemoval at
     * FluentGridPageExtension.php:80 removes `'ParentID' => $page->ID`, leaving
     * only `'ParentClass' => $page::class`. Two pages of the same class that
     * share overlapping IDs with other record classes would wrongly be counted
     * together. Pin it with two unrelated Page records, each with its own
     * section — the targetSectionCount for page A must not include page B's.
     */
    public function testCopyCountsSectionsByParentIdAndClass(): void
    {
        $pageA = $this->createPage('Page A');
        $pageB = Page::create();
        $pageB->Title = 'Page B';
        $pageB->URLSegment = 'page-b';
        $pageB->writeToStage(Versioned::DRAFT);

        GridTreeFactory::section($pageA, title: 'Section A');
        GridTreeFactory::section($pageB, title: 'Section B');

        // Copy only Page A to Dutch
        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $pageA->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        // Page A must have its section, but not Page B's
        $pageASections = Section::get()->filter([
            'ParentID' => $pageA->ID,
            'ParentClass' => Page::class,
        ]);
        self::assertCount(1, $pageASections);
        self::assertSame('Section A', $pageASections->first()->Title);

        // Page B must NOT have been auto-copied (we didn't invoke copy on it)
        $pageBSectionsInNl = Section::get()->filter([
            'ParentID' => $pageB->ID,
            'ParentClass' => Page::class,
        ]);
        self::assertCount(0, $pageBSectionsInNl);
    }

    /**
     * copyGridFromLocale suppresses auto_scaffold during duplication, but must
     * restore the *prior* value afterwards (not hardcode true) — otherwise the
     * override leaks for the rest of the request and a later DRAFT Section/Row
     * write silently skips scaffolding. FluentState::withState only scopes the
     * locale, not Config, so a try/finally restore is required.
     */
    public function testCopyRestoresAutoScaffoldToPriorValue(): void
    {
        $page = $this->createPage();
        GridTreeFactory::section($page, title: 'Hero Section');

        // Set a known non-default prior value (setUp set both to false).
        Config::modify()->set(Section::class, 'auto_scaffold', true);
        Config::modify()->set(Row::class, 'auto_scaffold', true);

        CopyToLocaleService::singleton()->copyToLocale(
            Page::class,
            (int) $page->ID,
            'en_US',
            'nl_NL',
        );

        // After the copy, the prior values must be restored, not left at false
        // (the in-copy suppression value) and not hardcoded to a default.
        self::assertTrue(
            (bool) Section::config()->get('auto_scaffold'),
            'Section auto_scaffold must be restored to its prior value (true) after copy',
        );
        self::assertTrue(
            (bool) Row::config()->get('auto_scaffold'),
            'Row auto_scaffold must be restored to its prior value (true) after copy',
        );
    }

    /**
     * Complements the prior-value-true case: when the prior value is false, the
     * restore must leave it false. A naive restore that hardcodes true (the
     * pattern GridMigrationService uses) would wrongly flip it on here — this
     * test pins capture-and-restore over hardcode-the-default.
     */
    public function testCopyRestoresAutoScaffoldWhenPriorValueWasFalse(): void
    {
        $page = $this->createPage();
        GridTreeFactory::section($page, title: 'Hero Section');

        // setUp already sets both to false; assert this is the captured prior.
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        CopyToLocaleService::singleton()->copyToLocale(
            Page::class,
            (int) $page->ID,
            'en_US',
            'nl_NL',
        );

        self::assertFalse(
            (bool) Section::config()->get('auto_scaffold'),
            'Section auto_scaffold must stay false (prior value), not be hardcoded to true',
        );
        self::assertFalse(
            (bool) Row::config()->get('auto_scaffold'),
            'Row auto_scaffold must stay false (prior value), not be hardcoded to true',
        );
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
            Page::class,
            (int) $page->ID,
            'en_US',
            'nl_NL',
        );

        FluentState::singleton()->setLocale('nl_NL');

        $nlSections = Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => Page::class,
        ]);
        self::assertCount(2, $nlSections, 'Dutch should have both zones');

        $zones = $nlSections->column('Zone');
        self::assertContains('main', $zones);
        self::assertContains('sidebar', $zones);
    }
}

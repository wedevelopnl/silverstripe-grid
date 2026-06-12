<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Delete\DeleteLocalisationPolicy;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Verifies that clearing a locale from a page also removes the grid
 * hierarchy (Section → Row → Column → Content) for that locale.
 *
 * Pages are created manually (not via fixture) because FluentExtension
 * needs an active FluentState during write to create localised records.
 */
#[CoversNothing]
final class FluentClearLocaleTest extends SapphireTest
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
        $page->URLSegment = 'fluent-clear-test';
        $page->writeToStage(Versioned::DRAFT);

        return $page;
    }

    /**
     * When a locale is cleared from a page, all grid elements in that
     * locale must be deleted while the other locale remains intact.
     */
    public function testClearLocaleDeletesGridElements(): void
    {
        $page = $this->createPage();

        // Build tree in English
        $enSection = GridTreeFactory::section($page, title: 'EN Section');
        $enRow = GridTreeFactory::row($enSection);
        $enCol = GridTreeFactory::column($enRow);
        GridTreeFactory::contentElement($enCol, title: 'EN Content');

        // Build tree in Dutch
        FluentState::singleton()->setLocale('nl_NL');
        $page->writeToStage(Versioned::DRAFT); // Localise page to Dutch

        $nlSection = GridTreeFactory::section($page, title: 'NL Section');
        $nlRow = GridTreeFactory::row($nlSection);
        $nlCol = GridTreeFactory::column($nlRow);
        GridTreeFactory::contentElement($nlCol, title: 'NL Content');

        // Clear Dutch locale (simulates clearFluent for one locale)
        $policy = DeleteLocalisationPolicy::create();
        $policy->delete($page);

        // Dutch grid elements should be gone
        self::assertCount(0, Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => Page::class,
        ]), 'Dutch Sections should be deleted');
        self::assertCount(0, Row::get(), 'Dutch Rows should be deleted');
        self::assertCount(0, Column::get(), 'Dutch Columns should be deleted');
        self::assertCount(0, ContentElement::get(), 'Dutch ContentElements should be deleted');

        // English grid elements should be intact
        FluentState::singleton()->setLocale('en_US');

        self::assertCount(1, Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => Page::class,
        ]), 'English Section should be intact');
        self::assertCount(1, Row::get(), 'English Row should be intact');
        self::assertCount(1, Column::get(), 'English Column should be intact');
        self::assertCount(1, ContentElement::get(), 'English ContentElement should be intact');
    }

    /**
     * Clearing a locale that has no grid elements should not cause errors
     * and should not affect other locales.
     */
    public function testClearLocaleWithNoGridElementsIsNoop(): void
    {
        $page = $this->createPage();

        // Build tree only in English
        $section = GridTreeFactory::section($page, title: 'EN Only');
        GridTreeFactory::row($section);

        // Localise page to Dutch (no grid elements created)
        FluentState::singleton()->setLocale('nl_NL');
        $page->writeToStage(Versioned::DRAFT);

        // Clear Dutch locale
        $policy = DeleteLocalisationPolicy::create();
        $policy->delete($page);

        // English should be unaffected
        FluentState::singleton()->setLocale('en_US');
        self::assertCount(1, Section::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => Page::class,
        ]), 'English Section should be intact');
    }
}

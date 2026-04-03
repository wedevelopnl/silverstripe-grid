<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

final class FluentLocaleIsolationTest extends SapphireTest
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

        // Disable auto-scaffolding so tests can focus on locale isolation
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        // Default to English for each test
        $locale = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($locale->Locale);
    }

    /**
     * An element written in locale A must not appear when querying in locale B.
     */
    public function testElementInLocaleAIsInvisibleInLocaleB(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Create section in English
        $section = GridTreeFactory::section($page);
        $sectionId = (int) $section->ID;

        // Confirm it exists in English
        self::assertInstanceOf(Section::class, Section::get()->byID($sectionId));

        // Switch to Dutch and verify the section is invisible
        $dutch = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutch->Locale);

        self::assertNull(Section::get()->byID($sectionId));
    }

    /**
     * Each locale can have its own independent set of sections.
     * Querying one locale must not return elements from the other.
     */
    public function testIndependentStructuresPerLocale(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Create section in English
        $englishSection = GridTreeFactory::section($page, 'main', 0, 'English Section');
        $englishId = (int) $englishSection->ID;

        // Switch to Dutch and create a section there
        $dutch = $this->objFromFixture(Locale::class, 'nl');
        FluentState::singleton()->setLocale($dutch->Locale);

        $dutchSection = GridTreeFactory::section($page, 'main', 0, 'Dutch Section');
        $dutchId = (int) $dutchSection->ID;

        // Dutch locale sees only its own section
        self::assertNull(Section::get()->byID($englishId));
        self::assertInstanceOf(Section::class, Section::get()->byID($dutchId));

        // Switch back to English and verify it only sees its own section
        $english = $this->objFromFixture(Locale::class, 'en');
        FluentState::singleton()->setLocale($english->Locale);

        self::assertInstanceOf(Section::class, Section::get()->byID($englishId));
        self::assertNull(Section::get()->byID($dutchId));
    }

    /**
     * FluentIsolatedExtension must auto-assign LocaleID on write.
     *
     * We read LocaleID from the in-memory object right after write() because
     * FluentIsolatedExtension sets it in onBeforeWrite before the DB insert.
     * This avoids needing a locale-bypass query API that Fluent does not expose.
     */
    public function testAutoLocaleAssignmentOnWrite(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $english = $this->objFromFixture(Locale::class, 'en');

        $section = GridTreeFactory::section($page);

        // LocaleID is set on the in-memory object by onBeforeWrite before the DB write
        self::assertSame((int) $english->ID, (int) $section->LocaleID);
    }
}

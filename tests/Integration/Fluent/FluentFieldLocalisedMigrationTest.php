<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;

/**
 * Migration of the FieldLocalised legacy localisation model: a single base
 * BaseElement row holds the default-locale values, and per-locale
 * BaseElement_Localised / ElementContent_Localised overlay tables carry the
 * translated field values that override the base on a per-locale basis.
 */
#[CoversClass(FluentMigrationOrchestrator::class)]
final class FluentFieldLocalisedMigrationTest extends FluentMigrationTestCase
{
    /**
     * Seed a base EN element plus an nl_NL overlay for both the element title
     * and its content HTML.
     */
    private function seedFieldLocalisedPage(int $pageId, int $elementId): void
    {
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement($elementId, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN One', 'SizeMD' => 6]);
        $this->seeder->seedContentMedia($elementId, ['HTML' => '<p>EN</p>']);
        $this->seeder->addFieldLocalisedTables();
        $this->seeder->seedLocalisedElement($elementId, 'nl_NL', ['Title' => 'NL One']);
        $this->seeder->seedLocalisedContent($elementId, 'nl_NL', ['HTML' => '<p>NL</p>']);
    }

    public function testMigratesPerLocaleWithOverlay(): void
    {
        $pageId = $this->pageId();
        $this->seedFieldLocalisedPage($pageId, 7000);

        $failures = $this->runMigration($pageId);

        self::assertSame(0, $failures);
        self::assertSame(['EN One'], $this->contentTitlesInLocale('en_US'));
        self::assertSame(['NL One'], $this->contentTitlesInLocale('nl_NL'), 'nl locale gets the overlaid title');
    }

    public function testReRunIsIdempotentPerLocale(): void
    {
        $pageId = $this->pageId();
        $this->seedFieldLocalisedPage($pageId, 7300);

        $this->runMigration($pageId);
        $this->runMigration($pageId);

        self::assertSame(1, $this->sectionCountInLocale($pageId, 'en_US'), 'no duplicate sections in en_US');
        self::assertSame(1, $this->sectionCountInLocale($pageId, 'nl_NL'), 'no duplicate sections in nl_NL');
    }

    public function testUseGridIsSetInEveryLocale(): void
    {
        $pageId = $this->pageId();
        $this->seedFieldLocalisedPage($pageId, 7400);

        $this->runMigration($pageId);

        self::assertTrue($this->useGridInLocale($pageId, 'en_US'), 'UseGrid must be set in en_US');
        self::assertTrue($this->useGridInLocale($pageId, 'nl_NL'), 'UseGrid must be set in nl_NL (non-localised shared flag)');
    }
}

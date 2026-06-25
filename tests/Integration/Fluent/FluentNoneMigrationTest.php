<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;

/**
 * Migration of the None legacy localisation model: legacy content is
 * single-locale (no LocaleID column, no _Localised overlay tables). Only the
 * default locale is migrated — the content must not be duplicated into the
 * other locales — while the page-level UseGrid flag, being locale-invariant,
 * is still visible everywhere.
 */
#[CoversClass(FluentMigrationOrchestrator::class)]
final class FluentNoneMigrationTest extends FluentMigrationTestCase
{
    private function seedNonLocalisedPage(int $pageId, int $elementId): void
    {
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement($elementId, 100, self::CONTENT_CLASS, 1, ['Title' => 'Shared']);
        $this->seeder->seedContentMedia($elementId);
    }

    public function testMigratesOnlyDefaultLocale(): void
    {
        $pageId = $this->pageId();
        $this->seedNonLocalisedPage($pageId, 7200);

        $failures = $this->runMigration($pageId);

        self::assertSame(0, $failures);
        self::assertSame(['Shared'], $this->contentTitlesInLocale('en_US'));
        self::assertSame([], $this->contentTitlesInLocale('nl_NL'), 'non-localised content is not duplicated into other locales');
    }

    public function testReRunIsIdempotent(): void
    {
        $pageId = $this->pageId();
        $this->seedNonLocalisedPage($pageId, 7210);

        $this->runMigration($pageId);
        $this->runMigration($pageId);

        self::assertSame(1, $this->sectionCountInLocale($pageId, 'en_US'), 'no duplicate sections in the default locale');
        self::assertSame(0, $this->sectionCountInLocale($pageId, 'nl_NL'), 'still no sections in non-default locales after re-run');
    }

    public function testUseGridIsVisibleInEveryLocale(): void
    {
        $pageId = $this->pageId();
        $this->seedNonLocalisedPage($pageId, 7220);

        $this->runMigration($pageId);

        self::assertTrue($this->useGridInLocale($pageId, 'en_US'), 'UseGrid must be set in en_US');
        self::assertTrue(
            $this->useGridInLocale($pageId, 'nl_NL'),
            'UseGrid is a locale-invariant page flag, so it is visible in nl_NL even though no content migrated there',
        );
    }
}

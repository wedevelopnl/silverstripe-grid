<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;

/**
 * Migration of the Isolated legacy localisation model: each locale carries its
 * own BaseElement rows, distinguished by a LocaleID column, and migrates into
 * its own locale-isolated grid independently of the others.
 */
#[CoversClass(FluentMigrationOrchestrator::class)]
final class FluentIsolatedMigrationTest extends FluentMigrationTestCase
{
    /**
     * Seed one isolated content element per locale. EN sorts first, NL second,
     * each tagged with its own LocaleID so the reader scopes them per locale.
     */
    private function seedIsolatedPage(int $pageId): void
    {
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->addLocaleIdColumn();
        $en = (int) $this->objFromFixture(Locale::class, 'en')->ID;
        $nl = (int) $this->objFromFixture(Locale::class, 'nl')->ID;
        $this->seeder->seedElement(7100, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN Iso', 'LocaleID' => $en]);
        $this->seeder->seedContentMedia(7100);
        $this->seeder->seedElement(7101, 100, self::CONTENT_CLASS, 2, ['Title' => 'NL Iso', 'LocaleID' => $nl]);
        $this->seeder->seedContentMedia(7101);
    }

    public function testMigratesEachLocaleIndependently(): void
    {
        $pageId = $this->pageId();
        $this->seedIsolatedPage($pageId);

        $failures = $this->runMigration($pageId);

        self::assertSame(0, $failures);
        self::assertSame(['EN Iso'], $this->contentTitlesInLocale('en_US'));
        self::assertSame(['NL Iso'], $this->contentTitlesInLocale('nl_NL'));
    }

    public function testReRunIsIdempotentPerLocale(): void
    {
        $pageId = $this->pageId();
        $this->seedIsolatedPage($pageId);

        $this->runMigration($pageId);
        $this->runMigration($pageId);

        self::assertSame(1, $this->sectionCountInLocale($pageId, 'en_US'), 'no duplicate sections in en_US');
        self::assertSame(1, $this->sectionCountInLocale($pageId, 'nl_NL'), 'no duplicate sections in nl_NL');
        self::assertSame(['EN Iso'], $this->contentTitlesInLocale('en_US'));
        self::assertSame(['NL Iso'], $this->contentTitlesInLocale('nl_NL'));
    }

    public function testUseGridIsSetInEveryLocale(): void
    {
        $pageId = $this->pageId();
        $this->seedIsolatedPage($pageId);

        $this->runMigration($pageId);

        self::assertTrue($this->useGridInLocale($pageId, 'en_US'), 'UseGrid must be set in en_US');
        self::assertTrue($this->useGridInLocale($pageId, 'nl_NL'), 'UseGrid must be set in nl_NL (non-localised shared flag)');
    }
}

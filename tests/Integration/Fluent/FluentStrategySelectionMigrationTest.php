<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Grid\Migration\Task\MigrateGridWithFluentTask;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * The strategy axis (--strategy flag) and the Fluent-locale axis are otherwise
 * orthogonal: the strategy shapes elements → Section/Row/Column with no locale
 * awareness, and the orchestrator threads whichever strategy it is given into
 * each per-locale run unchanged. This single test pins the one genuine
 * interaction between them — that the flag the task selects is the strategy the
 * orchestrator actually applies within every locale.
 *
 * Per-strategy hierarchy shaping itself is covered locale-free by
 * MigrateGridTaskTest (RowPerSection) and MigrateGridSingleSectionTaskTest
 * (AllRowsInSection); this test does not re-verify it for each mode.
 */
#[CoversClass(MigrateGridWithFluentTask::class)]
final class FluentStrategySelectionMigrationTest extends FluentMigrationTestCase
{
    private const string ROW_CLASS = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

    /**
     * Seed two isolated locales, each with TWO legacy rows. Two rows is what
     * distinguishes the strategies: AllRowsInSection collapses them into ONE
     * Section, whereas the default RowPerSection would produce two.
     */
    private function seedTwoRowsPerLocale(int $pageId): void
    {
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->addLocaleIdColumn();
        $en = (int) $this->objFromFixture(Locale::class, 'en')->ID;
        $nl = (int) $this->objFromFixture(Locale::class, 'nl')->ID;

        foreach ([['locale' => $en, 'base' => 8000], ['locale' => $nl, 'base' => 8100]] as $seed) {
            $base = $seed['base'];
            $this->seeder->seedElement($base, 100, self::ROW_CLASS, 1, ['LocaleID' => $seed['locale']]);
            $this->seeder->seedRow($base);
            $this->seeder->seedElement($base + 1, 100, self::CONTENT_CLASS, 2, ['LocaleID' => $seed['locale'], 'SizeMD' => 12]);
            $this->seeder->seedContentMedia($base + 1);

            $this->seeder->seedElement($base + 10, 100, self::ROW_CLASS, 3, ['LocaleID' => $seed['locale']]);
            $this->seeder->seedRow($base + 10);
            $this->seeder->seedElement($base + 11, 100, self::CONTENT_CLASS, 4, ['LocaleID' => $seed['locale'], 'SizeMD' => 12]);
            $this->seeder->seedContentMedia($base + 11);
        }
    }

    public function testSingleSectionStrategyIsAppliedWithinEveryLocale(): void
    {
        $pageId = $this->pageId();
        $this->seedTwoRowsPerLocale($pageId);

        $result = TaskRunner::run(new MigrateGridWithFluentTask(), [
            '--default-viewport' => self::DEFAULT_VIEWPORT,
            '--zone' => self::ZONE,
            '--strategy' => 'single-section',
            '--page-ids' => (string) $pageId,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        // single-section collapses both rows into one Section *per locale*; the
        // default RowPerSection strategy would yield two. One Section in each
        // locale therefore proves the flag reached every per-locale run.
        self::assertSame(1, $this->sectionCountInLocale($pageId, 'en_US'), 'single-section applied in en_US');
        self::assertSame(1, $this->sectionCountInLocale($pageId, 'nl_NL'), 'single-section applied in nl_NL');
    }
}

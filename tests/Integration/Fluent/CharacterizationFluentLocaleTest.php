<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTreeSnapshot;

/**
 * Golden-master (characterization) test for the Fluent multi-locale migration
 * scenario. It seeds the Isolated legacy localisation model — each locale owns
 * its own BaseElement rows, distinguished by a LocaleID column — and pins the
 * CURRENT observed per-locale migration output: column default widths and the
 * `GridSettingsOverrides` NULL-vs-JSON tri-state, captured independently in each
 * locale.
 *
 * It pins observed behaviour; it does not assert intended behaviour. If it
 * fails, the expected value is mis-pinned — read the actual per-locale output
 * and correct the expectation, never touch `src/`.
 *
 * ── Perturbation thought-experiment (why this is the WS5 #8 proof surface) ──
 * This golden master is the proof surface for WS5 #8 (the null default-locale
 * guard). Today, when `Locale::getDefault()` is non-null the orchestrator
 * migrates default-locale-first and this test pins the resulting per-locale
 * tree: EN (the global default) carries element 'EN' at column width 8 with a
 * NULL overrides column; NL carries element 'NL' at column width 4 with a
 * non-null JSON overrides column whose `lg` width is 3; content never
 * cross-duplicates between locales; and `UseGrid` is set in both locales.
 *
 * WS5 #8 intentionally changes the default-locale handling. That change must
 * update THIS golden master in the same PR, with the diff visible in review.
 * The assertions below are therefore deliberately specific (exact per-locale
 * widths, exact null-vs-JSON overrides, exact titles, exact section counts) so
 * that any behavioural shift produces a reviewable diff here rather than a
 * silent pass.
 */
#[CoversClass(FluentMigrationOrchestrator::class)]
final class CharacterizationFluentLocaleTest extends FluentMigrationTestCase
{
    private const int AREA_ID = 100;

    /**
     * Seed the Isolated model: one content element per locale, each tagged with
     * its own LocaleID. EN (global default) sorts first with width 8 and no
     * override; NL sorts second with width 4 and an `lg` override (SizeLG 3) so
     * the per-locale overrides tri-state is exercised on both arms.
     *
     * @param positive-int $pageId
     */
    private function seedIsolatedLocalePage(int $pageId): void
    {
        $this->seeder->seedPage($pageId, self::AREA_ID);
        $this->seeder->addLocaleIdColumn();

        $en = (int) $this->objFromFixture(Locale::class, 'en')->ID;
        $nl = (int) $this->objFromFixture(Locale::class, 'nl')->ID;

        $this->seeder->seedElement(7100, self::AREA_ID, self::CONTENT_CLASS, 1, [
            'Title' => 'EN',
            'SizeMD' => 8,
            'LocaleID' => $en,
        ]);
        $this->seeder->seedContentMedia(7100);

        $this->seeder->seedElement(7101, self::AREA_ID, self::CONTENT_CLASS, 2, [
            'Title' => 'NL',
            'SizeMD' => 4,
            'SizeLG' => 3,
            'LocaleID' => $nl,
        ]);
        $this->seeder->seedContentMedia(7101);
    }

    public function testPerLocaleGoldenMaster(): void
    {
        $pageId = $this->pageId();
        $this->seedIsolatedLocalePage($pageId);

        self::assertSame(0, $this->runMigration($pageId), 'Multi-locale migration completes without cross-locale failures');

        // ── No cross-locale content duplication ──────────────────────
        self::assertSame(['EN'], $this->contentTitlesInLocale('en_US'), 'en_US holds only its own content element');
        self::assertSame(['NL'], $this->contentTitlesInLocale('nl_NL'), 'nl_NL holds only its own content element');

        // ── UseGrid is a locale-invariant shared flag ────────────────
        self::assertTrue($this->useGridInLocale($pageId, 'en_US'), 'UseGrid set in en_US');
        self::assertTrue($this->useGridInLocale($pageId, 'nl_NL'), 'UseGrid set in nl_NL');

        // ── en_US (global default): width 8, NULL overrides ──────────
        self::assertSame(1, $this->sectionCountInLocale($pageId, 'en_US'), 'exactly one Section in en_US');
        $enColumn = $this->columnSnapshotInLocale($pageId, 'en_US');
        self::assertSame(8, $enColumn['gridDefault']['width'], 'en_US column default width is 8');
        self::assertNull($enColumn['overridesColumnRaw'], 'en_US column has no per-viewport override (NULL tri-state)');

        // ── nl_NL: width 4, non-null JSON override with lg width 3 ────
        self::assertSame(1, $this->sectionCountInLocale($pageId, 'nl_NL'), 'exactly one Section in nl_NL');
        $nlColumn = $this->columnSnapshotInLocale($pageId, 'nl_NL');
        self::assertSame(4, $nlColumn['gridDefault']['width'], 'nl_NL column default width is 4');

        $nlOverridesRaw = $nlColumn['overridesColumnRaw'];
        self::assertIsString($nlOverridesRaw, 'nl_NL column persists a non-null JSON overrides column (JSON tri-state)');
        self::assertStringContainsString('"lg"', $nlOverridesRaw, 'nl_NL column carries the lg override');
        $decoded = json_decode($nlOverridesRaw, true);
        self::assertIsArray($decoded);
        self::assertSame(3, $decoded['lg']['width'] ?? null, 'nl_NL column lg override width is 3');
    }

    /**
     * Snapshot the migrated tree inside a Fluent locale and return its single
     * Section's single Row's single Column. Reuses {@see MigrationTreeSnapshot}
     * (which reads `overridesColumnRaw` raw, preserving the NULL-vs-JSON
     * tri-state) inside a `FluentState::withState` closure so the read is scoped
     * to the requested locale.
     *
     * @param positive-int     $pageId
     * @param non-empty-string $localeCode
     *
     * @return array{
     *     sort: int,
     *     gridDefault: array{width: positive-int, offset: int<0, max>, visible: bool},
     *     overridesColumnRaw: string|null,
     *     elements: list<array<string, mixed>>,
     * }
     */
    private function columnSnapshotInLocale(int $pageId, string $localeCode): array
    {
        $tree = FluentState::singleton()->withState(
            static function (FluentState $state) use ($pageId, $localeCode): array {
                $state->setLocale($localeCode);

                return MigrationTreeSnapshot::snapshotTree($pageId, Page::class, self::ZONE, Versioned::DRAFT);
            },
        );

        self::assertCount(1, $tree, "exactly one Section in {$localeCode}");
        self::assertCount(1, $tree[0]['rows'], "exactly one Row in {$localeCode}");
        self::assertCount(1, $tree[0]['rows'][0]['columns'], "exactly one Column in {$localeCode}");

        return $tree[0]['rows'][0]['columns'][0];
    }
}

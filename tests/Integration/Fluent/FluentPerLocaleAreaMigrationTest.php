<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\DTO\MigrationSummary;
use WeDevelop\Grid\Migration\Service\DroppedMediaCollector;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
use WeDevelop\Grid\Migration\Service\LocaleScopedLegacySource;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Tests\Integration\Support\RecordingLogger;

/**
 * Migration of a site whose page table localised ElementalAreaID: each locale
 * points at its own legacy area through `Page_Localised`, while the elements
 * themselves are not localised. Every locale migrates from its own area on
 * each stage; de_DE (no row, falls back to en_US) takes an independent copy of
 * en_US's area, and fr_FR (no row, no fallback) gets no grid.
 */
#[CoversClass(FluentMigrationOrchestrator::class)]
#[CoversClass(LocaleScopedLegacySource::class)]
final class FluentPerLocaleAreaMigrationTest extends FluentMigrationTestCase
{
    protected static $fixture_file = __DIR__ . '/Fixture/migration-locales-fallback.yml';

    private const int EN_AREA = 100;
    private const int NL_AREA = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seeder->addLocalisedAreaTables('Page');
    }

    protected function tearDown(): void
    {
        $this->seeder->removeLocalisedAreaTables('Page');
        parent::tearDown();
    }

    /**
     * en_US holds "Welcome" and nl_NL holds "Welkom", each in its own area on
     * both stages. The base column holds whichever locale was saved last.
     */
    private function seedOwnAreas(int $pageId, int $baseAreaId = self::EN_AREA): void
    {
        $this->seeder->seedPage($pageId, $baseAreaId);
        foreach (['draft', 'live'] as $stage) {
            $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', self::EN_AREA, $stage);
            $this->seeder->seedLocalisedArea('Page', $pageId, 'nl_NL', self::NL_AREA, $stage);
            $this->seeder->seedElement(7100, self::EN_AREA, self::CONTENT_CLASS, 1, ['Title' => 'Welcome'], $stage);
            $this->seeder->seedContentMedia(7100, [], $stage);
            $this->seeder->seedElement(7200, self::NL_AREA, self::CONTENT_CLASS, 1, ['Title' => 'Welkom'], $stage);
            $this->seeder->seedContentMedia(7200, [], $stage);
        }
    }

    private function makeOrchestrator(LoggerInterface $logger): FluentMigrationOrchestrator
    {
        $mapper = new FieldMapper();

        return new FluentMigrationOrchestrator(
            new LegacyDataReader(),
            new LegacyLocalisationDetector(),
            $mapper,
            new RowPerSectionStrategy(new ElementGrouper(), $mapper, self::DEFAULT_VIEWPORT, self::VIEWPORT_KEY_MAP),
            $logger,
            new DroppedMediaCollector(),
        );
    }

    /** @return list<string> */
    private function liveContentTitlesInLocale(string $localeCode): array
    {
        return Versioned::withVersionedMode(function () use ($localeCode): array {
            Versioned::set_stage(Versioned::LIVE);

            return $this->contentTitlesInLocale($localeCode);
        });
    }

    public function testEachLocaleKeepsItsOwnBlocksOnDraftAndLive(): void
    {
        $pageId = $this->pageId();
        $this->seedOwnAreas($pageId);

        self::assertSame(0, $this->runMigration($pageId));

        self::assertSame(['Welcome'], $this->contentTitlesInLocale('en_US'));
        self::assertSame(['Welkom'], $this->contentTitlesInLocale('nl_NL'));
        self::assertSame(['Welcome'], $this->liveContentTitlesInLocale('en_US'));
        self::assertSame(['Welkom'], $this->liveContentTitlesInLocale('nl_NL'));
    }

    public function testTheLastEditedLocaleInTheBaseColumnDoesNotTakeOver(): void
    {
        $pageId = $this->pageId();
        $this->seedOwnAreas($pageId, baseAreaId: self::NL_AREA);

        self::assertSame(0, $this->runMigration($pageId));

        self::assertSame(['Welcome'], $this->contentTitlesInLocale('en_US'));
        self::assertSame(['Welcome'], $this->liveContentTitlesInLocale('en_US'));
        self::assertSame(['Welkom'], $this->contentTitlesInLocale('nl_NL'));
        self::assertSame(['Welkom'], $this->liveContentTitlesInLocale('nl_NL'));
    }

    public function testALocaleWithoutItsOwnAreaGetsAnIndependentCopyOfItsFallbacksBlocks(): void
    {
        $pageId = $this->pageId();
        $this->seedOwnAreas($pageId);

        $this->runMigration($pageId);

        self::assertSame(['Welcome'], $this->contentTitlesInLocale('de_DE'));
        self::assertSame(['Welcome'], $this->liveContentTitlesInLocale('de_DE'));

        FluentState::singleton()->withState(static function (FluentState $state): void {
            $state->setLocale('en_US');
            $english = ContentElement::get()->filter(['ParentClass' => Column::class, 'Title' => 'Welcome'])->first();
            self::assertInstanceOf(ContentElement::class, $english);
            $english->Title = 'Hello';
            $english->write();
        });

        self::assertSame(['Hello'], $this->contentTitlesInLocale('en_US'));
        self::assertSame(['Welcome'], $this->contentTitlesInLocale('de_DE'), 'the copy is not linked to its source');
    }

    public function testALocaleWithNoAreaAnywhereInItsChainGetsNoGrid(): void
    {
        $pageId = $this->pageId();
        $this->seedOwnAreas($pageId);

        $this->runMigration($pageId);

        self::assertSame(0, $this->sectionCountInLocale($pageId, 'fr_FR'));
        self::assertSame(['Welcome'], $this->contentTitlesInLocale('en_US'));
        self::assertSame(['Welkom'], $this->contentTitlesInLocale('nl_NL'));
    }

    public function testDifferentDraftAndLiveAreasInOneLocaleEachReachTheirOwnStage(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, self::EN_AREA);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', self::EN_AREA);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 300, 'live');
        $this->seeder->seedElement(7100, self::EN_AREA, self::CONTENT_CLASS, 1, ['Title' => 'Draft welcome']);
        $this->seeder->seedContentMedia(7100);
        $this->seeder->seedElement(7300, 300, self::CONTENT_CLASS, 1, ['Title' => 'Live welcome'], 'live');
        $this->seeder->seedContentMedia(7300, [], 'live');

        self::assertSame(0, $this->runMigration($pageId));

        self::assertSame(['Live welcome'], $this->liveContentTitlesInLocale('en_US'));
        $draft = $this->contentTitlesInLocale('en_US');
        \sort($draft);
        self::assertSame(
            ['Draft welcome', 'Live welcome'],
            $draft,
            'a live-only block is written to both stages, as on a single-locale site',
        );
    }

    public function testReRunIsIdempotentPerLocale(): void
    {
        $pageId = $this->pageId();
        $this->seedOwnAreas($pageId);

        $this->runMigration($pageId);
        $this->runMigration($pageId);

        self::assertSame(1, $this->sectionCountInLocale($pageId, 'en_US'));
        self::assertSame(1, $this->sectionCountInLocale($pageId, 'nl_NL'));
        self::assertSame(1, $this->sectionCountInLocale($pageId, 'de_DE'));
        self::assertSame(0, $this->sectionCountInLocale($pageId, 'fr_FR'));
    }

    public function testDryRunCountsEveryLocaleAndWritesNothing(): void
    {
        $pageId = $this->pageId();
        $this->seedOwnAreas($pageId);

        $summaries = $this->makeOrchestrator(new NullLogger())
            ->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP, true, [$pageId]);

        self::assertSame(
            ['en_US' => [1, 1], 'de_DE' => [1, 1], 'fr_FR' => [0, 0], 'nl_NL' => [1, 1]],
            \array_map(static fn (MigrationSummary $s): array => [$s->pagesMigrated, $s->blocksMigrated], $summaries),
        );
        self::assertSame(0, $this->sectionCountInLocale($pageId, 'en_US'));
    }

    public function testLogsWhichLocaleSuppliedABorrowedArea(): void
    {
        $pageId = $this->pageId();
        $this->seedOwnAreas($pageId);
        $logger = new RecordingLogger();

        $this->makeOrchestrator($logger)->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP, false, [$pageId]);

        $borrowed = \array_values(\array_filter(
            $logger->messages,
            static fn (array $entry): bool => \str_contains($entry['message'], 'content area of its own'),
        ));
        self::assertSame(
            [['pageId' => $pageId, 'locale' => 'de_DE', 'source' => 'en_US']],
            \array_column($borrowed, 'context'),
        );
    }

    public function testAnUnpublishedTranslationGetsNoLiveBlocksFromItsFallback(): void
    {
        $pageId = $this->pageId();
        $this->seedOwnAreas($pageId);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'de_DE', 400);
        $this->seeder->seedElement(7400, 400, self::CONTENT_CLASS, 1, ['Title' => 'Willkommen']);
        $this->seeder->seedContentMedia(7400);

        self::assertSame(0, $this->runMigration($pageId));

        self::assertSame(['Willkommen'], $this->contentTitlesInLocale('de_DE'));
        self::assertSame([], $this->liveContentTitlesInLocale('de_DE'));
        self::assertSame(['Welcome'], $this->liveContentTitlesInLocale('en_US'));
    }
}

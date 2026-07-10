<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Page;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\DraftHierarchyWriter;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\LivePublisher;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\MigrationIdMap;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Direct coverage for the live-publish collaborator extracted from
 * GridMigrationService. A real draft hierarchy is written via
 * {@see DraftHierarchyWriter} (populating a real {@see MigrationIdMap}); then
 * {@see LivePublisher::publishToLive()} is driven on DRAFT stage and the
 * resulting LIVE-stage records are asserted.
 */
#[CoversClass(LivePublisher::class)]
final class LivePublisherTest extends SapphireTest
{
    /** $extra_dataobjects alone does not provision the temp DB — this test writes records. */
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [TestCustomElement::class, TestPage::class];

    // The publisher is only ever invoked from GridMigrationService::run(), which
    // manages its own transactions; mirror that by disabling SapphireTest's
    // per-test transaction wrapping (its DDL/savepoint nesting is incompatible).
    protected $usesTransactions = false;

    private const string ZONE = 'main';

    private const string DEFAULT_VIEWPORT = 'MD';

    private const array VIEWPORT_KEY_MAP = [
        'XS' => 'xs',
        'SM' => 'sm',
        'MD' => 'md',
        'LG' => 'lg',
        'XL' => 'xl',
    ];

    private const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    private const string ROW_CLASS = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

    private FieldMapper $mapper;

    private DraftHierarchyWriter $draftWriter;

    private LivePublisher $publisher;

    private LoggerInterface $logger;

    private bool $sectionAutoScaffold;

    private bool $rowAutoScaffold;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        // The publisher's caller (GridMigrationService::run) suppresses
        // Section/Row auto-scaffolding for the batch; replicate that so a single
        // Section write does not also scaffold an extra Row + Column.
        $this->sectionAutoScaffold = (bool) Section::config()->get('auto_scaffold');
        $this->rowAutoScaffold = (bool) Row::config()->get('auto_scaffold');
        Section::config()->set('auto_scaffold', false);
        Row::config()->set('auto_scaffold', false);

        // No DDL/transaction rollback runs (usesTransactions = false), so purge any
        // grid records leaked from a previous test before each method.
        $this->cleanGridTables();

        $this->mapper = new FieldMapper();
        $this->draftWriter = new DraftHierarchyWriter($this->mapper);
        $this->logger = new class () extends NullLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $strategy = new RowPerSectionStrategy(
            new ElementGrouper(),
            $this->mapper,
            self::DEFAULT_VIEWPORT,
            self::VIEWPORT_KEY_MAP,
        );

        $this->publisher = new LivePublisher($this->mapper, $this->draftWriter, $this->logger, $strategy);
    }

    protected function tearDown(): void
    {
        Section::config()->set('auto_scaffold', $this->sectionAutoScaffold);
        Row::config()->set('auto_scaffold', $this->rowAutoScaffold);

        parent::tearDown();
    }

    public function testSharedElementIsPublishedToLiveWithLiveValuesAndContainerChain(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        // Draft: one element (legacy id 5000), Column width 8.
        $draftElement = $this->legacyElement(5000, 'Draft Title');
        $this->writeDraft($pageId, [$this->section([$draftElement], $this->gridSettings(8))], $idMap);

        $newElementId = $idMap->newElementId(5000);
        $newColumnId = $idMap->newColumnId(5000);

        // Live: same legacy id, divergent live Title + HTML.
        $liveElement = $this->legacyElement(
            5000,
            'Live Title',
            media: new LegacyMediaData(['HTML' => '<p>Live HTML</p>']),
        );

        $this->publish($pageId, Page::class, [$liveElement], [5000 => true], $idMap);

        // Content element exists on LIVE with the live-specific Title + draft-local Sort.
        $liveContent = $this->liveById(ContentElement::class, $newElementId);
        self::assertInstanceOf(ContentElement::class, $liveContent);
        self::assertSame('Live Title', (string) $liveContent->Title);
        self::assertSame(1, (int) $liveContent->Sort, 'Sort is the column-local draft Sort, not the legacy area sort');
        self::assertSame('<p>Live HTML</p>', (string) $liveContent->HTML);

        // The whole container chain is published to LIVE.
        self::assertInstanceOf(Column::class, $this->liveById(Column::class, $newColumnId));
        $liveColumn = $this->liveById(Column::class, $newColumnId);
        self::assertInstanceOf(Column::class, $liveColumn);
        self::assertInstanceOf(Row::class, $this->liveById(Row::class, (int) $liveColumn->ParentID));
        $liveRow = $this->liveById(Row::class, (int) $liveColumn->ParentID);
        self::assertInstanceOf(Row::class, $liveRow);
        self::assertInstanceOf(Section::class, $this->liveById(Section::class, (int) $liveRow->ParentID));
    }

    public function testSharedRowDelimiterIsNotPublishedAsRecord(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $draftElement = $this->legacyElement(5000, 'Draft Title');
        $this->writeDraft($pageId, [$this->section([$draftElement], $this->gridSettings(8))], $idMap);

        // A shared row delimiter (id 6000) sits in draftLegacyIds but never in the
        // id map (rows are grouping boundaries, never written as records).
        $sharedRow = $this->legacyElement(6000, '', isRow: true);
        $sharedContent = $this->legacyElement(5000, 'Live Title');

        $this->publish($pageId, Page::class, [$sharedRow, $sharedContent], [5000 => true, 6000 => true], $idMap);

        // Only the content element's section exists on LIVE; the row delimiter
        // produced no extra Section/Row/Column record.
        self::assertSame(1, $this->liveSectionCount($pageId), 'No spurious section from the shared row delimiter');
        self::assertSame(1, $this->liveRowCount(), 'Exactly the published content row exists on LIVE');
    }

    public function testMissingSharedElementThrowsNamingDroppedElement(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        // No draft hierarchy written: legacy id 7000 is in draftLegacyIds but has
        // no migrated draft record in the id map — the fail-loud guard must fire.
        $orphan = $this->legacyElement(7000, 'Orphan');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/7000/');

        $this->publish($pageId, Page::class, [$orphan], [7000 => true], $idMap);
    }

    public function testLiveOnlyElementCreatesHierarchyOnBothStagesAfterDraftSections(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        // Draft section (Sort = 1) for a shared element that we will NOT publish.
        $draftElement = $this->legacyElement(5000, 'Draft Title');
        $this->writeDraft($pageId, [$this->section([$draftElement], $this->gridSettings(8), sort: 1)], $idMap);

        // A live-only element (id 8000, absent from draftLegacyIds).
        $liveOnly = $this->legacyElement(8000, 'Live Only', sizeFields: ['MD' => 12]);

        $this->publish($pageId, Page::class, [$liveOnly], [5000 => true], $idMap);

        // A new Section appears on DRAFT (now 2 total) and LIVE (1 — the draft
        // section was never published), sorted after the existing draft section.
        self::assertSame(2, $this->draftSectionCount($pageId), 'Live-only build adds a draft section too');
        self::assertSame(1, $this->liveSectionCount($pageId), 'Only the live-only section reaches LIVE');

        $liveSection = Versioned::get_by_stage(Section::class, Versioned::LIVE)
            ->filter(['ParentID' => $pageId, 'ParentClass' => Page::class, 'Zone' => self::ZONE])
            ->first();
        self::assertInstanceOf(Section::class, $liveSection);
        self::assertSame(2, (int) $liveSection->Sort, 'Live-only section sorts after the draft section (Sort 1)');

        // The live-only content element exists on BOTH stages.
        $draftContent = ContentElement::get()->filter(['Title' => 'Live Only'])->first();
        self::assertInstanceOf(ContentElement::class, $draftContent);
        self::assertInstanceOf(
            ContentElement::class,
            $this->liveById(ContentElement::class, (int) $draftContent->ID),
            'Live-only content element is published to LIVE',
        );
    }

    public function testLiveGridSettingsReconciledOnLiveColumnLeavingDraftUntouched(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        // Draft Column width 8.
        $draftElement = $this->legacyElement(5000, 'Draft Title');
        $this->writeDraft($pageId, [$this->section([$draftElement], $this->gridSettings(8))], $idMap);

        $newColumnId = $idMap->newColumnId(5000);

        // Live element whose Size resolves to width 4 (differs from draft width 8).
        $liveElement = $this->legacyElement(5000, 'Live Title', sizeFields: ['MD' => 4]);

        $this->publish($pageId, Page::class, [$liveElement], [5000 => true], $idMap);

        $draftColumn = Versioned::get_by_stage(Column::class, Versioned::DRAFT)->byID($newColumnId);
        self::assertInstanceOf(Column::class, $draftColumn);
        self::assertSame(8, $draftColumn->getGridSettings()->default->width, 'DRAFT Column width is unchanged');

        $liveColumn = $this->liveById(Column::class, $newColumnId);
        self::assertInstanceOf(Column::class, $liveColumn);
        self::assertSame(4, $liveColumn->getGridSettings()->default->width, 'LIVE Column width derived from live Size');
    }

    public function testDivergentLiveSettingsWithinColumnLogWarningAndUseFirstElement(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        // Draft: two elements grouped into ONE column (Column width 12).
        $draftA = $this->legacyElement(9001, 'Draft A');
        $draftB = $this->legacyElement(9002, 'Draft B');
        $this->writeDraft($pageId, [$this->section([$draftA, $draftB], $this->gridSettings(12))], $idMap);

        $newColumnId = $idMap->newColumnId(9001);
        self::assertSame($newColumnId, $idMap->newColumnId(9002), 'Both draft elements share one Column');

        // Live: same two elements, divergent live widths (6 then 4) → first wins.
        $liveA = $this->legacyElement(9001, 'Live A', sizeFields: ['MD' => 6]);
        $liveB = $this->legacyElement(9002, 'Live B', sizeFields: ['MD' => 4]);

        $this->publish($pageId, Page::class, [$liveA, $liveB], [9001 => true, 9002 => true], $idMap);

        $warnings = $this->warningMessages();
        self::assertNotEmpty(
            \array_filter($warnings, static fn (string $m): bool => \str_contains($m, 'diverge')),
            'A divergence warning is logged for the column',
        );

        $liveColumn = $this->liveById(Column::class, $newColumnId);
        self::assertInstanceOf(Column::class, $liveColumn);
        self::assertSame(6, $liveColumn->getGridSettings()->default->width, "First live element's width wins");
    }

    private function createPage(): int
    {
        $page = Page::create();
        $page->Title = 'Publish Target';
        $page->write();

        return (int) $page->ID;
    }

    /**
     * @param array<string, int> $sizeFields
     */
    private function legacyElement(
        int $id,
        string $title,
        array $sizeFields = ['MD' => 8],
        bool $isRow = false,
        ?LegacyMediaData $media = null,
    ): LegacyElement {
        return new LegacyElement(
            id: $id,
            className: $isRow ? self::ROW_CLASS : self::CONTENT_CLASS,
            title: $title,
            showTitle: true,
            titleTag: 'h2',
            titleClass: '',
            sort: $id,
            extraClass: '',
            isRow: $isRow,
            sizeFields: $sizeFields,
            offsetFields: [],
            visibilityFields: [],
            mediaData: $media,
        );
    }

    private function gridSettings(int $width): GridSettings
    {
        /** @var positive-int $width */
        return new GridSettings(new ViewportConfig($width, 0, true));
    }

    /**
     * Build a single Section → Row → Column DTO holding the given draft elements.
     *
     * @param non-empty-list<LegacyElement> $elements
     */
    private function section(array $elements, GridSettings $gridSettings, int $sort = 1): MigrationSection
    {
        $column = new MigrationColumn($gridSettings, 1, $elements);
        $row = new MigrationRow('', '', 1, [$column]);

        return new MigrationSection('', self::ZONE, '', $sort, [$row]);
    }

    /**
     * @param list<MigrationSection> $sections
     */
    private function writeDraft(int $pageId, array $sections, MigrationIdMap $idMap): void
    {
        Versioned::withVersionedMode(function () use ($pageId, $sections, $idMap): void {
            Versioned::set_stage(Versioned::DRAFT);
            $this->draftWriter->writeDraftHierarchy($pageId, Page::class, self::ZONE, $sections, $idMap);
        });
    }

    /**
     * @param class-string $pageClassName
     * @param list<LegacyElement> $liveElements
     * @param array<int, true> $draftLegacyIds
     */
    private function publish(
        int $pageId,
        string $pageClassName,
        array $liveElements,
        array $draftLegacyIds,
        MigrationIdMap $idMap,
    ): void {
        Versioned::withVersionedMode(function () use (
            $pageId,
            $pageClassName,
            $liveElements,
            $draftLegacyIds,
            $idMap,
        ): void {
            Versioned::set_stage(Versioned::DRAFT);
            $this->publisher->publishToLive(
                $pageId,
                $pageClassName,
                self::ZONE,
                $liveElements,
                $draftLegacyIds,
                $idMap,
                self::DEFAULT_VIEWPORT,
                self::VIEWPORT_KEY_MAP,
            );
        });
    }

    /**
     * @template T of \SilverStripe\ORM\DataObject
     * @param class-string<T> $class
     * @return T|null
     */
    private function liveById(string $class, int $id): ?object
    {
        return Versioned::get_by_stage($class, Versioned::LIVE)->byID($id);
    }

    private function liveSectionCount(int $pageId): int
    {
        return Versioned::get_by_stage(Section::class, Versioned::LIVE)
            ->filter(['ParentID' => $pageId, 'ParentClass' => Page::class, 'Zone' => self::ZONE])
            ->count();
    }

    private function draftSectionCount(int $pageId): int
    {
        return Versioned::get_by_stage(Section::class, Versioned::DRAFT)
            ->filter(['ParentID' => $pageId, 'ParentClass' => Page::class, 'Zone' => self::ZONE])
            ->count();
    }

    private function liveRowCount(): int
    {
        return Versioned::get_by_stage(Row::class, Versioned::LIVE)->count();
    }

    /**
     * @return list<string>
     */
    private function warningMessages(): array
    {
        $result = [];
        foreach ($this->logger->messages as $entry) {
            if ($entry['level'] !== 'warning') {
                continue;
            }
            $result[] = $entry['message'];
        }

        return $result;
    }

    private function cleanGridTables(): void
    {
        $tables = [
            'WeDevelop_Grid_Test_CustomElement', 'WeDevelop_Grid_Test_CustomElement_Live',
            'WeDevelop_Grid_ContentElement', 'WeDevelop_Grid_ContentElement_Live',
            'WeDevelop_Grid_Column', 'WeDevelop_Grid_Column_Live',
            'WeDevelop_Grid_Row', 'WeDevelop_Grid_Row_Live',
            'WeDevelop_Grid_Section', 'WeDevelop_Grid_Section_Live',
            'WeDevelop_Grid_GridElement', 'WeDevelop_Grid_GridElement_Live',
            'WeDevelop_Grid_Test_Page', 'WeDevelop_Grid_Test_Page_Live',
        ];

        $allTables = DB::table_list();

        foreach ($tables as $table) {
            if (\array_key_exists(\strtolower($table), $allTables)) {
                DB::query("DELETE FROM \"{$table}\"");
            }
        }
    }
}

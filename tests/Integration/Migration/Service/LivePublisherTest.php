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
        $this->expectExceptionMessage(
            'Shared legacy element 7000 has no migrated draft record; '
            . 'the configured row mapping strategy dropped a content element.',
        );

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

        $warnings = $this->entriesAtLevel('warning');
        self::assertCount(1, $warnings);
        self::assertSame(
            'Live grid settings diverge within migrated column {columnId}; '
            . 'using the first element\'s settings (a single column cannot express multiple widths).',
            $warnings[0]['message'],
        );
        self::assertSame(['columnId' => $newColumnId], $warnings[0]['context']);

        $liveColumn = $this->liveById(Column::class, $newColumnId);
        self::assertInstanceOf(Column::class, $liveColumn);
        self::assertSame(6, $liveColumn->getGridSettings()->default->width, "First live element's width wins");
    }

    public function testLiveContentOverwriteWritesShowTitleFlagAndTitleTagFallback(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $draftA = $this->legacyElement(5000, 'Draft A');
        $draftB = $this->legacyElement(5001, 'Draft B');
        $this->writeDraft($pageId, [$this->section([$draftA, $draftB], $this->gridSettings(8))], $idMap);

        // 5000: hidden title and a blank tag (must fall back to h2).
        // 5001: shown title with an explicit tag (must be preserved verbatim).
        $liveA = $this->legacyElement(5000, 'Live A', showTitle: false, titleTag: '');
        $liveB = $this->legacyElement(5001, 'Live B', showTitle: true, titleTag: 'h3');

        $this->publish($pageId, Page::class, [$liveA, $liveB], [5000 => true, 5001 => true], $idMap);

        $liveAContent = $this->liveById(ContentElement::class, $idMap->newElementId(5000));
        self::assertInstanceOf(ContentElement::class, $liveAContent);
        self::assertSame(0, (int) $liveAContent->ShowTitle);
        self::assertSame('h2', (string) $liveAContent->TitleTag);

        $liveBContent = $this->liveById(ContentElement::class, $idMap->newElementId(5001));
        self::assertInstanceOf(ContentElement::class, $liveBContent);
        self::assertSame(1, (int) $liveBContent->ShowTitle);
        self::assertSame('h3', (string) $liveBContent->TitleTag);
    }

    public function testLiveContentOverwriteClearsHtmlAndAppliesMappedMediaFields(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $draftElement = $this->legacyElement(
            5000,
            'Draft Title',
            media: new LegacyMediaData(['HTML' => '<p>Draft HTML</p>', 'ContentColumns' => '1']),
        );
        $this->writeDraft($pageId, [$this->section([$draftElement], $this->gridSettings(8))], $idMap);

        // The live row carries no HTML key at all: HTML must be reset to '' rather than
        // inheriting the draft copy that writeToStage() just made. ContentColumns proves
        // the mapped media fields are applied on top.
        $liveElement = $this->legacyElement(
            5000,
            'Live Title',
            media: new LegacyMediaData(['ContentColumns' => '3']),
        );

        $this->publish($pageId, Page::class, [$liveElement], [5000 => true], $idMap);

        $liveContent = $this->liveById(ContentElement::class, $idMap->newElementId(5000));
        self::assertInstanceOf(ContentElement::class, $liveContent);
        self::assertSame('', (string) $liveContent->HTML, 'draft HTML must not leak onto live');
        self::assertSame(3, (int) $liveContent->ContentColumns, 'mapped media fields must be written to live');
    }

    public function testPublishRecordsEveryContainerAsPublishedInTheIdMap(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $draftElement = $this->legacyElement(5000, 'Draft Title');
        $this->writeDraft($pageId, [$this->section([$draftElement], $this->gridSettings(8))], $idMap);

        $columnId = $idMap->newColumnId(5000);
        $column = Column::get()->byID($columnId);
        self::assertInstanceOf(Column::class, $column);
        $rowId = (int) $column->ParentID;
        $row = Row::get()->byID($rowId);
        self::assertInstanceOf(Row::class, $row);
        $sectionId = (int) $row->ParentID;

        $this->publish($pageId, Page::class, [$this->legacyElement(5000, 'Live')], [5000 => true], $idMap);

        // The published-container bookkeeping is what stops the chain being re-published
        // once per shared element in the same column.
        self::assertTrue($idMap->isContainerPublished($columnId), 'column marked published');
        self::assertTrue($idMap->isContainerPublished($rowId), 'row marked published');
        self::assertTrue($idMap->isContainerPublished($sectionId), 'section marked published');
    }

    public function testPublishToleratesAMissingSectionInTheContainerChain(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $draftElement = $this->legacyElement(5000, 'Draft Title');
        $this->writeDraft($pageId, [$this->section([$draftElement], $this->gridSettings(8))], $idMap);

        $columnId = $idMap->newColumnId(5000);
        $column = Column::get()->byID($columnId);
        self::assertInstanceOf(Column::class, $column);
        $row = Row::get()->byID((int) $column->ParentID);
        self::assertInstanceOf(Row::class, $row);

        // Dangle the Row's parent at a Section that does not exist (deleting the real one
        // would cascade to the row and column). The chain walk must skip the missing
        // ancestor rather than dereference it, and still publish the row and column.
        $sectionId = 987654;
        $row->ParentID = $sectionId;
        $row->write();

        $this->publish($pageId, Page::class, [$this->legacyElement(5000, 'Live')], [5000 => true], $idMap);

        self::assertInstanceOf(Column::class, $this->liveById(Column::class, $columnId));
        self::assertInstanceOf(Row::class, $this->liveById(Row::class, (int) $row->ID));
        self::assertFalse($idMap->isContainerPublished($sectionId), 'a missing section is never marked published');
    }

    public function testLiveOnlyElementBeforeASharedElementDoesNotStopSharedPublishing(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $draftElement = $this->legacyElement(5000, 'Draft Title');
        $this->writeDraft($pageId, [$this->section([$draftElement], $this->gridSettings(8))], $idMap);

        // The live-only element is collected and skipped; the shared element behind it
        // must still be published rather than the loop terminating.
        $liveOnly = $this->legacyElement(8000, 'Live Only');
        $shared = $this->legacyElement(5000, 'Live Title');

        $this->publish($pageId, Page::class, [$liveOnly, $shared], [5000 => true], $idMap);

        $liveContent = $this->liveById(ContentElement::class, $idMap->newElementId(5000));
        self::assertInstanceOf(ContentElement::class, $liveContent);
        self::assertSame('Live Title', (string) $liveContent->Title);
    }

    public function testLiveOnlyBuildLogsTheCountAndPublishesTheWholeContainerChain(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $liveOnly = $this->legacyElement(8000, 'Live Only', sizeFields: ['MD' => 12]);

        $this->publish($pageId, Page::class, [$liveOnly], [], $idMap);

        $infos = $this->entriesAtLevel('info');
        self::assertCount(1, $infos);
        self::assertSame(
            'Page {pageId}: {liveOnlyCount} live-only legacy element(s) found; '
            . 'adjacent same-settings elements may be grouped into shared columns — verify live layout.',
            $infos[0]['message'],
        );
        self::assertSame(['pageId' => $pageId, 'liveOnlyCount' => 1], $infos[0]['context']);

        // Section, Row, Column and the element itself must all reach LIVE.
        $liveSection = Versioned::get_by_stage(Section::class, Versioned::LIVE)
            ->filter(['ParentID' => $pageId, 'ParentClass' => Page::class, 'Zone' => self::ZONE])
            ->first();
        self::assertInstanceOf(Section::class, $liveSection);
        self::assertSame(1, (int) $liveSection->Sort, 'first section on an empty page sorts at 1');

        $liveRow = Versioned::get_by_stage(Row::class, Versioned::LIVE)
            ->filter(['ParentID' => (int) $liveSection->ID])
            ->first();
        self::assertInstanceOf(Row::class, $liveRow);

        $liveColumn = Versioned::get_by_stage(Column::class, Versioned::LIVE)
            ->filter(['ParentID' => (int) $liveRow->ID])
            ->first();
        self::assertInstanceOf(Column::class, $liveColumn);

        $draftContent = ContentElement::get()->filter(['Title' => 'Live Only'])->first();
        self::assertInstanceOf(ContentElement::class, $draftContent);
        self::assertSame(1, (int) $draftContent->Sort, 'first element in a live-only column sorts at 1');

        $liveContent = $this->liveById(ContentElement::class, (int) $draftContent->ID);
        self::assertInstanceOf(ContentElement::class, $liveContent);
        self::assertSame((int) $liveColumn->ID, (int) $liveContent->ParentID);

        // The freshly created containers are recorded as published, so a later shared
        // element landing in the same chain does not re-publish them.
        self::assertTrue($idMap->isContainerPublished((int) $liveSection->ID), 'live-only section marked published');
        self::assertTrue($idMap->isContainerPublished((int) $liveRow->ID), 'live-only row marked published');
        self::assertTrue($idMap->isContainerPublished((int) $liveColumn->ID), 'live-only column marked published');
    }

    public function testLiveOnlySectionSortIgnoresSectionsBelongingToOtherPages(): void
    {
        $otherPageId = $this->createPage();
        $targetPageId = $this->createPage();
        $idMap = new MigrationIdMap();

        // A draft section on an unrelated page, sorted high. The next-sort lookup must
        // scope to the target page, otherwise the live-only section is pushed past it.
        $otherElement = $this->legacyElement(5000, 'Other Page Draft');
        $this->writeDraft($otherPageId, [$this->section([$otherElement], $this->gridSettings(8), sort: 7)], $idMap);

        $liveOnly = $this->legacyElement(8000, 'Live Only');
        $this->publish($targetPageId, Page::class, [$liveOnly], [], $idMap);

        $liveSection = Versioned::get_by_stage(Section::class, Versioned::LIVE)
            ->filter(['ParentID' => $targetPageId, 'ParentClass' => Page::class, 'Zone' => self::ZONE])
            ->first();
        self::assertInstanceOf(Section::class, $liveSection);
        self::assertSame(1, (int) $liveSection->Sort, 'the target page has no sections, so the first sort is 1');
    }

    public function testReconciliationSkipsUnchangedColumnsWithoutSkippingLaterOnes(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        // Two sections → two columns. Both drafted at width 8.
        $draftA = $this->legacyElement(5000, 'Draft A');
        $draftB = $this->legacyElement(5001, 'Draft B');
        $this->writeDraft(
            $pageId,
            [
                $this->section([$draftA], $this->gridSettings(8), sort: 1),
                $this->section([$draftB], $this->gridSettings(8), sort: 2),
            ],
            $idMap,
        );

        $columnA = $idMap->newColumnId(5000);
        $columnB = $idMap->newColumnId(5001);

        // Column A's live settings match the draft (skipped); column B's differ and must
        // still be reconciled — a `break` on the first match would leave B at width 8.
        $liveA = $this->legacyElement(5000, 'Live A', sizeFields: ['MD' => 8]);
        $liveB = $this->legacyElement(5001, 'Live B', sizeFields: ['MD' => 4]);

        $this->publish($pageId, Page::class, [$liveA, $liveB], [5000 => true, 5001 => true], $idMap);

        $liveColumnA = $this->liveById(Column::class, $columnA);
        self::assertInstanceOf(Column::class, $liveColumnA);
        self::assertSame(8, $liveColumnA->getGridSettings()->default->width);

        $liveColumnB = $this->liveById(Column::class, $columnB);
        self::assertInstanceOf(Column::class, $liveColumnB);
        self::assertSame(4, $liveColumnB->getGridSettings()->default->width, 'a later divergent column must still reconcile');
    }

    public function testReconciliationStoresTheVisibilityFlagAsAStrictZeroOrOne(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $draftA = $this->legacyElement(5000, 'Draft A');
        $draftB = $this->legacyElement(5001, 'Draft B');
        $this->writeDraft(
            $pageId,
            [
                $this->section([$draftA], $this->gridSettings(8), sort: 1),
                $this->section([$draftB], $this->gridSettings(8), sort: 2),
            ],
            $idMap,
        );

        // A: hidden on live. B: visible on live but a different width, so both columns
        // are actually rewritten by the reconcile pass.
        $liveA = $this->legacyElement(5000, 'Live A', visibilityFields: ['MD' => 'hidden']);
        $liveB = $this->legacyElement(5001, 'Live B', sizeFields: ['MD' => 4]);

        $this->publish($pageId, Page::class, [$liveA, $liveB], [5000 => true, 5001 => true], $idMap);

        // Asserted against the raw column: this is a hand-written SQL UPDATE, and the
        // Boolean column would read back a stray 2 as `true` through the ORM.
        self::assertSame(0, $this->liveVisibilityFlag($idMap->newColumnId(5000)));
        self::assertSame(1, $this->liveVisibilityFlag($idMap->newColumnId(5001)));
    }

    public function testReconciliationPersistsLiveViewportOverrides(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $draftElement = $this->legacyElement(5000, 'Draft Title');
        $this->writeDraft($pageId, [$this->section([$draftElement], $this->gridSettings(8))], $idMap);

        $newColumnId = $idMap->newColumnId(5000);

        // A second viewport width produces an override on top of the default.
        $liveElement = $this->legacyElement(5000, 'Live Title', sizeFields: ['MD' => 8, 'LG' => 4]);

        $this->publish($pageId, Page::class, [$liveElement], [5000 => true], $idMap);

        $liveColumn = $this->liveById(Column::class, $newColumnId);
        self::assertInstanceOf(Column::class, $liveColumn);

        $overrides = $liveColumn->getGridSettings()->overrides;
        self::assertArrayHasKey('lg', $overrides, 'non-empty overrides must be persisted as JSON, not NULL');
        self::assertSame(4, $overrides['lg']->width);
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
        bool $showTitle = true,
        string $titleTag = 'h2',
        array $visibilityFields = [],
    ): LegacyElement {
        return new LegacyElement(
            id: $id,
            className: $isRow ? self::ROW_CLASS : self::CONTENT_CLASS,
            title: $title,
            showTitle: $showTitle,
            titleTag: $titleTag,
            titleClass: '',
            sort: $id,
            extraClass: '',
            isRow: $isRow,
            sizeFields: $sizeFields,
            offsetFields: [],
            visibilityFields: $visibilityFields,
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

    /**
     * Read GridSettingsDefaultVisible straight out of the Column _Live table, bypassing
     * the DBBoolean cast that would flatten any non-0/1 integer to `true`.
     */
    private function liveVisibilityFlag(int $columnId): int
    {
        $table = \SilverStripe\ORM\DataObject::getSchema()->tableName(Column::class) . '_Live';
        $row = DB::prepared_query(
            \sprintf('SELECT "GridSettingsDefaultVisible" FROM "%s" WHERE "ID" = ?', $table),
            [$columnId],
        )->record();

        self::assertIsArray($row);

        return (int) $row['GridSettingsDefaultVisible'];
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

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function entriesAtLevel(string $level): array
    {
        return \array_values(\array_filter(
            $this->logger->messages,
            static fn (array $entry): bool => $entry['level'] === $level,
        ));
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

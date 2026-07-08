<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\Service\DraftHierarchyWriter;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\DTO\MappedMediaFields;
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Strategy\AllRowsInSectionStrategy;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(GridMigrationService::class)]
#[CoversClass(MappedMediaFields::class)]
final class GridMigrationServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    protected static $extra_dataobjects = [TestCustomElement::class, TestPage::class];

    // Disable SapphireTest's per-test transaction wrapping. The migration
    // service uses its own transactions, and the LegacyTableSeeder's DDL
    // (CREATE TABLE) auto-commits in MySQL, which breaks savepoint-based
    // transaction nesting.
    protected $usesTransactions = false;

    private const string DEFAULT_VIEWPORT = 'MD';

    private const string ZONE = 'main';

    private const array VIEWPORT_KEY_MAP = [
        'XS' => 'xs',
        'SM' => 'sm',
        'MD' => 'md',
        'LG' => 'lg',
        'XL' => 'xl',
    ];

    private const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    private const string ROW_CLASS = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

    private LegacyTableSeeder $seeder;

    private LegacyDataReader $reader;

    private FieldMapper $mapper;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->truncateTables();

        // Clean ORM grid tables from previous tests. DDL in createTables()
        // may have committed the SapphireTest transaction, so ORM records
        // from previous tests are not reliably rolled back.
        $this->cleanGridTables();

        $this->reader = new LegacyDataReader();
        $this->mapper = new FieldMapper();
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
    }

    protected function tearDown(): void
    {
        $this->seeder->removeExtensionColumns('Page');
        $this->seeder->dropTables();

        parent::tearDown();
    }

    /**
     * Remove all records from GridElement and related tables to prevent leaking
     * between tests (this class runs without SapphireTest's transaction rollback
     * because the seeder's DDL auto-commits).
     *
     * The table list is derived from the class manifest — every GridElement
     * subclass plus the test-only extra_dataobjects, each with its base and
     * _Live table — so adding a new element table or test DataObject can never
     * silently leak rows across tests here.
     */
    private function cleanGridTables(): void
    {
        $schema = DataObject::getSchema();

        /** @var list<class-string<DataObject>> $classes */
        $classes = \array_values(\array_unique([
            ...\array_values(ClassInfo::subclassesFor(GridElement::class)),
            ...static::$extra_dataobjects,
        ]));

        $allTables = DB::table_list();

        foreach ($classes as $class) {
            $table = $schema->tableName($class);
            if ($table === '') {
                continue;
            }

            foreach ([$table, $table . '_Live'] as $candidate) {
                if (\array_key_exists(\strtolower($candidate), $allTables)) {
                    DB::query("DELETE FROM \"{$candidate}\"");
                }
            }
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function createStrategy(): RowPerSectionStrategy
    {
        return new RowPerSectionStrategy(
            new ElementGrouper(),
            $this->mapper,
            self::DEFAULT_VIEWPORT,
            self::VIEWPORT_KEY_MAP,
        );
    }

    private function createAllRowsStrategy(): AllRowsInSectionStrategy
    {
        return new AllRowsInSectionStrategy(
            new ElementGrouper(),
            $this->mapper,
            self::DEFAULT_VIEWPORT,
            self::VIEWPORT_KEY_MAP,
            $this->logger,
        );
    }

    private function createService(?RowMappingStrategy $strategy = null): GridMigrationService
    {
        return new GridMigrationService(
            $this->reader,
            $this->mapper,
            $strategy ?? $this->createStrategy(),
            $this->logger,
        );
    }

    private function getPageId(): int
    {
        return (int) $this->objFromFixture(Page::class, 'test_page')->ID;
    }

    private function getPageId2(): int
    {
        return (int) $this->objFromFixture(Page::class, 'test_page_2')->ID;
    }

    /**
     * Get logged messages filtered by level, with PSR-3 placeholders interpolated.
     *
     * @return list<string>
     */
    private function getLogMessages(string $level): array
    {
        $result = [];
        foreach ($this->logger->messages as $entry) {
            if ($entry['level'] !== $level) {
                continue;
            }
            $replacements = [];
            foreach ($entry['context'] as $key => $value) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
            $result[] = \strtr($entry['message'], $replacements);
        }
        return $result;
    }

    private function runMigration(?GridMigrationService $service = null, ?int $pageId = null): void
    {
        $service = $service ?? $this->createService();
        $targetPageId = $pageId ?? $this->getPageId();

        $failures = $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: false,
            pageIds: [$targetPageId],
        );
        self::assertSame(0, $failures, 'Migration should complete without failures');
    }

    /**
     * Seed a standard page with one row and two content elements.
     */
    private function seedStandardPage(int $pageId, int $areaId = 100): void
    {
        $this->seeder->seedPage($pageId, $areaId);

        // Row element
        $this->seeder->seedElement(1000, $areaId, self::ROW_CLASS, 1, [
            'Title' => 'Test Row',
            'ExtraClass' => 'row-class',
        ]);
        $this->seeder->seedRow(1000, isFluid: false, customSectionClass: 'section-class');

        // Content element 1
        $this->seeder->seedElement(1001, $areaId, self::CONTENT_CLASS, 2, [
            'Title' => 'Element One',
            'ShowTitle' => 1,
            'TitleTag' => 'h3',
            'TitleClass' => 'text-lg',
            'ExtraClass' => 'extra-one',
            'SizeMD' => 8,
            'OffsetMD' => 2,
        ]);
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>Hello</p>']);

        // Content element 2
        $this->seeder->seedElement(1002, $areaId, self::CONTENT_CLASS, 3, [
            'Title' => 'Element Two',
            'ShowTitle' => 0,
            'TitleTag' => 'h2',
            'TitleClass' => '',
            'ExtraClass' => '',
            'SizeMD' => 4,
        ]);
        $this->seeder->seedContentMedia(1002, ['HTML' => '<p>World</p>']);
    }

    // ─── Test Group 1: Basic draft migration (tests 1-5) ─────────

    public function testEndToEndDraftMigrationCreatesHierarchy(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);
        $this->runMigration();

        // Verify Section exists
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => Page::class,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(1, $sections);

        // Verify Row under Section
        $section = $sections->first();
        self::assertInstanceOf(Section::class, $section);
        $rows = Row::get()->filter([
            'ParentID' => $section->ID,
            'ParentClass' => Section::class,
        ]);
        self::assertCount(1, $rows);

        // Verify Columns under Row
        $row = $rows->first();
        self::assertInstanceOf(Row::class, $row);
        $columns = Column::get()->filter([
            'ParentID' => $row->ID,
            'ParentClass' => Row::class,
        ]);
        self::assertCount(2, $columns);

        // Verify content elements under Columns
        foreach ($columns as $column) {
            $elements = GridElement::get()->filter([
                'ParentID' => $column->ID,
                'ParentClass' => Column::class,
            ]);
            self::assertCount(1, $elements);
        }
    }

    public function testGridSettingsConvertedCorrectlyOnColumns(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);
        $this->runMigration();

        // First element had SizeMD=8, OffsetMD=2
        $section = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->first();
        self::assertInstanceOf(Section::class, $section);

        $row = Row::get()->filter(['ParentID' => $section->ID])->first();
        self::assertInstanceOf(Row::class, $row);

        $columns = Column::get()->filter(['ParentID' => $row->ID])->sort('Sort', 'ASC');
        $firstColumn = $columns->first();
        self::assertInstanceOf(Column::class, $firstColumn);

        $settings = $firstColumn->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertSame(2, $settings->default->offset);
    }

    public function testMediaFieldsMappedCorrectlyOnContentElement(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(2000, $areaId, self::CONTENT_CLASS, 1, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(2000, [
            'HTML' => '<p>Test</p>',
            'ContentColumns' => '6',
            'ContentVerticalAlign' => 'align-items-center',
            'ExtraColumnGap' => 5,
            'MediaType' => 'image',
            'MediaRatio' => '16x9',
            'MediaPosition' => 'order-1',
            'MediaImageID' => 42,
            'MediaCaption' => 'Sample caption',
            'MediaVideoFullURL' => 'https://example.com/video.mp4',
            'MediaVideoProvider' => 'youtube',
            'MediaVideoHasOverlay' => 1,
            'MediaVideoCustomThumbnailID' => 99,
            'MediaVideoEmbeddedName' => 'Embed Name',
            'MediaVideoEmbeddedURL' => 'https://embed.example.com',
            'MediaVideoEmbeddedDescription' => 'Embed Description',
            'MediaVideoEmbeddedThumbnail' => 'https://thumb.example.com',
            'MediaVideoEmbeddedCreated' => '2024-01-15',
        ]);

        $this->runMigration();

        // Find the created ContentElement
        $contentElement = ContentElement::get()->filter([
            'ParentClass' => Column::class,
        ])->first();
        self::assertInstanceOf(ContentElement::class, $contentElement, 'ContentElement should exist with ParentClass=Column');

        // Verify every field MappedMediaFields::toArray() emits lands on the element
        // with the correct value. Distinct per-field values mean a dropped or
        // mis-keyed column in toArray() (the serialization is a hand-written map)
        // fails an assertion here — this is the end-to-end equivalent of the former
        // MappedMediaFieldsTest::toArray completeness check.
        self::assertSame(6, (int) $contentElement->ContentColumns);
        self::assertSame('center', $contentElement->VerticalAlignment);
        self::assertSame(2, (int) $contentElement->GapSize);
        self::assertSame('image', $contentElement->MediaType);
        self::assertSame('Sample caption', $contentElement->MediaCaption);
        self::assertSame('16x9', $contentElement->MediaRatio);
        self::assertSame('first', $contentElement->MediaPosition);
        self::assertSame(42, (int) $contentElement->MediaImageID);
        self::assertSame('https://example.com/video.mp4', $contentElement->VideoURL);
        self::assertSame('youtube', $contentElement->VideoProvider);
        self::assertTrue((bool) $contentElement->VideoHasOverlay);
        self::assertSame(99, (int) $contentElement->VideoCustomThumbnailID);
        self::assertSame('Embed Name', $contentElement->VideoEmbedName);
        self::assertSame('https://embed.example.com', $contentElement->VideoEmbedURL);
        self::assertSame('Embed Description', $contentElement->VideoEmbedDescription);
        self::assertSame('https://thumb.example.com', $contentElement->VideoEmbedThumbnail);
        self::assertSame('2024-01-15', $contentElement->VideoEmbedCreated);
    }

    public function testSortOrderPreservedThroughHierarchy(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Two rows with elements — use distinct grid settings so they don't group
        $this->seeder->seedElement(3000, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(3000);
        $this->seeder->seedElement(3001, $areaId, self::CONTENT_CLASS, 2, ['SizeMD' => 6, 'Title' => 'First']);
        $this->seeder->seedContentMedia(3001);
        $this->seeder->seedElement(3002, $areaId, self::CONTENT_CLASS, 3, ['SizeMD' => 4, 'Title' => 'Second']);
        $this->seeder->seedContentMedia(3002);

        $this->seeder->seedElement(3010, $areaId, self::ROW_CLASS, 4);
        $this->seeder->seedRow(3010);
        $this->seeder->seedElement(3011, $areaId, self::CONTENT_CLASS, 5, ['SizeMD' => 12, 'Title' => 'Third']);
        $this->seeder->seedContentMedia(3011);

        $this->runMigration();

        // Verify sections are sorted
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->sort('Sort', 'ASC');
        self::assertCount(2, $sections);
        self::assertSame(1, (int) $sections->first()->Sort);
        self::assertSame(2, (int) $sections->last()->Sort);

        // Verify columns in first row maintain sort (distinct widths → 2 columns)
        $firstRow = Row::get()->filter(['ParentID' => $sections->first()->ID])->first();
        self::assertInstanceOf(Row::class, $firstRow);
        $columns = Column::get()->filter(['ParentID' => $firstRow->ID])->sort('Sort', 'ASC');
        self::assertCount(2, $columns);
        self::assertSame(1, (int) $columns->first()->Sort);
        self::assertSame(2, (int) $columns->last()->Sort);
    }

    public function testScalarFieldsCarriedOverToNewElements(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);
        $this->runMigration();

        // Find the first content element (Title = 'Element One')
        $element = ContentElement::get()->filter([
            'Title' => 'Element One',
            'ParentClass' => Column::class,
        ])->first();
        self::assertInstanceOf(ContentElement::class, $element);

        self::assertSame('Element One', $element->Title);
        self::assertTrue((bool) $element->ShowTitle);
        self::assertSame('h3', $element->TitleTag);
        self::assertSame('text-lg', $element->TitleClass);
        self::assertSame('extra-one', $element->ExtraClass);
    }

    // ─── Test Group 2: Table migration verification (tests 6-10) ─

    public function testContentElementDataInGridElementTables(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);
        $this->runMigration();

        // Content elements should be in GridElement + ContentElement tables
        $element = ContentElement::get()->filter(['Title' => 'Element One'])->first();
        self::assertInstanceOf(ContentElement::class, $element);
        self::assertSame(ContentElement::class, $element->ClassName);
    }

    public function testContentElementsHaveNewIds(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);
        $this->runMigration();

        // Old element IDs were 1001 and 1002 — new elements must have different IDs
        $elements = ContentElement::get()->filter(['ParentClass' => Column::class]);
        foreach ($elements as $element) {
            self::assertNotSame(1001, (int) $element->ID);
            self::assertNotSame(1002, (int) $element->ID);
        }
    }

    public function testOldBaseElementRecordsLeftUntouched(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);
        $this->runMigration();

        // Old BaseElement records should still exist
        $result = \SilverStripe\ORM\DB::prepared_query(
            'SELECT COUNT(*) AS cnt FROM "BaseElement" WHERE "ParentID" = ?',
            [100],
        );
        $count = (int) $result->record()['cnt'];
        self::assertSame(3, $count); // 1 row + 2 content elements
    }

    public function testSubclassDataMigrated(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);
        $this->runMigration();

        // Verify HTML field migrated from ElementContent to ContentElement
        $element = ContentElement::get()->filter(['Title' => 'Element One'])->first();
        self::assertInstanceOf(ContentElement::class, $element);
        self::assertSame('<p>Hello</p>', $element->HTML);
    }

    public function testHasOneRelationIdsPreserved(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(4000, $areaId, self::CONTENT_CLASS, 1, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(4000, [
            'MediaImageID' => 42,
            'MediaVideoCustomThumbnailID' => 99,
        ]);

        $this->runMigration();

        $element = ContentElement::get()->filter(['ParentClass' => Column::class])->first();
        self::assertInstanceOf(ContentElement::class, $element);
        self::assertSame(42, (int) $element->MediaImageID);
        self::assertSame(99, (int) $element->VideoCustomThumbnailID);
    }

    // ─── Test Group 3: Stage handling (tests 11-18) ──────────────

    public function testDraftAndLiveSameNewId(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Seed same element on both stages with different content
        $this->seeder->seedElement(5000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'Title' => 'Draft Title',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(5000, ['HTML' => '<p>Draft HTML</p>'], stage: 'draft');

        $this->seeder->seedElement(5000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'Title' => 'Live Title',
        ], stage: 'live');
        $this->seeder->seedContentMedia(5000, ['HTML' => '<p>Live HTML</p>'], stage: 'live');

        $this->runMigration();

        // Check draft has draft content
        Versioned::set_stage(Versioned::DRAFT);
        $draftElement = ContentElement::get()->filter(['ParentClass' => Column::class])->first();
        self::assertInstanceOf(ContentElement::class, $draftElement);
        $draftId = (int) $draftElement->ID;
        self::assertSame('Draft Title', $draftElement->Title);
        self::assertSame('<p>Draft HTML</p>', $draftElement->HTML);

        // Check live has live content
        Versioned::set_stage(Versioned::LIVE);
        $liveElement = ContentElement::get()->filter(['ParentClass' => Column::class])->first();
        self::assertInstanceOf(ContentElement::class, $liveElement);
        $liveId = (int) $liveElement->ID;
        self::assertSame('Live Title', $liveElement->Title);
        self::assertSame('<p>Live HTML</p>', $liveElement->HTML);

        // Same ID on both stages
        self::assertSame($draftId, $liveId);
    }

    public function testDraftOnlyExistsOnlyInDraft(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Only seed on draft stage
        $this->seeder->seedElement(5100, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Draft Only',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(5100, [], stage: 'draft');

        $this->runMigration();

        // Should exist on draft
        Versioned::set_stage(Versioned::DRAFT);
        $draftElements = ContentElement::get()->filter(['Title' => 'Draft Only']);
        self::assertCount(1, $draftElements);

        // Should NOT exist on live
        Versioned::set_stage(Versioned::LIVE);
        $liveElements = ContentElement::get()->filter(['Title' => 'Draft Only']);
        self::assertCount(0, $liveElements);
    }

    public function testLiveOnlyCreatesRecordsOnBothStages(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Only seed on live stage (not on draft)
        $this->seeder->seedElement(5200, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Live Only',
        ], stage: 'live');
        $this->seeder->seedContentMedia(5200, ['HTML' => '<p>Live content</p>'], stage: 'live');

        $this->runMigration();

        // Should exist on draft (Versioned integrity)
        Versioned::set_stage(Versioned::DRAFT);
        $draftElements = ContentElement::get()->filter(['ParentClass' => Column::class]);
        self::assertGreaterThanOrEqual(1, $draftElements->count());

        // Should exist on live
        Versioned::set_stage(Versioned::LIVE);
        $liveElements = ContentElement::get()->filter(['ParentClass' => Column::class]);
        self::assertGreaterThanOrEqual(1, $liveElements->count());
    }

    public function testLiveOnlyContentGetsSameGroupedStructureAsDraftPath(): void
    {
        // Live-only content must flow through the same ElementGrouper + strategy
        // as the draft path, NOT a flat one-element-per-section chain.
        //
        // Layout (live-only): [E1(w6), E2(w6)] then a row boundary then [E3(w12)].
        // Expected grouped structure (RowPerSectionStrategy):
        //   Section 1 → Row → Column [E1, E2]  (consecutive identical width=6 grouped)
        //   Section 2 → Row → Column [E3]      (row delimiter starts a new section)
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Two consecutive content elements with identical grid settings (live-only)
        $this->seeder->seedElement(6200, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'LO First',
        ], stage: 'live');
        $this->seeder->seedContentMedia(6200, [], stage: 'live');
        $this->seeder->seedElement(6201, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'LO Second',
        ], stage: 'live');
        $this->seeder->seedContentMedia(6201, [], stage: 'live');

        // A row delimiter, then a third element (live-only)
        $this->seeder->seedElement(6202, $areaId, self::ROW_CLASS, 3, stage: 'live');
        $this->seeder->seedRow(6202, stage: 'live');
        $this->seeder->seedElement(6203, $areaId, self::CONTENT_CLASS, 4, [
            'SizeMD' => 12,
            'Title' => 'LO Third',
        ], stage: 'live');
        $this->seeder->seedContentMedia(6203, [], stage: 'live');

        $this->runMigration();

        // The grouped structure must exist on BOTH stages (Versioned integrity)
        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            Versioned::set_stage($stage);

            $sections = Section::get()->filter([
                'ParentID' => $pageId,
                'Zone' => self::ZONE,
            ])->sort('Sort', 'ASC');
            self::assertCount(2, $sections, "Stage {$stage}: row boundary should split into 2 sections, not a flat chain");

            $sectionList = $sections->toArray();

            // Section 1: one row, one column holding BOTH grouped elements
            $firstRow = Row::get()->filter(['ParentID' => $sectionList[0]->ID])->first();
            self::assertInstanceOf(Row::class, $firstRow);
            $firstColumns = Column::get()->filter(['ParentID' => $firstRow->ID]);
            self::assertCount(1, $firstColumns, "Stage {$stage}: identical-grid elements should share one column");
            $firstColumn = $firstColumns->first();
            self::assertInstanceOf(Column::class, $firstColumn);
            self::assertSame(6, $firstColumn->getGridSettings()->default->width);
            $firstColumnElements = ContentElement::get()
                ->filter(['ParentID' => $firstColumn->ID])
                ->sort('Sort', 'ASC');
            self::assertCount(2, $firstColumnElements, "Stage {$stage}: both grouped elements live in the shared column");
            self::assertSame('LO First', $firstColumnElements->first()->Title);
            self::assertSame('LO Second', $firstColumnElements->last()->Title);

            // Section 2: separate section (created by the row boundary), width=12
            $secondRow = Row::get()->filter(['ParentID' => $sectionList[1]->ID])->first();
            self::assertInstanceOf(Row::class, $secondRow);
            $secondColumn = Column::get()->filter(['ParentID' => $secondRow->ID])->first();
            self::assertInstanceOf(Column::class, $secondColumn);
            self::assertSame(12, $secondColumn->getGridSettings()->default->width);
            $thirdElement = ContentElement::get()->filter(['ParentID' => $secondColumn->ID])->first();
            self::assertInstanceOf(ContentElement::class, $thirdElement);
            self::assertSame('LO Third', $thirdElement->Title);
        }
    }

    public function testLiveOnlySectionsAppendAfterDraftSections(): void
    {
        // When a page has both draft content and additional live-only content,
        // the live-only Sections must be sorted AFTER the draft Sections (offset
        // past existing sort values), not collide at Sort=1.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Draft + live shared element (becomes Section sort 1)
        foreach (['draft', 'live'] as $stage) {
            $this->seeder->seedElement(6300, $areaId, self::CONTENT_CLASS, 1, [
                'SizeMD' => 12,
                'Title' => 'Shared',
            ], stage: $stage);
            $this->seeder->seedContentMedia(6300, [], stage: $stage);
        }

        // Live-only element (must append as a new Section after the shared one)
        $this->seeder->seedElement(6301, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 8,
            'Title' => 'Live Extra',
        ], stage: 'live');
        $this->seeder->seedContentMedia(6301, [], stage: 'live');

        $this->runMigration();

        Versioned::set_stage(Versioned::LIVE);
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->sort('Sort', 'ASC');
        self::assertCount(2, $sections, 'Live stage: one shared section + one live-only section');

        $sortValues = [];
        foreach ($sections as $section) {
            $sortValues[] = (int) $section->Sort;
        }
        // Distinct, ascending sort values — live-only section appended, not colliding
        self::assertSame([1, 2], $sortValues, 'Live-only section must append after the draft section');
    }

    public function testLiveOnlyElementsAreInfoLoggedForSpotCheck(): void
    {
        // Positive case: a page with live-only elements must emit an info log
        // naming the page ID and element count so an operator can spot-check
        // that the adjacent same-settings grouping produced the expected layout.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Two elements that exist only on live (no draft counterpart).
        $this->seeder->seedElement(7100, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Live Only A',
        ], stage: 'live');
        $this->seeder->seedContentMedia(7100, [], stage: 'live');
        $this->seeder->seedElement(7101, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'Live Only B',
        ], stage: 'live');
        $this->seeder->seedContentMedia(7101, [], stage: 'live');

        $this->runMigration();

        $liveOnlyLog = null;
        foreach ($this->getLogMessages('info') as $msg) {
            if (\str_contains($msg, 'live-only')) {
                $liveOnlyLog = $msg;
                break;
            }
        }
        self::assertNotNull($liveOnlyLog, 'An info log must be emitted when live-only elements are found');
        self::assertStringContainsString((string) $pageId, $liveOnlyLog, 'The log must name the page ID');
        self::assertStringContainsString('2 live-only', $liveOnlyLog, 'The log must name the live-only element count');

        // Negative case: a page with only shared elements (draft + live) must
        // not emit a live-only info log — the grouping caveat does not apply.
        $this->logger->messages = [];

        $pageId2 = $this->getPageId2();
        $areaId2 = 200;
        $this->seeder->seedPage($pageId2, $areaId2);
        foreach (['draft', 'live'] as $stage) {
            $this->seeder->seedElement(7200, $areaId2, self::CONTENT_CLASS, 1, [
                'SizeMD' => 12,
                'Title' => 'Shared',
            ], stage: $stage);
            $this->seeder->seedContentMedia(7200, [], stage: $stage);
        }

        $this->runMigration(pageId: $pageId2);

        $liveOnlyMessages = \array_values(\array_filter(
            $this->getLogMessages('info'),
            static fn (string $msg): bool => \str_contains($msg, 'live-only'),
        ));
        self::assertSame([], $liveOnlyMessages, 'A page with no live-only elements must not emit a live-only info log');
    }

    public function testDraftAndLiveMigratedWithSameId(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(5300, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Draft Title',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(5300, ['HTML' => '<p>Draft HTML</p>'], stage: 'draft');

        $this->seeder->seedElement(5300, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Live Title',
        ], stage: 'live');
        $this->seeder->seedContentMedia(5300, ['HTML' => '<p>Live HTML</p>'], stage: 'live');

        $this->runMigration();

        // Both stages share the same record ID but preserve their own content
        Versioned::set_stage(Versioned::DRAFT);
        $draftElement = ContentElement::get()->filter(['ParentClass' => Column::class])->first();
        self::assertInstanceOf(ContentElement::class, $draftElement);
        $draftId = (int) $draftElement->ID;
        self::assertSame('Draft Title', $draftElement->Title);
        self::assertSame('<p>Draft HTML</p>', $draftElement->HTML);

        Versioned::set_stage(Versioned::LIVE);
        $liveElement = ContentElement::get()->filter(['ParentClass' => Column::class])->first();
        self::assertInstanceOf(ContentElement::class, $liveElement);
        self::assertSame($draftId, (int) $liveElement->ID);
        self::assertSame('Live Title', $liveElement->Title);
        self::assertSame('<p>Live HTML</p>', $liveElement->HTML);
    }

    public function testContainersShareSameIdsAcrossStages(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(5400, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
        ], stage: 'draft');
        $this->seeder->seedContentMedia(5400, [], stage: 'draft');

        $this->seeder->seedElement(5400, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
        ], stage: 'live');
        $this->seeder->seedContentMedia(5400, [], stage: 'live');

        $this->runMigration();

        Versioned::set_stage(Versioned::DRAFT);
        $draftSection = Section::get()->filter(['ParentID' => $pageId, 'Zone' => self::ZONE])->first();
        self::assertInstanceOf(Section::class, $draftSection);
        $draftSectionId = (int) $draftSection->ID;

        Versioned::set_stage(Versioned::LIVE);
        $liveSection = Section::get()->filter(['ParentID' => $pageId, 'Zone' => self::ZONE])->first();
        self::assertInstanceOf(Section::class, $liveSection);

        self::assertSame($draftSectionId, (int) $liveSection->ID);
    }

    public function testVersionsRecordsCreated(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(5500, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
        ], stage: 'draft');
        $this->seeder->seedContentMedia(5500, [], stage: 'draft');

        $this->seeder->seedElement(5500, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
        ], stage: 'live');
        $this->seeder->seedContentMedia(5500, [], stage: 'live');

        $this->runMigration();

        Versioned::set_stage(Versioned::DRAFT);
        $section = Section::get()->filter(['ParentID' => $pageId, 'Zone' => self::ZONE])->first();
        self::assertInstanceOf(Section::class, $section);

        // _Versions table should have entries
        $versions = $section->Versions();
        self::assertGreaterThan(0, $versions->count());
    }

    public function testOldToNewIdMappingUsedForLiveReconciliation(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Two elements on both stages
        $this->seeder->seedElement(5600, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Alpha',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(5600, [], stage: 'draft');
        $this->seeder->seedElement(5601, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'Beta',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(5601, [], stage: 'draft');

        $this->seeder->seedElement(5600, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Alpha',
        ], stage: 'live');
        $this->seeder->seedContentMedia(5600, [], stage: 'live');
        $this->seeder->seedElement(5601, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'Beta',
        ], stage: 'live');
        $this->seeder->seedContentMedia(5601, [], stage: 'live');

        $this->runMigration();

        // Draft records
        Versioned::set_stage(Versioned::DRAFT);
        $draftAlpha = ContentElement::get()->filter(['Title' => 'Alpha'])->first();
        $draftBeta = ContentElement::get()->filter(['Title' => 'Beta'])->first();
        self::assertInstanceOf(ContentElement::class, $draftAlpha);
        self::assertInstanceOf(ContentElement::class, $draftBeta);

        // Live records — writeToStage(LIVE) publishes draft content to live,
        // so live titles match draft titles.
        Versioned::set_stage(Versioned::LIVE);
        $liveAlpha = ContentElement::get()->filter(['Title' => 'Alpha'])->first();
        $liveBeta = ContentElement::get()->filter(['Title' => 'Beta'])->first();
        self::assertInstanceOf(ContentElement::class, $liveAlpha);
        self::assertInstanceOf(ContentElement::class, $liveBeta);

        // Same IDs on both stages
        self::assertSame((int) $draftAlpha->ID, (int) $liveAlpha->ID);
        self::assertSame((int) $draftBeta->ID, (int) $liveBeta->ID);
    }

    public function testOldToNewColumnIdMappingAssignsCorrectParents(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(5700, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'Title' => 'Mapped',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(5700, [], stage: 'draft');

        $this->seeder->seedElement(5700, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'Title' => 'Mapped',
        ], stage: 'live');
        $this->seeder->seedContentMedia(5700, [], stage: 'live');

        $this->runMigration();

        // Both draft and live element should have Column as parent
        Versioned::set_stage(Versioned::DRAFT);
        $draftElement = ContentElement::get()->filter(['Title' => 'Mapped'])->first();
        self::assertInstanceOf(ContentElement::class, $draftElement);
        self::assertSame(Column::class, $draftElement->ParentClass);

        Versioned::set_stage(Versioned::LIVE);
        $liveElement = ContentElement::get()->filter(['Title' => 'Mapped'])->first();
        self::assertInstanceOf(ContentElement::class, $liveElement);
        self::assertSame(Column::class, $liveElement->ParentClass);

        // Same parent Column ID
        self::assertSame((int) $draftElement->ParentID, (int) $liveElement->ParentID);
    }

    public function testLiveRowElementsAreSkippedDuringPublish(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Draft: row delimiter + content element
        $this->seeder->seedElement(5800, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(5800);
        $this->seeder->seedElement(5801, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 12,
            'Title' => 'Content In Row',
        ]);
        $this->seeder->seedContentMedia(5801);

        // Live: same row + content element
        $this->seeder->seedElement(5800, $areaId, self::ROW_CLASS, 1, stage: 'live');
        $this->seeder->seedRow(5800, stage: 'live');
        $this->seeder->seedElement(5801, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 12,
            'Title' => 'Content In Row',
        ], stage: 'live');
        $this->seeder->seedContentMedia(5801, stage: 'live');

        $this->runMigration();

        // Draft: content element exists in the hierarchy
        Versioned::set_stage(Versioned::DRAFT);
        $draftElements = GridElement::get()->filter(['ParentClass' => Column::class]);
        self::assertCount(1, $draftElements);
        self::assertSame('Content In Row', $draftElements->first()->Title);

        // Live: only the content element is published — row element must not leak through
        Versioned::set_stage(Versioned::LIVE);
        $liveElements = GridElement::get()->filter(['ParentClass' => Column::class]);
        self::assertCount(1, $liveElements);
        self::assertSame('Content In Row', $liveElements->first()->Title);
    }

    public function testDraftAndLiveSortAgreeForElementOnBothStages(): void
    {
        // Two elements with identical grid settings group into ONE column, so
        // they receive column-local Sort 1 and 2 on draft. Their legacy
        // area-wide sort values are 2 and 3. The live UPDATE must use the
        // column-local draft Sort (1, 2), not the legacy area-wide value (2, 3),
        // so both stages order the column's children identically.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        foreach (['draft', 'live'] as $stage) {
            $this->seeder->seedElement(5900, $areaId, self::CONTENT_CLASS, 2, [
                'SizeMD' => 6,
                'Title' => 'First',
            ], stage: $stage);
            $this->seeder->seedContentMedia(5900, [], stage: $stage);

            $this->seeder->seedElement(5901, $areaId, self::CONTENT_CLASS, 3, [
                'SizeMD' => 6,
                'Title' => 'Second',
            ], stage: $stage);
            $this->seeder->seedContentMedia(5901, [], stage: $stage);
        }

        $this->runMigration();

        Versioned::set_stage(Versioned::DRAFT);
        $draftFirst = ContentElement::get()->filter(['Title' => 'First'])->first();
        $draftSecond = ContentElement::get()->filter(['Title' => 'Second'])->first();
        self::assertInstanceOf(ContentElement::class, $draftFirst);
        self::assertInstanceOf(ContentElement::class, $draftSecond);

        Versioned::set_stage(Versioned::LIVE);
        $liveFirst = ContentElement::get()->filter(['Title' => 'First'])->first();
        $liveSecond = ContentElement::get()->filter(['Title' => 'Second'])->first();
        self::assertInstanceOf(ContentElement::class, $liveFirst);
        self::assertInstanceOf(ContentElement::class, $liveSecond);

        // Draft assigns column-local 1, 2 (not the legacy area-wide 2, 3)
        self::assertSame(1, (int) $draftFirst->Sort);
        self::assertSame(2, (int) $draftSecond->Sort);

        // Live Sort must equal the draft Sort for each element
        self::assertSame((int) $draftFirst->Sort, (int) $liveFirst->Sort, 'First: draft and live Sort must agree');
        self::assertSame((int) $draftSecond->Sort, (int) $liveSecond->Sort, 'Second: draft and live Sort must agree');
    }

    public function testLiveColumnWidthReconciledFromLiveElementSize(): void
    {
        // An element present on BOTH stages whose live Size differs from its
        // draft Size. The Column's GridSettings is derived from the draft Size
        // and published to live; the live Column width must be reconciled from
        // the live Size, otherwise the published front-end renders the wrong
        // column width.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(6100, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Widthy',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(6100, [], stage: 'draft');

        $this->seeder->seedElement(6100, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 10,
            'Title' => 'Widthy',
        ], stage: 'live');
        $this->seeder->seedContentMedia(6100, [], stage: 'live');

        $this->runMigration();

        // Draft Column keeps the draft-derived width.
        Versioned::set_stage(Versioned::DRAFT);
        $draftElement = ContentElement::get()->filter(['Title' => 'Widthy'])->first();
        self::assertInstanceOf(ContentElement::class, $draftElement);
        $draftColumn = Column::get()->byID((int) $draftElement->ParentID);
        self::assertInstanceOf(Column::class, $draftColumn);
        self::assertSame(6, $draftColumn->getGridSettings()->default->width, 'Draft column width derives from the draft Size');

        // Live Column must reflect the live element Size, not the draft Size.
        Versioned::set_stage(Versioned::LIVE);
        $liveElement = ContentElement::get()->filter(['Title' => 'Widthy'])->first();
        self::assertInstanceOf(ContentElement::class, $liveElement);
        $liveColumn = Column::get()->byID((int) $liveElement->ParentID);
        self::assertInstanceOf(Column::class, $liveColumn);
        self::assertSame(10, $liveColumn->getGridSettings()->default->width, 'Live column width must reconcile from the live Size');
    }

    public function testLiveColumnWidthUnchangedWhenDraftAndLiveSizesMatch(): void
    {
        // Shared element with identical draft/live Size — reconciliation must be
        // a no-op and leave both stages at the same width.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        foreach (['draft', 'live'] as $stage) {
            $this->seeder->seedElement(6200, $areaId, self::CONTENT_CLASS, 1, [
                'SizeMD' => 7,
                'Title' => 'Stable',
            ], stage: $stage);
            $this->seeder->seedContentMedia(6200, [], stage: $stage);
        }

        $this->runMigration();

        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            Versioned::set_stage($stage);
            $element = ContentElement::get()->filter(['Title' => 'Stable'])->first();
            self::assertInstanceOf(ContentElement::class, $element);
            $column = Column::get()->byID((int) $element->ParentID);
            self::assertInstanceOf(Column::class, $column);
            self::assertSame(7, $column->getGridSettings()->default->width, "Width on {$stage} stays 7");
        }
    }

    public function testLiveColumnWidthUsesFirstElementWhenLiveSizesDiverge(): void
    {
        // Two elements share the same DRAFT Size, so they group into one Column.
        // On live their Size diverges; a single Column cannot express two widths,
        // so the first element's live settings win and a warning is logged.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(6300, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'DivA',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(6300, [], stage: 'draft');
        $this->seeder->seedElement(6301, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'DivB',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(6301, [], stage: 'draft');

        $this->seeder->seedElement(6300, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 10,
            'Title' => 'DivA',
        ], stage: 'live');
        $this->seeder->seedContentMedia(6300, [], stage: 'live');
        $this->seeder->seedElement(6301, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 4,
            'Title' => 'DivB',
        ], stage: 'live');
        $this->seeder->seedContentMedia(6301, [], stage: 'live');

        $this->runMigration();

        Versioned::set_stage(Versioned::LIVE);
        $liveA = ContentElement::get()->filter(['Title' => 'DivA'])->first();
        self::assertInstanceOf(ContentElement::class, $liveA);
        $liveColumn = Column::get()->byID((int) $liveA->ParentID);
        self::assertInstanceOf(Column::class, $liveColumn);
        self::assertSame(10, $liveColumn->getGridSettings()->default->width, 'First element\'s live Size wins');

        $diverged = false;
        foreach ($this->getLogMessages('warning') as $message) {
            if (str_contains($message, 'diverge') && str_contains($message, (string) $liveColumn->ID)) {
                $diverged = true;
            }
        }
        self::assertTrue($diverged, 'Divergent live grid settings should log a warning naming the column');
    }

    public function testLiveColumnOverrideReconciledFromLiveElementSize(): void
    {
        // The live element gains a per-viewport override (SizeLG) that the draft
        // element lacks. The live Column must carry that override; the draft must not.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(6400, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Override',
        ], stage: 'draft');
        $this->seeder->seedContentMedia(6400, [], stage: 'draft');

        $this->seeder->seedElement(6400, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'SizeLG' => 4,
            'Title' => 'Override',
        ], stage: 'live');
        $this->seeder->seedContentMedia(6400, [], stage: 'live');

        $this->runMigration();

        Versioned::set_stage(Versioned::DRAFT);
        $draftElement = ContentElement::get()->filter(['Title' => 'Override'])->first();
        self::assertInstanceOf(ContentElement::class, $draftElement);
        $draftColumn = Column::get()->byID((int) $draftElement->ParentID);
        self::assertInstanceOf(Column::class, $draftColumn);
        self::assertArrayNotHasKey('lg', $draftColumn->getGridSettings()->overrides, 'Draft column has no lg override');

        Versioned::set_stage(Versioned::LIVE);
        $liveElement = ContentElement::get()->filter(['Title' => 'Override'])->first();
        self::assertInstanceOf(ContentElement::class, $liveElement);
        $liveColumn = Column::get()->byID((int) $liveElement->ParentID);
        self::assertInstanceOf(Column::class, $liveColumn);
        $liveOverrides = $liveColumn->getGridSettings()->overrides;
        self::assertArrayHasKey('lg', $liveOverrides, 'Live column gains the lg override from the live Size');
        self::assertSame(4, $liveOverrides['lg']->width, 'Live lg override width is reconciled to 4');
    }

    // ─── Test Group 4: Idempotency + dry-run (tests 19-21) ──────

    public function testRunTwiceSkipsSecondRunNoDuplicates(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);

        $this->runMigration();
        $firstRunCount = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->count();

        // Run again — should skip due to idempotency
        $this->logger->messages = [];
        $this->runMigration();
        $secondRunCount = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->count();

        self::assertSame($firstRunCount, $secondRunCount);

        // Verify the skip was logged
        $infoMessages = $this->getLogMessages('info');
        self::assertNotEmpty($infoMessages);
        self::assertStringContainsString('already migrated', $infoMessages[0]);
    }

    public function testDryRunCreatesNoRecords(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);

        $service = $this->createService();
        $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: true,
            pageIds: [$pageId],
        );

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(0, $sections);

        // Verify dry-run logged the correct hierarchy counts
        $infoMessages = $this->getLogMessages('info');
        self::assertNotEmpty($infoMessages);
        $dryRunLog = $infoMessages[0];
        self::assertStringContainsString('[DRY RUN]', $dryRunLog);
        self::assertStringContainsString('1 section(s)', $dryRunLog);
        self::assertStringContainsString('1 row(s)', $dryRunLog);
        self::assertStringContainsString('2 column(s)', $dryRunLog);
    }

    public function testDryRunReportsLiveOnlyElements(): void
    {
        // A page whose draft area is empty but whose live area still holds
        // published elements: the real run creates a live-only hierarchy on both
        // stages, so the dry-run must report the live-only count instead of
        // "no elements to migrate", which would understate the write.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);
        $this->seeder->seedElement(7300, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Live Only',
        ], stage: 'live');
        $this->seeder->seedContentMedia(7300, [], stage: 'live');

        $service = $this->createService();
        $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: true,
            pageIds: [$pageId],
        );

        self::assertCount(0, Section::get()->filter(['ParentID' => $pageId, 'Zone' => self::ZONE]));

        $dryRunLog = null;
        foreach ($this->getLogMessages('info') as $msg) {
            if (\str_contains($msg, '[DRY RUN]')) {
                $dryRunLog = $msg;
                break;
            }
        }
        self::assertNotNull($dryRunLog, 'A dry-run info log must be emitted');
        self::assertStringContainsString('1 live-only element(s)', $dryRunLog);
        self::assertStringNotContainsString('no elements to migrate', $dryRunLog);
    }

    public function testDryRunDoesNotCountLiveOnlyRowDelimiters(): void
    {
        // Row delimiters are grouping boundaries, never written records — and an
        // empty live-only row is dropped by the strategy. Counting the delimiter
        // would make the preview promise a write the real run does not perform:
        // a live area holding only a delimiter migrates nothing.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);
        $this->seeder->seedElement(7400, $areaId, self::ROW_CLASS, 1, stage: 'live');
        $this->seeder->seedRow(7400, stage: 'live');

        $service = $this->createService();
        $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: true,
            pageIds: [$pageId],
        );

        $infoMessages = $this->getLogMessages('info');
        self::assertNotEmpty($infoMessages);
        self::assertStringContainsString('no elements to migrate', $infoMessages[0]);
    }

    public function testDryRunCountsOnlyLiveOnlyContentElements(): void
    {
        // A live-only delimiter followed by a live-only content element: only the
        // content element becomes a record, so the count must be 1, not 2.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);
        $this->seeder->seedElement(7500, $areaId, self::ROW_CLASS, 1, stage: 'live');
        $this->seeder->seedRow(7500, stage: 'live');
        $this->seeder->seedElement(7501, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'Live Only',
        ], stage: 'live');
        $this->seeder->seedContentMedia(7501, [], stage: 'live');

        $service = $this->createService();
        $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: true,
            pageIds: [$pageId],
        );

        $dryRunLog = null;
        foreach ($this->getLogMessages('info') as $msg) {
            if (\str_contains($msg, '[DRY RUN]')) {
                $dryRunLog = $msg;
                break;
            }
        }
        self::assertNotNull($dryRunLog, 'A dry-run info log must be emitted');
        self::assertStringContainsString('1 live-only element(s)', $dryRunLog);
    }

    public function testPageWithOnlyRowDelimitersIsReportedAsHavingNoElements(): void
    {
        // Draft and live both hold only row delimiters: every row is empty, the
        // strategy drops them all, and nothing can be written. The page must be
        // reported as having no elements — not logged as "Successfully migrated"
        // with zero sections, which misreports the run and (since no Section
        // exists) would re-process the page on every subsequent run.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);
        foreach (['draft', 'live'] as $stage) {
            $this->seeder->seedElement(7600, $areaId, self::ROW_CLASS, 1, stage: $stage);
            $this->seeder->seedRow(7600, stage: $stage);
        }

        $this->runMigration();

        self::assertCount(0, Section::get()->filter(['ParentID' => $pageId, 'Zone' => self::ZONE]));

        $allInfo = \implode("\n", $this->getLogMessages('info'));
        self::assertStringContainsString('has no elements to migrate', $allInfo);
        self::assertStringNotContainsString('Successfully migrated', $allInfo);
    }

    public function testMigrationRestoresProjectLevelAutoScaffoldFalse(): void
    {
        // A project may set auto_scaffold: false on Section/Row. The migration
        // suppresses scaffolding internally but MUST restore the captured value,
        // not a hardcoded true, so the project's configuration survives.
        $originalSection = Section::config()->get('auto_scaffold');
        $originalRow = Row::config()->get('auto_scaffold');

        Section::config()->set('auto_scaffold', false);
        Row::config()->set('auto_scaffold', false);

        try {
            $pageId = $this->getPageId();
            $this->seedStandardPage($pageId);

            $this->runMigration();

            self::assertFalse(
                (bool) Section::config()->get('auto_scaffold'),
                'Section auto_scaffold must be restored to the pre-set false, not clobbered to true',
            );
            self::assertFalse(
                (bool) Row::config()->get('auto_scaffold'),
                'Row auto_scaffold must be restored to the pre-set false, not clobbered to true',
            );
        } finally {
            Section::config()->set('auto_scaffold', $originalSection);
            Row::config()->set('auto_scaffold', $originalRow);
        }
    }

    // ─── Test Group 5: Transaction safety (test 22) ──────────────

    public function testFailureMidPageRollsBackThatPage(): void
    {
        $pageId1 = $this->getPageId();
        $pageId2 = $this->getPageId2();
        $areaId1 = 100;
        $areaId2 = 200;

        // Page 1: element titled "FAIL_ME" triggers the failing extension
        $this->seeder->seedPage($pageId1, $areaId1);
        $this->seeder->seedElement(6000, $areaId1, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'FAIL_ME',
        ]);
        $this->seeder->seedContentMedia(6000);

        // Page 2: normal element that should succeed
        $this->seeder->seedPage($pageId2, $areaId2);
        $this->seeder->seedElement(6100, $areaId2, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Normal Element',
        ]);
        $this->seeder->seedContentMedia(6100);

        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);

        try {
            $service = $this->createService();
            $service->run(
                self::DEFAULT_VIEWPORT,
                self::ZONE,
                self::VIEWPORT_KEY_MAP,
                dryRun: false,
                pageIds: [$pageId1, $pageId2],
            );

            // Page 1 should be rolled back — no sections
            self::assertCount(0, Section::get()->filter([
                'ParentID' => $pageId1,
                'Zone' => self::ZONE,
            ]));

            // Page 2 should have succeeded
            self::assertGreaterThan(0, Section::get()->filter([
                'ParentID' => $pageId2,
                'Zone' => self::ZONE,
            ])->count());

            // The error should have been logged
            $errors = $this->getLogMessages('error');
            self::assertNotEmpty($errors);
            self::assertStringContainsString('Deliberate test failure', $errors[0]);
        } finally {
            DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        }
    }

    public function testNonExceptionThrowableMidPageRollsBackThatPage(): void
    {
        // Regression guard: a non-Exception Throwable (e.g. a TypeError from a
        // project hook) raised mid-write must still roll the page back. The
        // framework's withTransaction() only catches \Exception, so an \Error
        // would otherwise leak an open transaction — and the next page's
        // transactionStart() would implicitly commit this page's partial writes.
        $pageId1 = $this->getPageId();
        $pageId2 = $this->getPageId2();
        $areaId1 = 100;
        $areaId2 = 200;

        // Page 1: element titled "ERROR_ME" triggers a \TypeError mid-write.
        $this->seeder->seedPage($pageId1, $areaId1);
        $this->seeder->seedElement(6200, $areaId1, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'ERROR_ME',
        ]);
        $this->seeder->seedContentMedia(6200);

        // Page 2: normal element that should succeed after page 1 is rolled back.
        $this->seeder->seedPage($pageId2, $areaId2);
        $this->seeder->seedElement(6300, $areaId2, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Normal Element',
        ]);
        $this->seeder->seedContentMedia(6300);

        DraftHierarchyWriter::add_extension(TestErrorThrowingMigrationExtension::class);

        try {
            $service = $this->createService();
            $service->run(
                self::DEFAULT_VIEWPORT,
                self::ZONE,
                self::VIEWPORT_KEY_MAP,
                dryRun: false,
                pageIds: [$pageId1, $pageId2],
            );

            // Page 1 must be fully rolled back — no partial hierarchy persisted.
            self::assertCount(0, Section::get()->filter([
                'ParentID' => $pageId1,
                'Zone' => self::ZONE,
            ]));

            // Page 2 must be unaffected by the leaked transaction and succeed.
            self::assertGreaterThan(0, Section::get()->filter([
                'ParentID' => $pageId2,
                'Zone' => self::ZONE,
            ])->count());

            // The connection must be left with no open transaction.
            self::assertSame(0, DB::get_conn()->transactionDepth());

            // The error should have been logged.
            $errors = $this->getLogMessages('error');
            self::assertNotEmpty($errors);
            self::assertStringContainsString('non-Exception Throwable', $errors[0]);
        } finally {
            DraftHierarchyWriter::remove_extension(TestErrorThrowingMigrationExtension::class);
        }
    }

    // ─── Test Group 6: Pseudo rows (tests 23-25) ─────────────────

    public function testElementsBeforeFirstRowCreateImplicitSection(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Elements before any row
        $this->seeder->seedElement(7000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Before Row',
        ]);
        $this->seeder->seedContentMedia(7000);

        // Then a row with an element
        $this->seeder->seedElement(7001, $areaId, self::ROW_CLASS, 2);
        $this->seeder->seedRow(7001);
        $this->seeder->seedElement(7002, $areaId, self::CONTENT_CLASS, 3, [
            'SizeMD' => 12,
            'Title' => 'In Row',
        ]);
        $this->seeder->seedContentMedia(7002);

        $this->runMigration();

        // Should have 2 sections: one implicit (for "Before Row") and one explicit
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->sort('Sort', 'ASC');
        self::assertCount(2, $sections);

        // First section should have the implicit element
        $firstSection = $sections->first();
        self::assertInstanceOf(Section::class, $firstSection);
        $firstRow = Row::get()->filter(['ParentID' => $firstSection->ID])->first();
        self::assertInstanceOf(Row::class, $firstRow);
        $firstColumn = Column::get()->filter(['ParentID' => $firstRow->ID])->first();
        self::assertInstanceOf(Column::class, $firstColumn);
        $firstElement = ContentElement::get()->filter(['ParentID' => $firstColumn->ID])->first();
        self::assertInstanceOf(ContentElement::class, $firstElement);
        self::assertSame('Before Row', $firstElement->Title);
    }

    public function testElementsAfterLastRowCreateImplicitSection(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Row first
        $this->seeder->seedElement(7100, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(7100);
        $this->seeder->seedElement(7101, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 12,
            'Title' => 'In Row',
        ]);
        $this->seeder->seedContentMedia(7101);

        // Elements after row (no more rows) — these belong to the last row's group
        // Actually per the ElementGrouper logic, these go in the same group as the row
        // so this is testing that trailing elements after the last row are handled
        $this->seeder->seedElement(7102, $areaId, self::CONTENT_CLASS, 3, [
            'SizeMD' => 6,
            'Title' => 'After Row',
        ]);
        $this->seeder->seedContentMedia(7102);

        $this->runMigration();

        // The "After Row" element should be in the same section as the row
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(1, $sections);

        // The row should have 2 columns (both elements under same row)
        $row = Row::get()->filter(['ParentID' => $sections->first()->ID])->first();
        self::assertInstanceOf(Row::class, $row);
        $columns = Column::get()->filter(['ParentID' => $row->ID]);
        self::assertCount(2, $columns);
    }

    public function testNoExplicitRowsAllElementsInImplicitSection(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // No row elements at all — distinct widths to prevent grouping
        $this->seeder->seedElement(7200, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'Title' => 'Orphan 1',
        ]);
        $this->seeder->seedContentMedia(7200);
        $this->seeder->seedElement(7201, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 4,
            'Title' => 'Orphan 2',
        ]);
        $this->seeder->seedContentMedia(7201);

        $this->runMigration();

        // Should create one implicit section
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(1, $sections);

        // With one row containing both columns (distinct widths → no grouping)
        $row = Row::get()->filter(['ParentID' => $sections->first()->ID])->first();
        self::assertInstanceOf(Row::class, $row);
        $columns = Column::get()->filter(['ParentID' => $row->ID]);
        self::assertCount(2, $columns);
    }

    /**
     * Four elements where only the 3rd has a different grid configuration produce
     * exactly 3 columns: [e1, e2] | [e3] | [e4]. Elements 1 and 2 share a column,
     * element 3 breaks the group, and element 4 starts a new column (it is not
     * merged with e1+e2 because grouping is strictly consecutive).
     */
    public function testGroupsConsecutiveElementsWithSameGridSettings(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(4000, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(4000);

        // e1 + e2: identical width=6
        $this->seeder->seedElement(4001, $areaId, self::CONTENT_CLASS, 2, ['SizeMD' => 6, 'Title' => 'E1']);
        $this->seeder->seedContentMedia(4001);
        $this->seeder->seedElement(4002, $areaId, self::CONTENT_CLASS, 3, ['SizeMD' => 6, 'Title' => 'E2']);
        $this->seeder->seedContentMedia(4002);
        // e3: different width
        $this->seeder->seedElement(4003, $areaId, self::CONTENT_CLASS, 4, ['SizeMD' => 4, 'Title' => 'E3']);
        $this->seeder->seedContentMedia(4003);
        // e4: back to width=6 — not merged with e1+e2 because grouping is consecutive
        $this->seeder->seedElement(4004, $areaId, self::CONTENT_CLASS, 5, ['SizeMD' => 6, 'Title' => 'E4']);
        $this->seeder->seedContentMedia(4004);

        $this->runMigration();

        $section = Section::get()->filter(['ParentID' => $pageId, 'Zone' => self::ZONE])->first();
        self::assertInstanceOf(Section::class, $section);
        $row = Row::get()->filter(['ParentID' => $section->ID])->first();
        self::assertInstanceOf(Row::class, $row);

        $columns = Column::get()->filter(['ParentID' => $row->ID])->sort('Sort', 'ASC');
        self::assertCount(3, $columns, '[e1,e2][e3][e4] → 3 columns');

        $columnsArray = $columns->toArray();

        // Column 1: e1 + e2 (both width=6)
        self::assertSame(6, $columnsArray[0]->getGridSettings()->default->width);
        $col1Elements = ContentElement::get()->filter(['ParentID' => $columnsArray[0]->ID])->sort('Sort', 'ASC');
        self::assertCount(2, $col1Elements);
        self::assertSame('E1', $col1Elements->first()->Title);
        self::assertSame('E2', $col1Elements->last()->Title);

        // Column 2: e3 alone (width=4)
        self::assertSame(4, $columnsArray[1]->getGridSettings()->default->width);
        $col2Elements = ContentElement::get()->filter(['ParentID' => $columnsArray[1]->ID]);
        self::assertCount(1, $col2Elements);
        self::assertSame('E3', $col2Elements->first()->Title);

        // Column 3: e4 alone (width=6 but separate from e1+e2 due to e3 boundary)
        self::assertSame(6, $columnsArray[2]->getGridSettings()->default->width);
        $col3Elements = ContentElement::get()->filter(['ParentID' => $columnsArray[2]->ID]);
        self::assertCount(1, $col3Elements);
        self::assertSame('E4', $col3Elements->first()->Title);
    }

    // ─── Test Group 7: Strategy-specific + edge cases ────────────

    public function testRowPerSectionMapsFieldsCorrectly(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(8000, $areaId, self::ROW_CLASS, 1, [
            'Title' => 'Row Title',
            'ExtraClass' => 'row-extra',
        ]);
        $this->seeder->seedRow(8000, isFluid: true, customSectionClass: 'section-extra');
        $this->seeder->seedElement(8001, $areaId, self::CONTENT_CLASS, 2, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(8001);

        $this->runMigration();

        $section = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->first();
        self::assertInstanceOf(Section::class, $section);
        // CustomSectionClass → Section.ExtraClass
        self::assertSame('section-extra', $section->ExtraClass);

        $row = Row::get()->filter(['ParentID' => $section->ID])->first();
        self::assertInstanceOf(Row::class, $row);
        // Row Title → Row.Title, Row ExtraClass → Row.ExtraClass
        self::assertSame('Row Title', $row->Title);
        self::assertSame('row-extra', $row->ExtraClass);
    }

    public function testAllRowsInSingleSectionFirstRowConfigUsed(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(8100, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(8100, isFluid: true, customSectionClass: 'first-class');
        $this->seeder->seedElement(8101, $areaId, self::CONTENT_CLASS, 2, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(8101);

        $this->seeder->seedElement(8110, $areaId, self::ROW_CLASS, 3);
        $this->seeder->seedRow(8110, isFluid: false, customSectionClass: 'second-class');
        $this->seeder->seedElement(8111, $areaId, self::CONTENT_CLASS, 4, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(8111);

        $service = $this->createService($this->createAllRowsStrategy());
        $this->runMigration($service);

        // Should create one section with the first row's config
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(1, $sections);
        self::assertSame('first-class', $sections->first()->ExtraClass);

        // Two rows under the single section
        $rows = Row::get()->filter(['ParentID' => $sections->first()->ID]);
        self::assertCount(2, $rows);
    }

    public function testAdjacentEmptyRowIsDropped(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Two adjacent row delimiters: the first has no content elements between it
        // and the next, so it is an empty row.
        $this->seeder->seedElement(8200, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(8200);
        $this->seeder->seedElement(8210, $areaId, self::ROW_CLASS, 2);
        $this->seeder->seedRow(8210);
        $this->seeder->seedElement(8211, $areaId, self::CONTENT_CLASS, 3, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(8211);

        $this->runMigration();

        // The empty leading row would produce a Section→Row with no Column, breaking
        // the complete-hierarchy invariant, so it is dropped: only the content row's
        // section is created, and its row has exactly one column.
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->sort('Sort', 'ASC');
        self::assertCount(1, $sections);

        $row = Row::get()->filter(['ParentID' => $sections->first()->ID])->first();
        self::assertInstanceOf(Row::class, $row);
        self::assertCount(1, Column::get()->filter(['ParentID' => $row->ID]));
    }

    public function testUseElementalGridFalseSkipsPage(): void
    {
        $pageId = $this->getPageId();
        $this->seeder->seedPage($pageId, 100, useGrid: false);

        $this->seeder->seedElement(8300, 100, self::CONTENT_CLASS, 1, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(8300);

        $this->runMigration();

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(0, $sections);
    }

    public function testEmptyElementalAreaSkipsPage(): void
    {
        $pageId = $this->getPageId();
        $this->seeder->seedPage($pageId, 100);
        // No elements seeded for area 100

        $this->runMigration();

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(0, $sections);

        // Verify "no elements" was logged
        $infoMessages = $this->getLogMessages('info');
        self::assertNotEmpty($infoMessages);
        self::assertStringContainsString('no elements to migrate', $infoMessages[0]);
    }

    public function testMultipleElementTypesGetCorrectClassName(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // ElementContent → ContentElement (default mapping)
        $this->seeder->seedElement(8400, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Content Type',
        ]);
        $this->seeder->seedContentMedia(8400);

        // Custom legacy class → TestCustomElement (via extension hook)
        $this->seeder->seedElement(8401, $areaId, 'App\\Elements\\CustomBlock', 2, [
            'SizeMD' => 6,
            'Title' => 'Custom Type',
        ]);
        $this->seeder->seedContentMedia(8401);

        TestClassNameMappingExtension::$targetClass = TestCustomElement::class;
        TestClassNameMappingExtension::$sourceClass = 'App\\Elements\\CustomBlock';
        DraftHierarchyWriter::add_extension(TestClassNameMappingExtension::class);

        try {
            $this->runMigration();

            // Default-mapped element should be ContentElement
            $contentElement = ContentElement::get()->filter([
                'Title' => 'Content Type',
                'ParentClass' => Column::class,
            ])->first();
            self::assertInstanceOf(ContentElement::class, $contentElement);
            self::assertSame(ContentElement::class, $contentElement->ClassName);

            // Extension-mapped element should be TestCustomElement
            $customElement = GridElement::get()->filter([
                'Title' => 'Custom Type',
                'ParentClass' => Column::class,
            ])->first();
            self::assertInstanceOf(TestCustomElement::class, $customElement);
            self::assertSame(TestCustomElement::class, $customElement->ClassName);
        } finally {
            DraftHierarchyWriter::remove_extension(TestClassNameMappingExtension::class);
            TestClassNameMappingExtension::$targetClass = '';
            TestClassNameMappingExtension::$sourceClass = null;
        }
    }

    // ─── Test Group 8: Extension hooks (tests 29-33) ─────────────

    public function testUpdateElementFieldMappingHookAddsCustomField(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(9000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Hook Test',
        ]);
        $this->seeder->seedContentMedia(9000);

        // Create a service with a hook that sets ExtraClass
        $service = $this->createService();

        // Use the Extensible trait to register a temporary extension callback
        DraftHierarchyWriter::add_extension(TestMigrationExtension::class);

        try {
            $this->runMigration($service);

            $element = ContentElement::get()->filter(['ParentClass' => Column::class])->first();
            self::assertInstanceOf(ContentElement::class, $element);
            // The extension sets Style = 'hook-applied'
            self::assertSame('hook-applied', $element->Style);
        } finally {
            DraftHierarchyWriter::remove_extension(TestMigrationExtension::class);
        }
    }

    public function testUpdateClassNameMappingHookOverridesClassName(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(9100, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Overridden',
        ]);
        $this->seeder->seedContentMedia(9100);

        // Override the default ContentElement mapping to TestCustomElement
        TestClassNameMappingExtension::$targetClass = TestCustomElement::class;
        TestClassNameMappingExtension::$sourceClass = null;
        DraftHierarchyWriter::add_extension(TestClassNameMappingExtension::class);

        try {
            $this->runMigration();

            $element = GridElement::get()->filter([
                'Title' => 'Overridden',
                'ParentClass' => Column::class,
            ])->first();
            self::assertInstanceOf(TestCustomElement::class, $element);
            self::assertSame(TestCustomElement::class, $element->ClassName);
        } finally {
            DraftHierarchyWriter::remove_extension(TestClassNameMappingExtension::class);
            TestClassNameMappingExtension::$targetClass = '';
            TestClassNameMappingExtension::$sourceClass = null;
        }
    }

    // ─── Test Group 9: Realistic multi-row migration (test 34) ───

    public function testRealisticPageMigration(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Orphan element before any row (no row wrapper)
        $this->seeder->seedElement(9200, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Orphan',
        ]);
        $this->seeder->seedContentMedia(9200);

        // Row 1: "hero" section with 1 full-width element + image media
        $this->seeder->seedElement(9201, $areaId, self::ROW_CLASS, 2);
        $this->seeder->seedRow(9201, customSectionClass: 'hero');
        $this->seeder->seedElement(9202, $areaId, self::CONTENT_CLASS, 3, [
            'SizeMD' => 12,
            'Title' => 'Hero Element',
        ]);
        $this->seeder->seedContentMedia(9202, [
            'MediaImageID' => 42,
            'MediaRatio' => '16x9',
            'MediaType' => 'image',
        ]);

        // Row 2: 2 elements (8 + 4 columns), second has gap and video
        $this->seeder->seedElement(9210, $areaId, self::ROW_CLASS, 4);
        $this->seeder->seedRow(9210);
        $this->seeder->seedElement(9211, $areaId, self::CONTENT_CLASS, 5, [
            'SizeMD' => 8,
            'Title' => 'Left Column',
        ]);
        $this->seeder->seedContentMedia(9211);
        $this->seeder->seedElement(9212, $areaId, self::CONTENT_CLASS, 6, [
            'SizeMD' => 4,
            'Title' => 'Right Column',
        ]);
        $this->seeder->seedContentMedia(9212, [
            'ExtraColumnGap' => 7,
            'MediaVideoFullURL' => 'https://example.com/vid.mp4',
            'MediaType' => 'video',
        ]);

        $this->runMigration();

        // 3 sections: implicit (orphan) + hero + row 2
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->sort('Sort', 'ASC');
        self::assertCount(3, $sections);

        $sectionList = $sections->toArray();

        // Section 1 (implicit): orphan element
        $implicitSection = $sectionList[0];
        $implicitRow = Row::get()->filter(['ParentID' => $implicitSection->ID])->first();
        self::assertInstanceOf(Row::class, $implicitRow);
        $implicitColumns = Column::get()->filter(['ParentID' => $implicitRow->ID]);
        self::assertCount(1, $implicitColumns);
        $orphanElement = ContentElement::get()->filter(['ParentID' => $implicitColumns->first()->ID])->first();
        self::assertInstanceOf(ContentElement::class, $orphanElement);
        self::assertSame('Orphan', $orphanElement->Title);

        // Section 2 (hero): ExtraClass="hero", 1 row, 1 column width=12
        $heroSection = $sectionList[1];
        self::assertSame('hero', $heroSection->ExtraClass);
        $heroRow = Row::get()->filter(['ParentID' => $heroSection->ID])->first();
        self::assertInstanceOf(Row::class, $heroRow);
        $heroColumns = Column::get()->filter(['ParentID' => $heroRow->ID]);
        self::assertCount(1, $heroColumns);
        $heroColumn = $heroColumns->first();
        self::assertSame(12, $heroColumn->getGridSettings()->default->width);
        $heroElement = ContentElement::get()->filter(['ParentID' => $heroColumn->ID])->first();
        self::assertInstanceOf(ContentElement::class, $heroElement);
        self::assertSame(42, (int) $heroElement->MediaImageID);

        // Section 3: 1 row, 2 columns (width 8 + 4)
        $thirdSection = $sectionList[2];
        $thirdRow = Row::get()->filter(['ParentID' => $thirdSection->ID])->first();
        self::assertInstanceOf(Row::class, $thirdRow);
        $thirdColumns = Column::get()->filter(['ParentID' => $thirdRow->ID])->sort('Sort', 'ASC');
        self::assertCount(2, $thirdColumns);

        $columnList = $thirdColumns->toArray();
        self::assertSame(8, $columnList[0]->getGridSettings()->default->width);
        self::assertSame(4, $columnList[1]->getGridSettings()->default->width);

        // Second element in row 2 has GapSize=3 (mapped from ExtraColumnGap=7)
        // and VideoURL set
        $rightElement = ContentElement::get()->filter(['ParentID' => $columnList[1]->ID])->first();
        self::assertInstanceOf(ContentElement::class, $rightElement);
        self::assertSame(3, (int) $rightElement->GapSize);
        self::assertSame('https://example.com/vid.mp4', $rightElement->VideoURL);
    }

    // ─── Test Group 10: Filter hook (test 35) ────────────────────

    public function testUpdateLegacyElementsFilterPreventsElementFromMigrating(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(9300, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Skip Me',
        ]);
        $this->seeder->seedContentMedia(9300);

        $this->seeder->seedElement(9301, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'Keep Me',
        ]);
        $this->seeder->seedContentMedia(9301);

        LegacyDataReader::add_extension(TestFilterExtension::class);

        try {
            $this->runMigration();

            // Only "Keep Me" should be migrated
            $elements = ContentElement::get()->filter(['ParentClass' => Column::class]);
            self::assertCount(1, $elements);
            self::assertSame('Keep Me', $elements->first()->Title);
        } finally {
            LegacyDataReader::remove_extension(TestFilterExtension::class);
        }
    }

    // ─── Test Group 11: Concrete page class (test 36) ───────────

    public function testMigrationUsesConcretePageClassName(): void
    {
        // Create a TestPage subclass (not base SiteTree) to verify
        // ParentClass stores the concrete class, not the base.
        $page = TestPage::create();
        $page->Title = 'Subclass Page';
        $page->URLSegment = 'subclass-page';
        $page->write();
        $pageId = (int) $page->ID;

        $areaId = 800;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(8001, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
        ]);
        $this->seeder->seedContentMedia(8001, ['HTML' => '<p>Subclass</p>']);

        $service = $this->createService();
        $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: false,
            pageIds: [$pageId],
        );

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => TestPage::class,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(1, $sections, 'Section should have ParentClass = TestPage');

        // Verify no sections exist with the base SiteTree class
        $wrongSections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => Page::class,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(0, $wrongSections, 'No sections should have ParentClass = Page');
    }

    // ─── Test Group 12: Logging behaviour (tests 37-40) ─────────

    public function testSuccessfulMigrationLogsSuccess(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);

        $this->runMigration();

        $infoMessages = $this->getLogMessages('info');
        self::assertNotEmpty($infoMessages);
        self::assertStringContainsString('Successfully migrated page', $infoMessages[0]);
    }

    public function testDryRunWithEmptyPageLogsNoElements(): void
    {
        $pageId = $this->getPageId();
        $this->seeder->seedPage($pageId, 100);
        // No elements seeded

        $service = $this->createService();
        $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: true,
            pageIds: [$pageId],
        );

        self::assertCount(0, Section::get());

        $infoMessages = $this->getLogMessages('info');
        self::assertNotEmpty($infoMessages);
        self::assertStringContainsString('[DRY RUN]', $infoMessages[0]);
        self::assertStringContainsString('no elements to migrate', $infoMessages[0]);
    }

    public function testDryRunWithMultipleRowsLogsCounts(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Row 1 with 1 element
        $this->seeder->seedElement(9000, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(9000);
        $this->seeder->seedElement(9001, $areaId, self::CONTENT_CLASS, 2, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(9001);

        // Row 2 with 2 elements of different widths (prevent grouping)
        $this->seeder->seedElement(9010, $areaId, self::ROW_CLASS, 3);
        $this->seeder->seedRow(9010);
        $this->seeder->seedElement(9011, $areaId, self::CONTENT_CLASS, 4, ['SizeMD' => 6]);
        $this->seeder->seedContentMedia(9011);
        $this->seeder->seedElement(9012, $areaId, self::CONTENT_CLASS, 5, ['SizeMD' => 4]);
        $this->seeder->seedContentMedia(9012);

        $service = $this->createService();
        $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: true,
            pageIds: [$pageId],
        );

        self::assertCount(0, Section::get());

        $infoMessages = $this->getLogMessages('info');
        self::assertNotEmpty($infoMessages);
        $dryRunLog = $infoMessages[0];
        self::assertStringContainsString('[DRY RUN]', $dryRunLog);
        self::assertStringContainsString('2 section(s)', $dryRunLog);
        self::assertStringContainsString('2 row(s)', $dryRunLog);
        self::assertStringContainsString('3 column(s)', $dryRunLog);
    }

    public function testErrorLogIncludesPageIdAndMessage(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;

        $this->seeder->seedPage($pageId, $areaId);
        $this->seeder->seedElement(9100, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'FAIL_ME',
        ]);
        $this->seeder->seedContentMedia(9100);

        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);
        try {
            $service = $this->createService();
            $failures = $service->run(
                self::DEFAULT_VIEWPORT,
                self::ZONE,
                self::VIEWPORT_KEY_MAP,
                dryRun: false,
                pageIds: [$pageId],
            );
            self::assertSame(1, $failures, 'Failing extension should cause one page failure');

            $errors = $this->getLogMessages('error');
            self::assertCount(1, $errors);
            self::assertStringContainsString((string) $pageId, $errors[0]);
            self::assertStringContainsString('Deliberate test failure', $errors[0]);
        } finally {
            DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        }
    }

    public function testFailedPageLogsExceptionContext(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;

        $this->seeder->seedPage($pageId, $areaId);
        $this->seeder->seedElement(9400, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'FAIL_ME',
        ]);
        $this->seeder->seedContentMedia(9400);

        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);
        try {
            $service = $this->createService();
            $service->run(
                self::DEFAULT_VIEWPORT,
                self::ZONE,
                self::VIEWPORT_KEY_MAP,
                dryRun: false,
                pageIds: [$pageId],
            );

            $errorEntries = \array_values(\array_filter(
                $this->logger->messages,
                static fn (array $entry): bool => $entry['level'] === 'error',
            ));
            self::assertNotEmpty($errorEntries, 'At least one error-level entry must be logged');

            $entry = $errorEntries[0];
            self::assertSame($pageId, $entry['context']['pageId'], 'context[pageId] must equal the failed page ID');
            self::assertSame($areaId, $entry['context']['areaId'], 'context[areaId] must equal the area ID');
            self::assertArrayHasKey('exception', $entry['context'], 'context[exception] key must be present');
            self::assertInstanceOf(\Throwable::class, $entry['context']['exception'], 'context[exception] must hold the Throwable');
        } finally {
            DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        }
    }

    // ─── UseGrid flag migration ──────────────────────────────────

    public function testMigrationSetsUseGridOnDraftPage(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);

        $this->runMigration();

        $row = DB::prepared_query('SELECT "UseGrid" FROM "Page" WHERE "ID" = ?', [$pageId])->record();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['UseGrid']);
    }

    public function testMigrationSetsUseGridOnLivePage(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seedStandardPage($pageId, $areaId);

        // Seed the same elements on live stage
        $this->seeder->seedElement(1001, $areaId, self::CONTENT_CLASS, 2, [
            'Title' => 'Element One',
            'SizeMD' => 8,
        ], 'live');
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>Hello</p>'], 'live');

        // Ensure Page_Live has this page and UseElementalGrid = 1.
        // In production, publishing copies all columns. Here we simulate it
        // by publishing via ORM and then setting the legacy column directly.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->UseGrid = false;
        $page->write();
        $page->publishSingle();
        DB::prepared_query(
            'UPDATE "Page_Live" SET "UseElementalGrid" = 1, "ElementalAreaID" = ? WHERE "ID" = ?',
            [$areaId, $pageId],
        );

        $this->runMigration();

        $liveRow = DB::prepared_query('SELECT "UseGrid" FROM "Page_Live" WHERE "ID" = ?', [$pageId])->record();
        self::assertNotNull($liveRow);
        self::assertSame(1, (int) $liveRow['UseGrid']);
    }

    public function testMigrationSetsUseGridFalseForDisabledPages(): void
    {
        $pageId = $this->getPageId();
        $pageId2 = $this->getPageId2();

        // Page 1 has grid enabled + content
        $this->seedStandardPage($pageId, 100);

        // Page 2 has grid disabled (no content to migrate)
        $this->seeder->seedPage($pageId2, 200, useGrid: false);

        $this->runMigration();

        $row = DB::prepared_query('SELECT "UseGrid" FROM "Page" WHERE "ID" = ?', [$pageId2])->record();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['UseGrid']);
    }

    public function testDryRunDoesNotSetUseGrid(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);

        // Set UseGrid to 0 to verify dry run doesn't change it
        DB::prepared_query('UPDATE "Page" SET "UseGrid" = 0 WHERE "ID" = ?', [$pageId]);

        $service = $this->createService();
        $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: true,
            pageIds: [$pageId],
        );

        $row = DB::prepared_query('SELECT "UseGrid" FROM "Page" WHERE "ID" = ?', [$pageId])->record();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['UseGrid'], 'Dry run should not modify UseGrid');
    }

    public function testMigrationHandlesGridDisabledOnLiveButEnabledOnDraft(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seedStandardPage($pageId, $areaId);

        // Publish the page so Page_Live has the row
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->publishSingle();

        // Simulate: grid enabled on draft, disabled on live.
        // This can happen if a page was published with grid off, then
        // re-enabled on draft but not yet re-published.
        DB::prepared_query(
            'UPDATE "Page" SET "UseElementalGrid" = 1 WHERE "ID" = ?',
            [$pageId],
        );
        DB::prepared_query(
            'UPDATE "Page_Live" SET "UseElementalGrid" = 0 WHERE "ID" = ?',
            [$pageId],
        );

        $this->runMigration();

        // Draft should be enabled (content was migrated)
        $draftRow = DB::prepared_query('SELECT "UseGrid" FROM "Page" WHERE "ID" = ?', [$pageId])->record();
        self::assertSame(1, (int) $draftRow['UseGrid'], 'Draft UseGrid should be 1');

        // Live should be disabled (UseElementalGrid was 0 on live)
        $liveRow = DB::prepared_query('SELECT "UseGrid" FROM "Page_Live" WHERE "ID" = ?', [$pageId])->record();
        self::assertSame(0, (int) $liveRow['UseGrid'], 'Live UseGrid should be 0 — grid was disabled on live');
    }

    // ─── Test Group 13: Batch summary + stop-on-first-failure ───────

    public function testRunLogsBatchSummaryOfSucceededAndFailedPageIds(): void
    {
        // Page 1: will fail (FAIL_ME element triggers TestFailingMigrationExtension)
        // Page 2: will succeed (normal element title)
        $pageId1 = $this->getPageId();
        $pageId2 = $this->getPageId2();

        $this->seeder->seedPage($pageId1, 10100);
        $this->seeder->seedElement(10101, 10100, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'FAIL_ME',
        ]);
        $this->seeder->seedContentMedia(10101);

        $this->seeder->seedPage($pageId2, 10200);
        $this->seeder->seedElement(10201, 10200, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Normal Element',
        ]);
        $this->seeder->seedContentMedia(10201);

        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);

        try {
            $service = $this->createService();
            $service->run(
                self::DEFAULT_VIEWPORT,
                self::ZONE,
                self::VIEWPORT_KEY_MAP,
                dryRun: false,
                pageIds: [$pageId1, $pageId2],
            );

            // Summary must be emitted at warning level (one failure present)
            $warningMessages = $this->getLogMessages('warning');
            $summaryFound = false;
            foreach ($warningMessages as $msg) {
                if (\str_contains($msg, 'Migration batch complete')) {
                    $summaryFound = true;
                    self::assertStringContainsString('1 page(s) succeeded', $msg, 'Summary must name the succeeded count');
                    self::assertStringContainsString('1 failed', $msg, 'Summary must name the failed count');
                    self::assertStringContainsString((string) $pageId1, $msg, 'Summary must list the failing page ID');
                }
            }
            self::assertTrue($summaryFound, 'A batch summary warning must be emitted when pages fail');
        } finally {
            DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        }
    }

    public function testStopOnFirstFailureHaltsRemainingPages(): void
    {
        // Both pages have FAIL_ME elements — with stopOnFirstFailure only the first is attempted
        $pageId1 = $this->getPageId();
        $pageId2 = $this->getPageId2();

        $this->seeder->seedPage($pageId1, 10300);
        $this->seeder->seedElement(10301, 10300, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'FAIL_ME',
        ]);
        $this->seeder->seedContentMedia(10301);

        $this->seeder->seedPage($pageId2, 10400);
        $this->seeder->seedElement(10401, 10400, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'FAIL_ME',
        ]);
        $this->seeder->seedContentMedia(10401);

        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);

        try {
            $service = $this->createService();
            $failures = $service->run(
                self::DEFAULT_VIEWPORT,
                self::ZONE,
                self::VIEWPORT_KEY_MAP,
                dryRun: false,
                pageIds: [$pageId1, $pageId2],
                stopOnFirstFailure: true,
            );

            // Only the first page in the loop was attempted; the break halted the rest
            self::assertSame(1, $failures, 'stop-on-first-failure must halt after the first failing page');

            // Only one error was logged (one page attempted)
            $errors = $this->getLogMessages('error');
            self::assertCount(1, $errors, 'Only the first page failure should be logged');

            // Summary reflects 1 failure and 0 succeeded
            $warningMessages = $this->getLogMessages('warning');
            $summaryFound = false;
            foreach ($warningMessages as $msg) {
                if (\str_contains($msg, 'Migration batch complete')) {
                    $summaryFound = true;
                    self::assertStringContainsString('0 page(s) succeeded', $msg);
                    self::assertStringContainsString('1 failed', $msg);
                }
            }
            self::assertTrue($summaryFound, 'A batch summary must be emitted after stop-on-first-failure halts');
        } finally {
            DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        }
    }

    public function testStopOnFirstFailureFalseContinuesPastFailure(): void
    {
        // Both pages fail; without stopOnFirstFailure both must be attempted
        $pageId1 = $this->getPageId();
        $pageId2 = $this->getPageId2();

        $this->seeder->seedPage($pageId1, 10500);
        $this->seeder->seedElement(10501, 10500, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'FAIL_ME',
        ]);
        $this->seeder->seedContentMedia(10501);

        $this->seeder->seedPage($pageId2, 10600);
        $this->seeder->seedElement(10601, 10600, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'FAIL_ME',
        ]);
        $this->seeder->seedContentMedia(10601);

        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);

        try {
            $service = $this->createService();
            $failures = $service->run(
                self::DEFAULT_VIEWPORT,
                self::ZONE,
                self::VIEWPORT_KEY_MAP,
                dryRun: false,
                pageIds: [$pageId1, $pageId2],
                // stopOnFirstFailure defaults to false — loop must continue
            );

            self::assertSame(2, $failures, 'Without stop-on-first-failure, both failing pages must be attempted');

            $errors = $this->getLogMessages('error');
            self::assertCount(2, $errors, 'Both page failures must be logged');

            // Summary lists both failed page IDs
            $warningMessages = $this->getLogMessages('warning');
            $summaryFound = false;
            foreach ($warningMessages as $msg) {
                if (\str_contains($msg, 'Migration batch complete')) {
                    $summaryFound = true;
                    self::assertStringContainsString('0 page(s) succeeded', $msg);
                    self::assertStringContainsString('2 failed', $msg);
                    self::assertStringContainsString((string) $pageId1, $msg);
                    self::assertStringContainsString((string) $pageId2, $msg);
                }
            }
            self::assertTrue($summaryFound, 'A batch summary warning must be emitted listing all failed page IDs');
        } finally {
            DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        }
    }

    // ─── Test Group 14: Batch-level scaffold suppression ────────────

    public function testScaffoldingSuppressedAcrossEntireBatch(): void
    {
        // Capture the natural config values before run() so the restore
        // assertion is independent of whatever the project default happens to be.
        $sectionAutoScaffoldBefore = (bool) Section::config()->get('auto_scaffold');
        $rowAutoScaffoldBefore = (bool) Row::config()->get('auto_scaffold');

        $pageId1 = $this->getPageId();
        $pageId2 = $this->getPageId2();

        // Page 1: one content element, no explicit row → implicit Section
        $this->seeder->seedPage($pageId1, 10700);
        $this->seeder->seedElement(10701, 10700, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Batch Element Page 1',
        ]);
        $this->seeder->seedContentMedia(10701);

        // Page 2: one content element, no explicit row → implicit Section
        $this->seeder->seedPage($pageId2, 10800);
        $this->seeder->seedElement(10801, 10800, self::CONTENT_CLASS, 1, [
            'SizeMD' => 12,
            'Title' => 'Batch Element Page 2',
        ]);
        $this->seeder->seedContentMedia(10801);

        $service = $this->createService();
        $failures = $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: false,
            pageIds: [$pageId1, $pageId2],
        );
        self::assertSame(0, $failures, 'Both pages must migrate without failures');

        // Auto-scaffold config must be restored to its original values after run()
        // returns, regardless of the suppression strategy (per-page or batch-level).
        // The existing testMigrationRestoresProjectLevelAutoScaffoldFalse test already
        // pins the single-page case; this asserts it holds for a two-page batch.
        self::assertSame(
            $sectionAutoScaffoldBefore,
            (bool) Section::config()->get('auto_scaffold'),
            'Section::auto_scaffold must be restored to its original value after run() returns',
        );
        self::assertSame(
            $rowAutoScaffoldBefore,
            (bool) Row::config()->get('auto_scaffold'),
            'Row::auto_scaffold must be restored to its original value after run() returns',
        );

        // Verify no duplicate auto-scaffolded Rows or Columns were inserted.
        // The strategy produces exactly 1 Section → 1 Row → 1 Column → 1 element
        // per page (no explicit row delimiter → one implicit section). If
        // auto_scaffold were active during any Section write, an extra Row would
        // appear (Section::onAfterWrite → Row). If active during a Row write, an
        // extra Column would appear (Row::onAfterWrite → Column).
        Versioned::set_stage(Versioned::DRAFT);

        foreach ([$pageId1, $pageId2] as $pageId) {
            $sections = Section::get()->filter([
                'ParentID' => $pageId,
                'Zone' => self::ZONE,
            ]);
            self::assertCount(
                1,
                $sections,
                "Page {$pageId}: strategy produces exactly 1 Section; auto-scaffold during Section write would add duplicates",
            );

            $section = $sections->first();
            self::assertInstanceOf(Section::class, $section);

            $rows = Row::get()->filter([
                'ParentID' => $section->ID,
                'ParentClass' => Section::class,
            ]);
            self::assertCount(
                1,
                $rows,
                "Page {$pageId}: strategy produces exactly 1 Row; auto-scaffold during Section write would add a spurious Row",
            );

            $row = $rows->first();
            self::assertInstanceOf(Row::class, $row);

            $columns = Column::get()->filter([
                'ParentID' => $row->ID,
                'ParentClass' => Row::class,
            ]);
            self::assertCount(
                1,
                $columns,
                "Page {$pageId}: strategy produces exactly 1 Column; auto-scaffold during Row write would add a spurious Column",
            );

            $column = $columns->first();
            self::assertInstanceOf(Column::class, $column);

            $elements = GridElement::get()->filter([
                'ParentID' => $column->ID,
                'ParentClass' => Column::class,
            ]);
            self::assertCount(
                1,
                $elements,
                "Page {$pageId}: exactly 1 content element expected from strategy output",
            );
        }
    }
}

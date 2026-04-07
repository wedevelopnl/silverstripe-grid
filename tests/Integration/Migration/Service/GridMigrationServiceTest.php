<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Strategy\AllRowsInSectionStrategy;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(GridMigrationService::class)]
final class GridMigrationServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    protected static $extra_dataobjects = [TestCustomElement::class];

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
        $this->seeder->truncateTables();

        // Clean ORM grid tables from previous tests. DDL in createTables()
        // may have committed the SapphireTest transaction, so ORM records
        // from previous tests are not reliably rolled back.
        $this->cleanGridTables();

        $this->reader = new LegacyDataReader();
        $this->mapper = new FieldMapper();
        $this->logger = new class () extends NullLogger {
            /** @var list<string> */
            public array $errors = [];

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->errors[] = \strtr((string) $message, [
                    '{pageId}' => (string) ($context['pageId'] ?? ''),
                    '{message}' => (string) ($context['message'] ?? ''),
                ]);
            }
        };
    }

    protected function tearDown(): void
    {
        $this->seeder->dropTables();

        parent::tearDown();
    }

    /**
     * Remove all records from GridElement and related tables to prevent leaking between tests.
     */
    private function cleanGridTables(): void
    {
        $tables = [
            'TestCustomElement', 'TestCustomElement_Live',
            'ContentElement', 'ContentElement_Live',
            'Column', 'Column_Live',
            'Row', 'Row_Live',
            'Section', 'Section_Live',
            'GridElement', 'GridElement_Live',
        ];

        $allTables = \SilverStripe\ORM\DB::table_list();

        foreach ($tables as $table) {
            if (\array_key_exists(\strtolower($table), $allTables)) {
                \SilverStripe\ORM\DB::query("DELETE FROM \"{$table}\"");
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
        return (int) $this->objFromFixture(SiteTree::class, 'test_page')->ID;
    }

    private function getPageId2(): int
    {
        return (int) $this->objFromFixture(SiteTree::class, 'test_page_2')->ID;
    }

    private function runMigration(?GridMigrationService $service = null, ?int $pageId = null): void
    {
        $service = $service ?? $this->createService();
        $targetPageId = $pageId ?? $this->getPageId();

        $service->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: false,
            pageIds: [$targetPageId],
        );
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
            'ParentClass' => SiteTree::class,
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
            'MediaVideoFullURL' => 'https://example.com/video.mp4',
            'MediaVideoCustomThumbnailID' => 99,
        ]);

        $this->runMigration();

        // Find the created ContentElement
        $contentElement = ContentElement::get()->filter([
            'ParentClass' => Column::class,
        ])->first();
        self::assertInstanceOf(ContentElement::class, $contentElement, 'ContentElement should exist with ParentClass=Column');

        // Verify mapped fields
        self::assertSame(6, (int) $contentElement->ContentColumns);
        self::assertSame('center', $contentElement->VerticalAlignment);
        self::assertSame(2, (int) $contentElement->GapSize);
        self::assertSame('image', $contentElement->MediaType);
        self::assertSame('16x9', $contentElement->MediaRatio);
        self::assertSame('first', $contentElement->MediaPosition);
        self::assertSame(42, (int) $contentElement->MediaImageID);
        self::assertSame('https://example.com/video.mp4', $contentElement->VideoURL);
        self::assertSame(99, (int) $contentElement->VideoCustomThumbnailID);
    }

    public function testSortOrderPreservedThroughHierarchy(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Two rows with elements
        $this->seeder->seedElement(3000, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(3000);
        $this->seeder->seedElement(3001, $areaId, self::CONTENT_CLASS, 2, ['SizeMD' => 6, 'Title' => 'First']);
        $this->seeder->seedContentMedia(3001);
        $this->seeder->seedElement(3002, $areaId, self::CONTENT_CLASS, 3, ['SizeMD' => 6, 'Title' => 'Second']);
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

        // Verify columns in first row maintain sort
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

        // Run again
        $this->runMigration();
        $secondRunCount = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->count();

        self::assertSame($firstRunCount, $secondRunCount);
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

        GridMigrationService::add_extension(TestFailingMigrationExtension::class);

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
            self::assertNotEmpty($this->logger->errors);
            self::assertStringContainsString('Deliberate test failure', $this->logger->errors[0]);
        } finally {
            GridMigrationService::remove_extension(TestFailingMigrationExtension::class);
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

        // No row elements at all
        $this->seeder->seedElement(7200, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Orphan 1',
        ]);
        $this->seeder->seedContentMedia(7200);
        $this->seeder->seedElement(7201, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
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

        // With one row containing both elements
        $row = Row::get()->filter(['ParentID' => $sections->first()->ID])->first();
        self::assertInstanceOf(Row::class, $row);
        $columns = Column::get()->filter(['ParentID' => $row->ID]);
        self::assertCount(2, $columns);
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

    public function testAdjacentRowsCreateEmptyRow(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Two adjacent rows (no content elements between them)
        $this->seeder->seedElement(8200, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(8200);
        $this->seeder->seedElement(8210, $areaId, self::ROW_CLASS, 2);
        $this->seeder->seedRow(8210);
        $this->seeder->seedElement(8211, $areaId, self::CONTENT_CLASS, 3, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(8211);

        $this->runMigration();

        // Should create 2 sections
        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => self::ZONE,
        ])->sort('Sort', 'ASC');
        self::assertCount(2, $sections);

        // First section's row should have no columns (empty row)
        $firstRow = Row::get()->filter(['ParentID' => $sections->first()->ID])->first();
        self::assertInstanceOf(Row::class, $firstRow);
        $firstRowColumns = Column::get()->filter(['ParentID' => $firstRow->ID]);
        self::assertCount(0, $firstRowColumns);
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
        GridMigrationService::add_extension(TestClassNameMappingExtension::class);

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
            GridMigrationService::remove_extension(TestClassNameMappingExtension::class);
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
        GridMigrationService::add_extension(TestMigrationExtension::class);

        try {
            $this->runMigration($service);

            $element = ContentElement::get()->filter(['ParentClass' => Column::class])->first();
            self::assertInstanceOf(ContentElement::class, $element);
            // The extension sets Style = 'hook-applied'
            self::assertSame('hook-applied', $element->Style);
        } finally {
            GridMigrationService::remove_extension(TestMigrationExtension::class);
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
        GridMigrationService::add_extension(TestClassNameMappingExtension::class);

        try {
            $this->runMigration();

            $element = GridElement::get()->filter([
                'Title' => 'Overridden',
                'ParentClass' => Column::class,
            ])->first();
            self::assertInstanceOf(TestCustomElement::class, $element);
            self::assertSame(TestCustomElement::class, $element->ClassName);
        } finally {
            GridMigrationService::remove_extension(TestClassNameMappingExtension::class);
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
}

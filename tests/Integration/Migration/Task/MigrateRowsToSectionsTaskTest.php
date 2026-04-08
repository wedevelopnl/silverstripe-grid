<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use WeDevelop\Grid\Migration\Task\MigrateRowsToSectionsTask;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(MigrateRowsToSectionsTask::class)]
final class MigrateRowsToSectionsTaskTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    // Disable SapphireTest's per-test transaction wrapping. The migration
    // task uses its own transactions, and the LegacyTableSeeder's DDL
    // (CREATE TABLE) auto-commits in MySQL, which breaks savepoint-based
    // transaction nesting.
    protected $usesTransactions = false;

    private const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    private const string ROW_CLASS = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

    private LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->addExtensionColumns('SiteTree');
        $this->seeder->truncateTables();

        $this->cleanGridTables();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeExtensionColumns('SiteTree');
        $this->seeder->dropTables();

        parent::tearDown();
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $options
     */
    private function executeTask(array $options): int
    {
        $task = new MigrateRowsToSectionsTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput($options, $definition);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);
        return $task->execute($input, $output);
    }

    private function getPageId(): int
    {
        return (int) $this->objFromFixture(SiteTree::class, 'test_page')->ID;
    }

    private function getPageId2(): int
    {
        return (int) $this->objFromFixture(SiteTree::class, 'test_page_2')->ID;
    }

    /**
     * Seed a page with one row and two content elements.
     */
    private function seedStandardPage(int $pageId, int $areaId = 100): void
    {
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(1000, $areaId, self::ROW_CLASS, 1, [
            'Title' => 'Test Row',
        ]);
        $this->seeder->seedRow(1000);

        $this->seeder->seedElement(1001, $areaId, self::CONTENT_CLASS, 2, [
            'Title' => 'Element One',
            'SizeMD' => 8,
        ]);
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>Hello</p>']);

        $this->seeder->seedElement(1002, $areaId, self::CONTENT_CLASS, 3, [
            'Title' => 'Element Two',
            'SizeMD' => 4,
        ]);
        $this->seeder->seedContentMedia(1002, ['HTML' => '<p>World</p>']);
    }

    /**
     * Remove all records from GridElement and related tables to prevent leaking between tests.
     */
    private function cleanGridTables(): void
    {
        $tables = [
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

    // ─── Tests ────────────────────────────────────────────────────

    public function testMissingDefaultViewportReturnsFailure(): void
    {
        $exitCode = $this->executeTask(['--zone' => 'main']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertCount(0, Section::get());
    }

    public function testMissingZoneReturnsFailure(): void
    {
        $exitCode = $this->executeTask(['--default-viewport' => 'MD']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertCount(0, Section::get());
    }

    public function testExecuteWithValidArgsCreatesHierarchy(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => SiteTree::class,
            'Zone' => 'main',
        ]);
        self::assertCount(1, $sections);

        $section = $sections->first();
        self::assertInstanceOf(Section::class, $section);

        $rows = Row::get()->filter([
            'ParentID' => $section->ID,
            'ParentClass' => Section::class,
        ]);
        self::assertCount(1, $rows);

        $row = $rows->first();
        self::assertInstanceOf(Row::class, $row);

        $columns = Column::get()->filter([
            'ParentID' => $row->ID,
            'ParentClass' => Row::class,
        ]);
        self::assertCount(2, $columns);
    }

    public function testExecuteUsesRowPerSectionStrategy(): void
    {
        $pageId = $this->getPageId();
        $areaId = 200;
        $this->seeder->seedPage($pageId, $areaId);

        // Two rows → RowPerSection strategy should produce 2 Sections
        $this->seeder->seedElement(2000, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(2000);
        $this->seeder->seedElement(2001, $areaId, self::CONTENT_CLASS, 2, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(2001);

        $this->seeder->seedElement(2010, $areaId, self::ROW_CLASS, 3);
        $this->seeder->seedRow(2010);
        $this->seeder->seedElement(2011, $areaId, self::CONTENT_CLASS, 4, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(2011);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => 'main',
        ]);
        // RowPerSection: one Section per ElementRow
        self::assertCount(2, $sections);
    }

    public function testDryRunCreatesNoRecords(): void
    {
        $pageId = $this->getPageId();
        $this->seedStandardPage($pageId);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(0, Section::get());
        self::assertCount(0, Row::get());
        self::assertCount(0, Column::get());
    }

    public function testPageIdsFilterRestrictsMigration(): void
    {
        $pageId1 = $this->getPageId();
        $pageId2 = $this->getPageId2();

        $this->seedStandardPage($pageId1, 100);

        $this->seeder->seedPage($pageId2, 101);
        $this->seeder->seedElement(3000, 101, self::ROW_CLASS, 1);
        $this->seeder->seedRow(3000);
        $this->seeder->seedElement(3001, 101, self::CONTENT_CLASS, 2, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(3001);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--page-ids' => (string) $pageId1,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        // Page 1 should have Sections
        self::assertGreaterThan(0, Section::get()->filter([
            'ParentID' => $pageId1,
            'Zone' => 'main',
        ])->count());

        // Page 2 should NOT have Sections
        self::assertCount(0, Section::get()->filter([
            'ParentID' => $pageId2,
            'Zone' => 'main',
        ]));
    }

    public function testViewportMapParsedFromArgument(): void
    {
        $pageId = $this->getPageId();
        $areaId = 400;
        $this->seeder->seedPage($pageId, $areaId);

        // Element with SizeMD=8 and SizeXL=6; only MD and XL are in the viewport map
        $this->seeder->seedElement(4000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'SizeXL' => 6,
            'SizeSM' => 10, // SM not in map — should not produce a sm override
        ]);
        $this->seeder->seedContentMedia(4000);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => 'MD=md,XL=xl',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);

        $settings = $column->getGridSettings();

        // Default from MD=8
        self::assertSame(8, $settings->default->width);

        // XL override exists
        self::assertTrue($settings->hasOverride('xl'));
        $xlOverride = $settings->getOverride('xl');
        self::assertNotNull($xlOverride);
        self::assertSame(6, $xlOverride->width);

        // SM was not in viewport map — no sm override
        self::assertFalse($settings->hasOverride('sm'));

        // lg/xs not in map — no such overrides
        self::assertFalse($settings->hasOverride('lg'));
        self::assertFalse($settings->hasOverride('xs'));
    }

    public function testViewportMapAutoDerivesFromAdapter(): void
    {
        $pageId = $this->getPageId();
        $areaId = 500;
        $this->seeder->seedPage($pageId, $areaId);

        // Bootstrap default adapter: xs,sm,md,lg,xl,xxl
        // SizeSM=6 should become sm override; SizeMD=12 becomes the default
        $this->seeder->seedElement(5000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeSM' => 6,
            'SizeMD' => 12,
        ]);
        $this->seeder->seedContentMedia(5000);

        // No --viewport-map arg — task derives from active adapter
        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);

        $settings = $column->getGridSettings();

        // Default from MD=12
        self::assertSame(12, $settings->default->width);

        // SM override should be keyed as 'sm'
        self::assertTrue($settings->hasOverride('sm'));
        $smOverride = $settings->getOverride('sm');
        self::assertNotNull($smOverride);
        self::assertSame(6, $smOverride->width);
    }

    public function testViewportMapWithWhitespaceParsesCorrectly(): void
    {
        $pageId = $this->getPageId();
        $areaId = 600;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(6000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'SizeXL' => 6,
        ]);
        $this->seeder->seedContentMedia(6000);

        // Whitespace around = and , should be trimmed
        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => ' MD = md , XL = xl ',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);

        $settings = $column->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertTrue($settings->hasOverride('xl'));
        self::assertSame(6, $settings->getOverride('xl')?->width);
    }

    public function testViewportMapSinglePairParsedCorrectly(): void
    {
        $pageId = $this->getPageId();
        $areaId = 700;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(7000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 10,
        ]);
        $this->seeder->seedContentMedia(7000);

        // Single pair without commas
        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => 'MD=md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);
        self::assertSame(10, $column->getGridSettings()->default->width);
    }

    public function testViewportMapMalformedPairsAreSkipped(): void
    {
        $pageId = $this->getPageId();
        $areaId = 800;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(8000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'SizeXL' => 6,
        ]);
        $this->seeder->seedContentMedia(8000);

        // "BROKEN" has no = sign → should be skipped, only MD=md and XL=xl used
        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => 'MD=md,BROKEN,XL=xl',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);

        $settings = $column->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertTrue($settings->hasOverride('xl'));
    }
}

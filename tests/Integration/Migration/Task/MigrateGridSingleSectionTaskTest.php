<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(MigrateGridTask::class)]
final class MigrateGridSingleSectionTaskTest extends SapphireTest
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
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->truncateTables();

        $this->cleanGridTables();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeExtensionColumns('Page');
        $this->seeder->dropTables();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function executeTask(array $options): int
    {
        // Inject --force so the new confirmation gate doesn't block non-interactive tests,
        // and --strategy=single-section to select the AllRowsInSection strategy (the
        // behaviour the former MigrateRowsToSingleSectionTask hard-coded).
        // Using += preserves any per-test override (e.g. --dry-run).
        $options += ['--force' => true, '--strategy' => 'single-section'];

        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput($options, $definition);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);
        return $task->execute($input, $output);
    }

    private function getPageId(): int
    {
        return (int) $this->objFromFixture(Page::class, 'test_page')->ID;
    }

    /**
     * Remove all records from GridElement and related tables to prevent leaking between tests.
     */
    private function cleanGridTables(): void
    {
        $tables = [
            'WeDevelop_Grid_ContentElement', 'WeDevelop_Grid_ContentElement_Live',
            'WeDevelop_Grid_Column', 'WeDevelop_Grid_Column_Live',
            'WeDevelop_Grid_Row', 'WeDevelop_Grid_Row_Live',
            'WeDevelop_Grid_Section', 'WeDevelop_Grid_Section_Live',
            'WeDevelop_Grid_GridElement', 'WeDevelop_Grid_GridElement_Live',
        ];

        $allTables = \SilverStripe\ORM\DB::table_list();

        foreach ($tables as $table) {
            if (\array_key_exists(\strtolower($table), $allTables)) {
                \SilverStripe\ORM\DB::query("DELETE FROM \"{$table}\"");
            }
        }
    }

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

    public function testLowercaseDefaultViewportIsNormalisedAndPreservesColumnWidth(): void
    {
        // 'md' is the NEW adapter spelling; the legacy sizeFields are keyed 'MD'.
        // Unnormalised, the lookup missed and every migrated column silently became
        // full-width on a destructive run. Asserting only "the task did not error"
        // would pass against the buggy code too, so assert the migrated width.
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);
        $this->seeder->seedElement(7400, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Half width',
        ]);
        $this->seeder->seedContentMedia(7400, []);

        $exitCode = $this->executeTask(['--default-viewport' => 'md', '--zone' => 'main']);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->first();
        self::assertInstanceOf(Column::class, $column);
        self::assertSame(6, $column->getGridSettings()->default->width, 'lowercase md must map to legacy MD');
    }

    public function testExecuteUsesAllRowsInSectionStrategy(): void
    {
        $pageId = $this->getPageId();
        $areaId = 100;
        $this->seeder->seedPage($pageId, $areaId);

        // Two rows → AllRowsInSection strategy should produce 1 Section with 2 Rows
        $this->seeder->seedElement(1000, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(1000);
        $this->seeder->seedElement(1001, $areaId, self::CONTENT_CLASS, 2, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(1001);

        $this->seeder->seedElement(1010, $areaId, self::ROW_CLASS, 3);
        $this->seeder->seedRow(1010);
        $this->seeder->seedElement(1011, $areaId, self::CONTENT_CLASS, 4, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(1011);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => Page::class,
            'Zone' => 'main',
        ]);

        // AllRowsInSection: all ElementRows go under a single Section
        self::assertCount(1, $sections);

        $section = $sections->first();
        self::assertInstanceOf(Section::class, $section);

        $rows = Row::get()->filter([
            'ParentID' => $section->ID,
            'ParentClass' => Section::class,
        ]);
        self::assertCount(2, $rows);
    }
}

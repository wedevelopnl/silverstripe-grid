<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

#[CoversClass(MigrateGridTask::class)]
final class MigrateGridSingleSectionTaskTest extends MigrationTestCase
{
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

        return TaskRunner::run(new MigrateGridTask(), $options)['exitCode'];
    }

    public function testLowercaseDefaultViewportIsNormalisedAndPreservesColumnWidth(): void
    {
        // 'md' is the NEW adapter spelling; the legacy sizeFields are keyed 'MD'.
        // Unnormalised, the lookup missed and every migrated column silently became
        // full-width on a destructive run. Asserting only "the task did not error"
        // would pass against the buggy code too, so assert the migrated width.
        $pageId = $this->pageId();
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
        $pageId = $this->pageId();
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

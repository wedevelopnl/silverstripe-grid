<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTreeSnapshot;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * A SilverStripe 5 site's legacy content, untouched, migrates in one task run
 * and the run closes with a summary of what it converted.
 */
#[CoversClass(MigrateGridTask::class)]
final class MigrateGridTaskSummaryTest extends MigrationTestCase
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/three-pages.yml';

    /**
     * Fixture handle → [area ID, rows, content blocks per row]: 14 non-row blocks in total.
     */
    private const array PAGES = [
        'page_one' => [100, 1, 5],
        'page_two' => [200, 2, 2],
        'page_three' => [300, 1, 5],
    ];

    public function testMigratesEveryPageOnBothStagesAndPrintsTheSummary(): void
    {
        $blockTitles = $this->seedPublishedPages();

        $result = TaskRunner::run(new MigrateGridTask(), [
            '--default-viewport' => 'MD',
            '--zone' => self::ZONE,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertSame(
            "Migrated 3 page(s) and 14 content block(s); 0 page(s) skipped as already migrated, 0 failed.\n",
            $result['output'],
        );

        foreach ($blockTitles as $handle => $titles) {
            $pageId = $this->pageId($handle);
            foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                $tree = MigrationTreeSnapshot::snapshotTree($pageId, Page::class, self::ZONE, $stage);
                self::assertSame(
                    $titles,
                    MigrationTreeSnapshot::allElementTitles($tree),
                    "{$handle} carries its blocks on {$stage}",
                );
            }
        }
    }

    public function testDryRunPrintsWhatWouldBeMigrated(): void
    {
        $this->seedPublishedPages();

        $result = TaskRunner::run(new MigrateGridTask(), [
            '--default-viewport' => 'MD',
            '--zone' => self::ZONE,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertSame(
            "Would migrate 3 page(s) and 14 content block(s); 0 page(s) skipped as already migrated, 0 failed.\n",
            $result['output'],
        );
    }

    public function testCountsAnAlreadyMigratedPageAsSkippedWithNoBlocks(): void
    {
        $this->seedPublishedPages();
        $options = ['--default-viewport' => 'MD', '--zone' => self::ZONE, '--force' => true];
        TaskRunner::run(new MigrateGridTask(), $options);

        $result = TaskRunner::run(new MigrateGridTask(), $options);

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertSame(
            "Migrated 0 page(s) and 0 content block(s); 3 page(s) skipped as already migrated, 0 failed.\n",
            $result['output'],
        );
    }

    /**
     * Seed every fixture page with the same legacy rows and blocks on draft and
     * live, and publish it, as a SilverStripe 5 site stores published content.
     *
     * @return array<non-empty-string, list<string>> fixture handle → block titles in page order
     */
    private function seedPublishedPages(): array
    {
        $blockTitles = [];
        $elementId = 1000;

        foreach (self::PAGES as $handle => [$areaId, $rowCount, $blocksPerRow]) {
            $this->seeder->seedPage($this->pageId($handle), $areaId);
            $this->publishPage($handle, $areaId);

            $blockTitles[$handle] = [];
            $sort = 0;
            for ($row = 1; $row <= $rowCount; $row++) {
                $rowId = ++$elementId;
                ++$sort;
                foreach (['draft', 'live'] as $stage) {
                    $this->seeder->seedElement($rowId, $areaId, self::ROW_CLASS, $sort, ['Title' => "Row {$rowId}"], $stage);
                    $this->seeder->seedRow($rowId, stage: $stage);
                }

                for ($block = 1; $block <= $blocksPerRow; $block++) {
                    $blockId = ++$elementId;
                    ++$sort;
                    $title = "{$handle} row {$row} block {$block}";
                    foreach (['draft', 'live'] as $stage) {
                        $this->seeder->seedElement($blockId, $areaId, self::CONTENT_CLASS, $sort, [
                            'Title' => $title,
                            'SizeMD' => 6,
                        ], $stage);
                        $this->seeder->seedContentMedia($blockId, ['HTML' => "<p>{$title}</p>"], $stage);
                    }
                    $blockTitles[$handle][] = $title;
                }
            }
        }

        return $blockTitles;
    }

    /**
     * @param non-empty-string $handle
     */
    private function publishPage(string $handle, int $areaId): void
    {
        $page = $this->objFromFixture(Page::class, $handle);
        $page->publishSingle();
        DB::prepared_query(
            'UPDATE "Page_Live" SET "UseElementalGrid" = 1, "ElementalAreaID" = ? WHERE "ID" = ?',
            [$areaId, $page->ID],
        );
    }
}

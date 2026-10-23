<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Migration\Service\LegacyElementReader;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTreeSnapshot;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * A site that worked around the old table-name bug by hand-adding a
 * `BaseElement` copy of `Element` keeps that copy after the fix. The copy is
 * stale by the time the migration runs, so it must never be read.
 */
#[CoversClass(LegacyElementReader::class)]
final class MigrateGridTaskIgnoresBaseElementCopyTest extends MigrationTestCase
{
    private const array WORKAROUND_TABLES = ['BaseElement', 'BaseElement_Live'];

    public function onBeforeLoadFixtures(): void
    {
        parent::onBeforeLoadFixtures();

        foreach (self::WORKAROUND_TABLES as $table) {
            $source = \str_replace('BaseElement', 'Element', $table);
            DB::query("CREATE TABLE IF NOT EXISTS \"{$table}\" LIKE \"{$source}\"");
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::tempDB()->isUsed()) {
            foreach (self::WORKAROUND_TABLES as $table) {
                DB::query("DROP TABLE IF EXISTS \"{$table}\"");
            }
        }

        parent::tearDownAfterClass();
    }

    public function testMigratesTheElementTableContentAndNotTheStaleCopy(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->publishSingle();
        DB::prepared_query(
            'UPDATE "Page_Live" SET "UseElementalGrid" = 1, "ElementalAreaID" = 100 WHERE "ID" = ?',
            [$pageId],
        );

        foreach (['draft', 'live'] as $stage) {
            $this->seeder->seedElement(1001, 100, self::CONTENT_CLASS, 1, ['Title' => 'Current title', 'SizeMD' => 6], $stage);
            $this->seeder->seedContentMedia(1001, ['HTML' => '<p>Current</p>'], $stage);
        }
        foreach (self::WORKAROUND_TABLES as $table) {
            $source = \str_replace('BaseElement', 'Element', $table);
            DB::query("INSERT INTO \"{$table}\" SELECT * FROM \"{$source}\"");
            DB::query("UPDATE \"{$table}\" SET \"Title\" = 'Stale title', \"SizeMD\" = 12");
        }

        $result = TaskRunner::run(new MigrateGridTask(), [
            '--default-viewport' => 'MD',
            '--zone' => self::ZONE,
            ...TaskRunner::ACKNOWLEDGED,
        ]);

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            $tree = MigrationTreeSnapshot::snapshotTree($pageId, Page::class, self::ZONE, $stage);
            self::assertSame(['Current title'], MigrationTreeSnapshot::allElementTitles($tree), "title on {$stage}");
            $column = MigrationTreeSnapshot::findColumnContaining($tree, 'Current title');
            self::assertNotNull($column);
            self::assertSame(6, $column['gridDefault']['width'], "width on {$stage}");
            self::assertSame('<p>Current</p>', MigrationTreeSnapshot::elementHtml($column, 'Current title'), "HTML on {$stage}");
        }
    }
}

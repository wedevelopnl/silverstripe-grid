<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\ORM\DB;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Migration\Task\AbstractMigrationTask;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * A database without the elemental 5.x `Element` table is not a supported
 * starting point: the task refuses up front, before the requirements banner,
 * the confirmation prompts and any write.
 */
#[CoversClass(AbstractMigrationTask::class)]
final class MigrateGridTaskMissingElementTableTest extends MigrationTestCase
{
    public function onBeforeLoadFixtures(): void
    {
        parent::onBeforeLoadFixtures();

        DB::query('DROP TABLE IF EXISTS "Element"');
    }

    public function testRefusesBeforeTheConfirmationPromptAndWritesNothing(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        $versionBefore = (int) Page::get()->byID($pageId)?->Version;

        // Interactive with an empty answer: reaching the prompt would read EOF,
        // abort and return SUCCESS, so FAILURE proves the refusal came first.
        $result = TaskRunner::runInteractive(
            new MigrateGridTask(),
            ['--default-viewport' => 'MD', '--zone' => self::ZONE],
            '',
        );

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertSame(
            'Legacy element table "Element" not found. The migration reads the elemental tables of a site on '
            . AbstractMigrationTask::SUPPORTED_SOURCE . '; nothing was changed.' . "\n",
            $result['output'],
        );
        self::assertSame(0, GridElement::get()->count(), 'no grid element was written');
        self::assertSame($versionBefore, (int) Page::get()->byID($pageId)?->Version, 'the page was not changed');
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Migration\Task\MigrateGridWithFluentTask;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * The Fluent task closes with one summary line per locale pass, never a sum
 * across locales: a page converted in two locales is not two pages.
 */
#[CoversClass(MigrateGridWithFluentTask::class)]
final class MigrateGridWithFluentTaskSummaryTest extends FluentMigrationTestCase
{
    public function testPrintsOneSummaryLinePerLocalePass(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(7000, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN One']);
        $this->seeder->seedContentMedia(7000, ['HTML' => '<p>EN</p>']);
        $this->seeder->addFieldLocalisedTables();
        $this->seeder->seedLocalisedElement(7000, 'nl_NL', ['Title' => 'NL One']);

        $result = TaskRunner::run(new MigrateGridWithFluentTask(), [
            '--default-viewport' => self::DEFAULT_VIEWPORT,
            '--zone' => self::ZONE,
            ...TaskRunner::ACKNOWLEDGED,
        ]);

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertStringEndsWith(
            "\n[en_US] Migrated 1 page(s) and 1 content block(s); 0 page(s) skipped as already migrated, 0 failed.\n"
            . "[nl_NL] Migrated 1 page(s) and 1 content block(s); 0 page(s) skipped as already migrated, 0 failed.\n",
            $result['output'],
        );
    }
}

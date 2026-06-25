<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(MigrateGridTask::class)]
final class MigrateGridTaskGuardTest extends SapphireTest
{
    // Disable SapphireTest's per-test transaction wrapping: the LegacyTableSeeder's
    // DDL (CREATE TABLE) auto-commits in MySQL, which breaks savepoint nesting.
    protected $usesTransactions = false;

    private LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeFieldLocalisedTables();
        $this->seeder->removeLocaleIdColumn();
        $this->seeder->dropTables();
        parent::tearDown();
    }

    public function testRefusesWhenLocalisedLegacyTablesPresent(): void
    {
        $this->seeder->addFieldLocalisedTables();

        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput(['--default-viewport' => 'MD', '--zone' => 'main', '--dry-run' => true], $definition);
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: new BufferedOutput());

        $result = $task->execute($input, $output);

        self::assertSame(Command::FAILURE, $result, 'must refuse on localised legacy data');
    }

    public function testRefusesCleanlyOnAmbiguousLegacyConfiguration(): void
    {
        // Both the field-localised table and the isolated LocaleID column present
        // is an unsupported mixed Fluent config. The detector throws on it; the
        // task must convert that into a clean Command::FAILURE with the message
        // rather than letting the exception escape run() as a raw stack trace.
        $this->seeder->addFieldLocalisedTables();
        $this->seeder->addLocaleIdColumn();

        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput(['--default-viewport' => 'MD', '--zone' => 'main', '--dry-run' => true], $definition);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $result = $task->execute($input, $output);

        self::assertSame(Command::FAILURE, $result);
        self::assertStringContainsString('Ambiguous legacy localisation', $buffered->fetch());
    }
}

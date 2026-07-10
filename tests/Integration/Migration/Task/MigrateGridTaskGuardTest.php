<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(MigrateGridTask::class)]
final class MigrateGridTaskGuardTest extends SapphireTest
{
    protected $usesDatabase = true;

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

    public function testRejectsInvalidDefaultViewport(): void
    {
        // --default-viewport must be one of the legacy keys (XS/SM/MD/LG/XL). An
        // unvalidated value misses the uppercase-keyed legacy sizeFields lookup and
        // silently full-widths every migrated column, so it must fail loudly.
        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput(['--default-viewport' => 'ZZ', '--zone' => 'main', '--dry-run' => true], $definition);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $result = $task->execute($input, $output);

        self::assertSame(Command::FAILURE, $result);
        self::assertStringContainsString('Invalid --default-viewport "ZZ"', $buffered->fetch());
    }

    public function testFailsWhenNoLegacyKeyMatchesAnAdapterViewport(): void
    {
        // Bulma's viewports (mobile/tablet/desktop/...) share no names with the
        // legacy XS/SM/MD/LG/XL set, so the derived map is empty. Migrating with an
        // empty map would silently drop every responsive override.
        Injector::inst()->registerService(new BulmaAdapter(), GridAdapterInterface::class);

        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput(['--default-viewport' => 'MD', '--zone' => 'main', '--dry-run' => true], $definition);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $result = $task->execute($input, $output);

        $rendered = $buffered->fetch();

        self::assertSame(Command::FAILURE, $result);
        self::assertStringContainsString('Could not derive a viewport map', $rendered);
        // The remediation half of the message must survive in order: asserting only the
        // opening clause lets a reordered or truncated message pass.
        self::assertStringContainsString('. Pass --viewport-map with explicit old=new pairs.', $rendered);
    }

    public function testRefusesWhenLocalisedLegacyTablesPresent(): void
    {
        $this->seeder->addFieldLocalisedTables();

        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput(['--default-viewport' => 'MD', '--zone' => 'main', '--dry-run' => true], $definition);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $result = $task->execute($input, $output);

        self::assertSame(Command::FAILURE, $result, 'must refuse on localised legacy data');
        self::assertStringContainsString(
            'Fluent site detected (locale-isolated grid or localised legacy tables). '
            . 'Run "migrate-grid-with-fluent" instead — this task migrates content without '
            . 'locale context and would write records invisible in every locale.',
            $buffered->fetch(),
        );
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

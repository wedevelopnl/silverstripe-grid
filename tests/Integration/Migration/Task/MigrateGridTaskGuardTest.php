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
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;
use WeDevelop\Grid\Tests\Unit\Support\GridAdapterStub;
use WeDevelop\Grid\Value\Viewport;

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

    public function testAcceptsLowercaseDefaultViewport(): void
    {
        // Legacy sizeFields are keyed uppercase; the task normalises the option,
        // so a lowercase spelling must work rather than silently full-width
        // every column (the pre-validation failure mode).
        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput(['--default-viewport' => 'md', '--zone' => 'main', '--dry-run' => true], $definition);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $result = $task->execute($input, $output);

        self::assertSame(Command::SUCCESS, $result, $buffered->fetch());
    }

    public function testRejectsMalformedViewportMapPairBeforeConfirmation(): void
    {
        // No --dry-run, no --force, non-interactive: the destructive-confirmation
        // gate would normally refuse this run. Input validation must come first —
        // operators should never be asked to confirm (or blocked on the gate)
        // only to have the arguments rejected afterwards.
        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => 'MD=md,BROKEN,XL=xl',
        ], $definition);
        $input->setInteractive(false);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $result = $task->execute($input, $output);
        $printed = $buffered->fetch();

        self::assertSame(Command::FAILURE, $result);
        self::assertStringContainsString('BROKEN', $printed);
        self::assertStringNotContainsString('destructive', $printed, 'Validation must precede the confirmation gate');
    }

    public function testRejectsUnderivableViewportMapWithActionableMessage(): void
    {
        // An adapter sharing no key names with the legacy XS/SM/MD/LG/XL set
        // (Bulma-style) cannot auto-derive a map; without an explicit
        // --viewport-map the task must refuse rather than silently drop every
        // responsive override.
        Injector::inst()->registerService(
            new GridAdapterStub([
                new Viewport('mobile', 'Mobile', 0),
                new Viewport('tablet', 'Tablet', 769),
            ]),
            GridAdapterInterface::class,
        );

        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput(['--default-viewport' => 'MD', '--zone' => 'main', '--dry-run' => true], $definition);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $result = $task->execute($input, $output);

        self::assertSame(Command::FAILURE, $result);
        self::assertStringContainsString('Could not derive a viewport map', $buffered->fetch());
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

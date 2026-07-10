<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Grid\Migration\Task\MigrateGridWithFluentTask;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

/**
 * Preflight behaviour for the Fluent migration task.
 *
 * Lives in the `fluent` suite so {@see \TractorCow\Fluent\State\FluentState}
 * is guaranteed present — the task's first preflight check short-circuits
 * when Fluent is absent, which would mask the ambiguous-config path tested
 * here.
 */
#[CoversClass(MigrateGridWithFluentTask::class)]
final class MigrateGridWithFluentTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    // Disable per-test transaction wrapping: the seeder's CREATE TABLE DDL
    // auto-commits in MySQL, which breaks savepoint-based nesting.
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

    public function testRefusesCleanlyOnAmbiguousLegacyConfigurationBeforeConfirmation(): void
    {
        // Both the field-localised table and the isolated LocaleID column present
        // is an unsupported mixed Fluent config. The detector throws on it. The
        // preflight must surface that as a clean Command::FAILURE *before* the
        // destructive confirmation prompt — without the preflight detect() call
        // the throw would only fire inside the orchestrator after the operator
        // confirmed, escaping run() as a raw stack trace.
        $this->seeder->addFieldLocalisedTables();
        $this->seeder->addLocaleIdColumn();

        $task = new MigrateGridWithFluentTask();
        $definition = new InputDefinition($task->getOptions());
        // Interactive with an empty input stream: if execution ever reached the
        // confirmation prompt it would read EOF, default to "no", and return
        // SUCCESS ("aborted"). Asserting FAILURE proves preflight caught the
        // ambiguous config first — before the prompt.
        $input = new ArrayInput(['--default-viewport' => 'MD', '--zone' => 'main'], $definition);
        $input->setInteractive(true);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $input->setStream($stream);

        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $result = $task->execute($input, $output);

        self::assertSame(Command::FAILURE, $result);
        self::assertStringContainsString('Ambiguous legacy localisation', $buffered->fetch());
    }

    public function testRefusesWhenNoDefaultLocaleResolves(): void
    {
        // With no locales configured, Locale::getDefault() resolves to null
        // (Fluent falls back to the first locale only when one exists). The
        // orchestrator's per-locale plan is then empty, so its entire loop —
        // including the grid-disabled reconciliation pass — silently no-ops and
        // the task would report SUCCESS having migrated nothing. The preflight
        // must catch the missing default locale and fail loudly instead.
        Locale::get()->removeAll();
        Locale::clearCached();

        $task = new MigrateGridWithFluentTask();
        $definition = new InputDefinition($task->getOptions());
        // --dry-run bypasses the confirmation gate, so any FAILURE here is the
        // preflight guard (which runs before the gate), not the gate itself.
        $input = new ArrayInput([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--dry-run' => true,
        ], $definition);
        $input->setInteractive(false);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $exitCode = $task->execute($input, $output);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(
            'No Fluent default locale resolves; configure at least one locale '
            . '(and a global default) before migrating per locale, or use '
            . '"migrate-grid" for a single-locale site.',
            $buffered->fetch(),
        );
    }
}

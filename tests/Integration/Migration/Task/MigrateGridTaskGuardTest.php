<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

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
        $result = TaskRunner::run(
            new MigrateGridTask(),
            ['--default-viewport' => 'ZZ', '--zone' => 'main', '--dry-run' => true],
        );

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString('Invalid --default-viewport "ZZ"', $result['output']);
    }

    public function testFailsWhenNoLegacyKeyMatchesAnAdapterViewport(): void
    {
        // Bulma's viewports (mobile/tablet/desktop/...) share no names with the
        // legacy XS/SM/MD/LG/XL set, so the derived map is empty. Migrating with an
        // empty map would silently drop every responsive override.
        Injector::inst()->registerService(new BulmaAdapter(), GridAdapterInterface::class);

        $result = TaskRunner::run(
            new MigrateGridTask(),
            ['--default-viewport' => 'MD', '--zone' => 'main', '--dry-run' => true],
        );

        $rendered = $result['output'];

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString('Could not derive a viewport map', $rendered);
        // The remediation half of the message must survive in order: asserting only the
        // opening clause lets a reordered or truncated message pass.
        self::assertStringContainsString('. Pass --viewport-map with explicit old=new pairs.', $rendered);
    }

    public function testRefusesWhenLocalisedLegacyTablesPresent(): void
    {
        $this->seeder->addFieldLocalisedTables();

        $result = TaskRunner::run(
            new MigrateGridTask(),
            ['--default-viewport' => 'MD', '--zone' => 'main', '--dry-run' => true],
        );

        self::assertSame(Command::FAILURE, $result['exitCode'], 'must refuse on localised legacy data');
        self::assertStringContainsString(
            'Fluent site detected (locale-isolated grid or localised legacy tables). '
            . 'Run "migrate-grid-with-fluent" instead — this task migrates content without '
            . 'locale context and would write records invisible in every locale.',
            $result['output'],
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

        $result = TaskRunner::run(
            new MigrateGridTask(),
            ['--default-viewport' => 'MD', '--zone' => 'main', '--dry-run' => true],
        );

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString('Ambiguous legacy localisation', $result['output']);
    }
}

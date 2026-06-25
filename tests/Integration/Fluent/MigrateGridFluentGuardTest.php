<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

/**
 * The plain `migrate-grid` task must refuse on a Fluent site whose grid is
 * locale-isolated, even for the single-locale (None) legacy model that DB
 * table-shape detection cannot see — otherwise it writes records at
 * LocaleID = 0, invisible in every locale.
 */
#[CoversClass(MigrateGridTask::class)]
final class MigrateGridFluentGuardTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/migration-locales.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [FluentIsolatedExtension::class],
    ];

    protected $usesTransactions = false;

    private const string ZONE = 'main';
    private const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    private LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
        Locale::clearCached();

        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->seeder->dropTables();
        parent::tearDown();
    }

    public function testRefusesOnFluentIsolatedSiteWithNoneModel(): void
    {
        // None model: base legacy content only, NO localised tables / LocaleID column.
        // hasLocalisedContent() is therefore false — the refusal must come from the
        // grid being locale-isolated, not from legacy table shape.
        $pageId = (int) $this->objFromFixture(\Page::class, 'test_page')->ID;
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(7500, 100, self::CONTENT_CLASS, 1, ['Title' => 'Single', 'SizeMD' => 12]);
        $this->seeder->seedContentMedia(7500);

        $task = new MigrateGridTask();
        $definition = new InputDefinition($task->getOptions());
        // --dry-run bypasses the confirmation gate, so any FAILURE here is the
        // preflight guard (which runs before the gate), not the gate itself.
        $input = new ArrayInput([
            '--default-viewport' => 'MD',
            '--zone' => self::ZONE,
            '--dry-run' => true,
        ], $definition);
        $input->setInteractive(false);
        $buffered = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffered);

        $exitCode = $task->execute($input, $output);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('migrate-grid-with-fluent', $buffered->fetch());
    }
}

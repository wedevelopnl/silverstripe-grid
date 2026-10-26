<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Migration\Service\ElementNumberingGuard;
use WeDevelop\Grid\Migration\Task\AbstractMigrationTask;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * New grid elements are numbered above every legacy element ID, so an item
 * still pointing at a legacy block's number was never moved.
 */
#[CoversClass(ElementNumberingGuard::class)]
#[CoversClass(AbstractMigrationTask::class)]
final class ElementNumberingGuardTest extends MigrationTestCase
{
    // The counter outlives a rollback, and one test drops a legacy table: give
    // every test a fresh database.
    protected $usesTransactions = false;

    /**
     * @return iterable<string, array{string}>
     */
    public static function legacySources(): iterable
    {
        yield 'a draft element' => ['draft'];
        yield 'a live element' => ['live'];
        yield 'an archived element in the version history' => ['versions'];
    }

    #[DataProvider('legacySources')]
    public function testNewElementsAreNumberedAboveTheHighestLegacyId(string $highestIn): void
    {
        $this->seeder->seedElement(5000, 100, self::CONTENT_CLASS, 1);
        $this->seeder->seedElement(5000, 100, self::CONTENT_CLASS, 1, stage: 'live');
        $this->seeder->seedElementVersion(5000);
        match ($highestIn) {
            'draft' => $this->seeder->seedElement(7000, 100, self::CONTENT_CLASS, 2),
            'live' => $this->seeder->seedElement(7000, 100, self::CONTENT_CLASS, 2, stage: 'live'),
            default => $this->seeder->seedElementVersion(7000),
        };

        (new ElementNumberingGuard())->raiseAboveLegacyIds();

        self::assertSame(7001, $this->writeElement());
    }

    public function testAHigherNumberingIsNeverLowered(): void
    {
        $this->seeder->seedElement(5000, 100, self::CONTENT_CLASS, 1);
        DB::prepared_query(
            \sprintf('INSERT INTO "%s" ("ID", "ClassName") VALUES (?, ?)', DataObject::getSchema()->tableName(GridElement::class)),
            [9000, GridElement::class],
        );

        (new ElementNumberingGuard())->raiseAboveLegacyIds();

        self::assertSame(9001, $this->writeElement());
    }

    public function testRefusesWhenALegacyTableCannotBeRead(): void
    {
        DB::query('DROP TABLE "Element_Versions"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy element table "Element_Versions" not found');

        (new ElementNumberingGuard())->raiseAboveLegacyIds();
    }

    public function testTheTaskRaisesTheNumberingBeforeTheFirstPage(): void
    {
        $this->seedLegacyPage();

        $result = $this->migrate();

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertGreaterThan(5000, (int) ContentElement::get()->min('ID'));
    }

    public function testADryRunLeavesTheNumberingAlone(): void
    {
        $this->seedLegacyPage();

        $result = $this->migrate(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
        self::assertLessThan(5000, $this->writeElement());
    }

    public function testTheTaskStopsBeforeTheFirstPageWhenTheGuardCannotReadTheLegacyTables(): void
    {
        $this->seedLegacyPage();
        DB::query('DROP TABLE "Element_Versions"');

        $result = $this->migrate();

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString('Legacy element table "Element_Versions" not found', $result['output']);
        self::assertSame(0, GridElement::get()->count(), 'no page was migrated');
    }

    private function seedLegacyPage(): void
    {
        $this->seeder->seedPage($this->pageId(), 100);
        $this->seeder->seedElement(5000, 100, self::CONTENT_CLASS, 1, ['Title' => 'Block']);
        $this->seeder->seedElementVersion(5000);
    }

    private function writeElement(): int
    {
        return (int) ContentElement::create()->write();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{exitCode: int, output: string}
     */
    private function migrate(array $options = []): array
    {
        return TaskRunner::run(new MigrateGridTask(), [
            '--default-viewport' => self::DEFAULT_VIEWPORT,
            '--zone' => self::ZONE,
            ...TaskRunner::ACKNOWLEDGED,
            ...$options,
        ]);
    }
}

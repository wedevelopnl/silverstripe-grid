<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Migration\Service\DraftHierarchyWriter;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Service\TestFailingMigrationExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\FieldMapperConfigStubExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;
use WeDevelop\Grid\Value\VerticalAlignment;

#[CoversClass(MigrateGridTask::class)]
final class MigrateGridTaskTest extends MigrationTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    private function executeTask(array $options): int
    {
        // Inject --force so the new confirmation gate doesn't block non-interactive tests.
        // Using += preserves any per-test override.
        $options += ['--force' => true];

        return $this->executeTaskRaw($options)['exitCode'];
    }

    /**
     * Execute the task without injecting --force, non-interactively.
     *
     * @param array<string, mixed> $options
     * @return array{exitCode: int, output: string}
     */
    private function executeTaskRaw(array $options): array
    {
        return TaskRunner::run(new MigrateGridTask(), $options);
    }

    /**
     * Execute the task with an interactive STDIN-style stream so the destructive
     * confirmation prompt is actually answered (rather than skipped via --force or
     * --dry-run), exercising the real [y/N] gate.
     *
     * @param array<string, mixed> $options
     * @param string               $answer Raw stream content fed to the prompt (e.g. "y\n" or "n\n")
     * @return array{exitCode: int, output: string}
     */
    private function executeTaskInteractive(array $options, string $answer): array
    {
        return TaskRunner::runInteractive(new MigrateGridTask(), $options, $answer);
    }

    /**
     * Seed a page with one row and two content elements.
     */
    private function seedStandardPage(int $pageId, int $areaId = 100): void
    {
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(1000, $areaId, self::ROW_CLASS, 1, [
            'Title' => 'Test Row',
        ]);
        $this->seeder->seedRow(1000);

        $this->seeder->seedElement(1001, $areaId, self::CONTENT_CLASS, 2, [
            'Title' => 'Element One',
            'SizeMD' => 8,
        ]);
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>Hello</p>']);

        $this->seeder->seedElement(1002, $areaId, self::CONTENT_CLASS, 3, [
            'Title' => 'Element Two',
            'SizeMD' => 4,
        ]);
        $this->seeder->seedContentMedia(1002, ['HTML' => '<p>World</p>']);
    }

    public function testMissingDefaultViewportReturnsFailure(): void
    {
        $exitCode = $this->executeTask(['--zone' => 'main']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertCount(0, Section::get());
    }

    public function testMissingZoneReturnsFailure(): void
    {
        $exitCode = $this->executeTask(['--default-viewport' => 'MD']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertCount(0, Section::get());
    }

    public function testUnknownStrategyReturnsFailure(): void
    {
        // A typo like "--strategy=single" must fail loudly rather than silently
        // running the default "sections" strategy on a destructive migration.
        $result = $this->executeTaskRaw([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--strategy' => 'single',
            '--force' => true,
        ]);

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString('Invalid --strategy', $result['output']);
        self::assertCount(0, Section::get());
    }

    public function testExecuteWithValidArgsCreatesHierarchy(): void
    {
        $pageId = $this->pageId();
        $this->seedStandardPage($pageId);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => Page::class,
            'Zone' => 'main',
        ]);
        self::assertCount(1, $sections);

        $section = $sections->first();
        self::assertInstanceOf(Section::class, $section);

        $rows = Row::get()->filter([
            'ParentID' => $section->ID,
            'ParentClass' => Section::class,
        ]);
        self::assertCount(1, $rows);

        $row = $rows->first();
        self::assertInstanceOf(Row::class, $row);

        $columns = Column::get()->filter([
            'ParentID' => $row->ID,
            'ParentClass' => Row::class,
        ]);
        self::assertCount(2, $columns);
    }

    public function testExecuteUsesRowPerSectionStrategy(): void
    {
        $pageId = $this->pageId();
        $areaId = 200;
        $this->seeder->seedPage($pageId, $areaId);

        // Two rows → RowPerSection strategy should produce 2 Sections
        $this->seeder->seedElement(2000, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(2000);
        $this->seeder->seedElement(2001, $areaId, self::CONTENT_CLASS, 2, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(2001);

        $this->seeder->seedElement(2010, $areaId, self::ROW_CLASS, 3);
        $this->seeder->seedRow(2010);
        $this->seeder->seedElement(2011, $areaId, self::CONTENT_CLASS, 4, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(2011);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'Zone' => 'main',
        ]);
        // RowPerSection: one Section per ElementRow
        self::assertCount(2, $sections);
    }

    public function testNonInteractiveRefusesWithoutForce(): void
    {
        $pageId = $this->pageId();
        $this->seedStandardPage($pageId);

        $result = $this->executeTaskRaw([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ]);

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString('Refusing to run', $result['output']);
        self::assertCount(0, Section::get());
        self::assertCount(0, Row::get());
        self::assertCount(0, Column::get());
    }

    public function testDryRunBypassesConfirmationGateWithoutForce(): void
    {
        $pageId = $this->pageId();
        $this->seedStandardPage($pageId);

        $result = $this->executeTaskRaw([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertCount(0, Section::get());
        self::assertCount(0, Row::get());
        self::assertCount(0, Column::get());
    }

    public function testInteractiveConfirmationAcceptedRunsMigration(): void
    {
        $pageId = $this->pageId();
        $this->seedStandardPage($pageId);

        // Answering "y" at the [y/N] prompt proceeds with the destructive write.
        $result = $this->executeTaskInteractive([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ], "y\n");

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertCount(1, Section::get()->filter(['ParentID' => $pageId, 'Zone' => 'main']));
    }

    public function testInteractiveConfirmationDefaultsToNoOnAnEmptyAnswer(): void
    {
        $pageId = $this->pageId();
        $this->seedStandardPage($pageId);

        // Just pressing Enter must take the safe default. The prompt reads [y/N], so a
        // destructive migration may never proceed on an empty answer.
        $result = $this->executeTaskInteractive([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ], "\n");

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertStringContainsString('Migration aborted.', $result['output']);
        self::assertCount(0, Section::get());
    }

    public function testInteractiveConfirmationDeclinedAbortsMigration(): void
    {
        $pageId = $this->pageId();
        $this->seedStandardPage($pageId);

        // Answering "n" at the [y/N] prompt aborts without writing anything.
        $result = $this->executeTaskInteractive([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ], "n\n");

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertStringContainsString('Migration aborted.', $result['output']);
        self::assertCount(0, Section::get());
        self::assertCount(0, Row::get());
        self::assertCount(0, Column::get());
    }

    public function testFailedPageMigrationReportsFailureCount(): void
    {
        // A page whose element throws during the write rolls back and is counted
        // as a failure; the task must surface that count and exit FAILURE.
        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);

        try {
            $pageId = $this->pageId();
            $areaId = 1100;
            $this->seeder->seedPage($pageId, $areaId);
            $this->seeder->seedElement(11001, $areaId, self::CONTENT_CLASS, 1, [
                'Title' => 'FAIL_ME',
                'SizeMD' => 12,
            ]);
            $this->seeder->seedContentMedia(11001);

            $result = $this->executeTaskRaw([
                '--default-viewport' => 'MD',
                '--zone' => 'main',
                '--force' => true,
            ]);

            self::assertSame(Command::FAILURE, $result['exitCode']);
            self::assertStringContainsString('failed to migrate', $result['output']);
            // The deliberate failure rolled back — no partial hierarchy persisted.
            self::assertCount(0, Section::get()->filter(['ParentID' => $pageId, 'Zone' => 'main']));
        } finally {
            DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        }
    }

    public function testDryRunCreatesNoRecords(): void
    {
        $pageId = $this->pageId();
        $this->seedStandardPage($pageId);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(0, Section::get());
        self::assertCount(0, Row::get());
        self::assertCount(0, Column::get());
    }

    public function testPageIdsFilterRestrictsMigration(): void
    {
        $pageId1 = $this->pageId();
        $pageId2 = $this->pageId('test_page_2');

        $this->seedStandardPage($pageId1, 100);

        $this->seeder->seedPage($pageId2, 101);
        $this->seeder->seedElement(3000, 101, self::ROW_CLASS, 1);
        $this->seeder->seedRow(3000);
        $this->seeder->seedElement(3001, 101, self::CONTENT_CLASS, 2, ['SizeMD' => 12]);
        $this->seeder->seedContentMedia(3001);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--page-ids' => (string) $pageId1,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        // Page 1 should have Sections
        self::assertGreaterThan(0, Section::get()->filter([
            'ParentID' => $pageId1,
            'Zone' => 'main',
        ])->count());

        // Page 2 should NOT have Sections
        self::assertCount(0, Section::get()->filter([
            'ParentID' => $pageId2,
            'Zone' => 'main',
        ]));
    }

    public function testViewportMapParsedFromArgument(): void
    {
        $pageId = $this->pageId();
        $areaId = 400;
        $this->seeder->seedPage($pageId, $areaId);

        // Element with SizeMD=8 and SizeXL=6; only MD and XL are in the viewport map
        $this->seeder->seedElement(4000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'SizeXL' => 6,
            'SizeSM' => 10, // SM not in map — should not produce a sm override
        ]);
        $this->seeder->seedContentMedia(4000);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => 'MD=md,XL=xl',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);

        $settings = $column->getGridSettings();

        // Default from MD=8
        self::assertSame(8, $settings->default->width);

        // XL override exists
        self::assertTrue($settings->hasOverride('xl'));
        $xlOverride = $settings->getOverride('xl');
        self::assertNotNull($xlOverride);
        self::assertSame(6, $xlOverride->width);

        // SM was not in viewport map — no sm override
        self::assertFalse($settings->hasOverride('sm'));

        // lg/xs not in map — no such overrides
        self::assertFalse($settings->hasOverride('lg'));
        self::assertFalse($settings->hasOverride('xs'));
    }

    public function testViewportMapAutoDerivesFromAdapter(): void
    {
        $pageId = $this->pageId();
        $areaId = 500;
        $this->seeder->seedPage($pageId, $areaId);

        // Bootstrap default adapter: xs,sm,md,lg,xl,xxl
        // SizeSM=6 should become sm override; SizeMD=12 becomes the default
        $this->seeder->seedElement(5000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeSM' => 6,
            'SizeMD' => 12,
        ]);
        $this->seeder->seedContentMedia(5000);

        // No --viewport-map arg — task derives from active adapter
        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);

        $settings = $column->getGridSettings();

        // Default from MD=12
        self::assertSame(12, $settings->default->width);

        // SM override should be keyed as 'sm'
        self::assertTrue($settings->hasOverride('sm'));
        $smOverride = $settings->getOverride('sm');
        self::assertNotNull($smOverride);
        self::assertSame(6, $smOverride->width);
    }

    public function testViewportMapWithWhitespaceParsesCorrectly(): void
    {
        $pageId = $this->pageId();
        $areaId = 600;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(6000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'SizeXL' => 6,
        ]);
        $this->seeder->seedContentMedia(6000);

        // Whitespace around = and , should be trimmed
        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => ' MD = md , XL = xl ',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);

        $settings = $column->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertTrue($settings->hasOverride('xl'));
        self::assertSame(6, $settings->getOverride('xl')?->width);
    }

    public function testViewportMapSplitsEachPairOnTheFirstEqualsSignOnly(): void
    {
        // A pair containing a second "=" must still parse into exactly two parts, so the
        // bogus new key reaches validation and is reported. Splitting into three parts
        // would silently drop the pair and leave an empty map instead.
        $result = $this->executeTaskRaw([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--dry-run' => true,
            '--viewport-map' => 'MD=md=x',
        ]);

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString('Invalid --viewport-map new key "md=x"', $result['output']);
    }

    public function testViewportMapSinglePairParsedCorrectly(): void
    {
        $pageId = $this->pageId();
        $areaId = 700;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(7000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 10,
        ]);
        $this->seeder->seedContentMedia(7000);

        // Single pair without commas
        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => 'MD=md',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);
        self::assertSame(10, $column->getGridSettings()->default->width);
    }

    public function testUpdateFieldMapperConfigExtensionReplacesVerticalAlignMap(): void
    {
        MigrateGridTask::add_extension(FieldMapperConfigStubExtension::class);

        try {
            $pageId = $this->pageId();
            $areaId = 900;
            $this->seeder->seedPage($pageId, $areaId);

            $this->seeder->seedElement(9000, $areaId, self::ROW_CLASS, 1);
            $this->seeder->seedRow(9000);

            $this->seeder->seedElement(9001, $areaId, self::CONTENT_CLASS, 2, [
                'SizeMD' => 12,
            ]);
            // A value that is NOT in FieldMapper's default Bootstrap map. The
            // stub extension's verticalAlignMap rewrites it to 'bottom'; if the
            // hook is not wired through, FieldMapper's default fallback would
            // return 'top' instead.
            $this->seeder->seedContentMedia(9001, [
                'ContentVerticalAlign' => FieldMapperConfigStubExtension::CUSTOM_ALIGN_INPUT,
            ]);

            $exitCode = $this->executeTask([
                '--default-viewport' => 'MD',
                '--zone' => 'main',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $content = ContentElement::get()->first();
            self::assertInstanceOf(ContentElement::class, $content);
            self::assertSame(VerticalAlignment::Bottom->value, $content->VerticalAlignment);
        } finally {
            MigrateGridTask::remove_extension(FieldMapperConfigStubExtension::class);
        }
    }

    public function testFieldMapperUsesBuiltInDefaultsWhenNoExtensionRegistered(): void
    {
        $pageId = $this->pageId();
        $areaId = 950;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(9500, $areaId, self::ROW_CLASS, 1);
        $this->seeder->seedRow(9500);

        $this->seeder->seedElement(9501, $areaId, self::CONTENT_CLASS, 2, [
            'SizeMD' => 12,
        ]);
        // Standard Bootstrap value — should resolve via the built-in default map.
        $this->seeder->seedContentMedia(9501, [
            'ContentVerticalAlign' => 'align-items-center',
        ]);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $content = ContentElement::get()->first();
        self::assertInstanceOf(ContentElement::class, $content);
        self::assertSame(VerticalAlignment::Center->value, $content->VerticalAlignment);
    }

    public function testViewportMapMalformedPairReturnsFailure(): void
    {
        // A pair without "=" must fail loudly, like a --strategy typo: skipping it
        // leaves the map non-empty, so the empty-map guard never fires and every
        // responsive override for that viewport is lost on a destructive run.
        $pageId = $this->pageId();
        $areaId = 800;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(8000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'SizeXL' => 6,
        ]);
        $this->seeder->seedContentMedia(8000);

        $result = $this->executeTaskRaw([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => 'MD=md,BROKEN,XL=xl',
            '--force' => true,
        ]);

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString('Invalid --viewport-map pair "BROKEN"', $result['output']);
        self::assertCount(0, Section::get());
    }

    public function testViewportMapToleratesStrayCommas(): void
    {
        $pageId = $this->pageId();
        $areaId = 800;
        $this->seeder->seedPage($pageId, $areaId);

        $this->seeder->seedElement(8000, $areaId, self::CONTENT_CLASS, 1, [
            'SizeMD' => 8,
            'SizeXL' => 6,
        ]);
        $this->seeder->seedContentMedia(8000);

        $exitCode = $this->executeTask([
            '--default-viewport' => 'MD',
            '--zone' => 'main',
            '--viewport-map' => 'MD=md,,XL=xl,',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $column = Column::get()->filter(['ParentClass' => Row::class])->first();
        self::assertInstanceOf(Column::class, $column);

        $settings = $column->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertTrue($settings->hasOverride('xl'));
    }

    public function testStopOnFirstFailureFlagHaltsAfterFirstPage(): void
    {
        // Both pages fail; with --stop-on-first-failure only the first should be attempted,
        // so the task reports 1 failure (not 2) and exits FAILURE.
        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);

        try {
            $pageId1 = $this->pageId();
            $pageId2 = $this->pageId('test_page_2');

            $this->seeder->seedPage($pageId1, 1200);
            $this->seeder->seedElement(12001, 1200, self::CONTENT_CLASS, 1, [
                'Title' => 'FAIL_ME',
                'SizeMD' => 12,
            ]);
            $this->seeder->seedContentMedia(12001);

            $this->seeder->seedPage($pageId2, 1300);
            $this->seeder->seedElement(13001, 1300, self::CONTENT_CLASS, 1, [
                'Title' => 'FAIL_ME',
                'SizeMD' => 12,
            ]);
            $this->seeder->seedContentMedia(13001);

            $result = $this->executeTaskRaw([
                '--default-viewport' => 'MD',
                '--zone' => 'main',
                '--force' => true,
                '--stop-on-first-failure' => true,
            ]);

            self::assertSame(Command::FAILURE, $result['exitCode']);
            // Only 1 page was attempted before the loop broke
            self::assertStringContainsString('1 page(s) failed to migrate', $result['output']);
        } finally {
            DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        }
    }

    public function testWithoutStopOnFirstFailureBothPagesAttempted(): void
    {
        // Both pages fail; without --stop-on-first-failure both must be attempted,
        // so the task reports 2 failures and exits FAILURE.
        DraftHierarchyWriter::add_extension(TestFailingMigrationExtension::class);

        try {
            $pageId1 = $this->pageId();
            $pageId2 = $this->pageId('test_page_2');

            $this->seeder->seedPage($pageId1, 1400);
            $this->seeder->seedElement(14001, 1400, self::CONTENT_CLASS, 1, [
                'Title' => 'FAIL_ME',
                'SizeMD' => 12,
            ]);
            $this->seeder->seedContentMedia(14001);

            $this->seeder->seedPage($pageId2, 1500);
            $this->seeder->seedElement(15001, 1500, self::CONTENT_CLASS, 1, [
                'Title' => 'FAIL_ME',
                'SizeMD' => 12,
            ]);
            $this->seeder->seedContentMedia(15001);

            $result = $this->executeTaskRaw([
                '--default-viewport' => 'MD',
                '--zone' => 'main',
                '--force' => true,
                // --stop-on-first-failure is absent — both pages must be attempted
            ]);

            self::assertSame(Command::FAILURE, $result['exitCode']);
            self::assertStringContainsString('2 page(s) failed to migrate', $result['output']);
        } finally {
            DraftHierarchyWriter::remove_extension(TestFailingMigrationExtension::class);
        }
    }
}

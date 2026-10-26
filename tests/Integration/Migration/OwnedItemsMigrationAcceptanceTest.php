<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Migration\Service\LivePublisher;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Tests\Integration\Migration\Service\TestFailingLiveRowMigrationExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;
use WeDevelop\Grid\Tests\Integration\Migration\Support\Slide;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestSlideRecipe;
use WeDevelop\Grid\Tests\Integration\Support\RecordingLogger;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * Items a legacy block owns (slides, gallery images) follow the block to its
 * migrated copy when the project registered the documented `onAfterMigrate`
 * recipe ({@see TestSlideRecipe}).
 */
#[CoversNothing]
final class OwnedItemsMigrationAcceptanceTest extends MigrationTestCase
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var list<class-string> */
    protected static $extra_dataobjects = [Slide::class];

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        ContentElement::class => [TestSlideRecipe::class],
    ];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
        Injector::inst()->registerService($this->logger, LoggerInterface::class);
    }

    public function testAMigratedSliderShowsItsSlidesInOrderOnDraftAndLive(): void
    {
        $this->seedPublishedPage('test_page', 100);
        $this->seedBlock(100, 501, 'Slider', ['draft', 'live']);
        $this->seedSlides(501, ['One', 'Two', 'Three', 'Four']);

        $this->migrateSucceeds();

        $slider = $this->migratedBlockId('Slider');
        self::assertSame(['One', 'Two', 'Three', 'Four'], $this->slideTitles($slider, Versioned::DRAFT));
        self::assertSame(['One', 'Two', 'Three', 'Four'], $this->slideTitles($slider, Versioned::LIVE));
    }

    public function testABlockOnlyOnThePublishedPageKeepsItsImagesOnLive(): void
    {
        $this->seedPublishedPage('test_page', 100);
        $this->seedBlock(100, 601, 'Gallery', ['live']);
        $this->seedSlides(601, ['A', 'B', 'C', 'D', 'E', 'F'], liveOnly: true);

        $this->migrateSucceeds();

        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $this->slideTitles($this->migratedBlockId('Gallery'), Versioned::LIVE));
    }

    public function testARunAfterAFailedAttemptLeavesOneSliderWithItsSlides(): void
    {
        $this->seedPublishedPage('test_page', 100);
        foreach (['draft', 'live'] as $stage) {
            $this->seeder->seedElement(500, 100, self::ROW_CLASS, 1, ['Title' => 'FAIL_ME'], $stage);
            $this->seeder->seedRow(500, stage: $stage);
        }
        $this->seedBlock(100, 501, 'Slider', ['draft', 'live'], sort: 2);
        $this->seedSlides(501, ['One', 'Two', 'Three', 'Four']);

        LivePublisher::add_extension(TestFailingLiveRowMigrationExtension::class);
        try {
            self::assertSame(Command::FAILURE, $this->migrate()['exitCode']);
        } finally {
            LivePublisher::remove_extension(TestFailingLiveRowMigrationExtension::class);
        }
        self::assertSame(['One', 'Two', 'Three', 'Four'], $this->slideTitles(501, Versioned::DRAFT), 'the failed attempt left the slides on the legacy number');

        $this->migrateSucceeds();

        self::assertCount(1, ContentElement::get()->filter('Title', 'Slider'));
        self::assertSame(['One', 'Two', 'Three', 'Four'], $this->slideTitles($this->migratedBlockId('Slider'), Versioned::LIVE));
    }

    public function testEachPageSharingAnAreaGetsASliderWithTheSlidesAndTheRunNamesTheArea(): void
    {
        $pageA = $this->pageId();
        $pageB = $this->pageId('test_page_2');
        $this->seedPublishedPage('test_page', 100);
        $this->seeder->seedPageSharingArea($pageB, 100);
        $this->publishPageOnArea('test_page_2', 100);
        $this->seedBlock(100, 501, 'Slider', ['draft', 'live']);
        $this->seedSlides(501, ['One', 'Two', 'Three', 'Four']);

        $output = $this->migrateSucceeds();

        $sliders = ContentElement::get()->filter('Title', 'Slider')->column('ID');
        self::assertCount(2, $sliders);
        foreach ($sliders as $slider) {
            foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                self::assertSame(['One', 'Two', 'Three', 'Four'], $this->slideTitles((int) $slider, $stage), "slider {$slider} on {$stage}");
            }
        }

        self::assertStringContainsString("Shared content areas:\n  Area 100: pages {$pageA}, {$pageB}\n", $output);
        $warnings = \array_values(\array_filter(
            $this->logger->messagesAt('warning'),
            static fn (string $message): bool => \str_contains($message, 'area 100'),
        ));
        self::assertCount(1, $warnings);
        self::assertStringContainsString("pages {$pageA}, {$pageB}", $warnings[0]);
    }

    public function testEachBlockKeepsOnlyItsOwnItemsWhenANewIdEqualsALaterLegacyId(): void
    {
        // Without the numbering guard, page 1's slider would be written as the
        // fourth new grid element (Section, Row, Column, slider) and take the
        // ID the gallery of page 2 still has as its legacy ID.
        $galleryLegacyId = $this->nextGridElementId() + 3;

        $this->seedPublishedPage('test_page', 100);
        $this->seedBlock(100, 9001, 'Slider', ['draft', 'live']);
        $this->seedSlides(9001, ['One', 'Two', 'Three']);
        $this->seedPublishedPage('test_page_2', 200);
        $this->seedBlock(200, $galleryLegacyId, 'Gallery', ['draft', 'live']);
        $this->seedSlides($galleryLegacyId, ['A', 'B', 'C', 'D', 'E']);

        $this->migrateSucceeds();

        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            self::assertSame(['One', 'Two', 'Three'], $this->slideTitles($this->migratedBlockId('Slider'), $stage), "slider on {$stage}");
            self::assertSame(['A', 'B', 'C', 'D', 'E'], $this->slideTitles($this->migratedBlockId('Gallery'), $stage), "gallery on {$stage}");
        }
    }

    /**
     * @param non-empty-string $handle fixture handle
     */
    private function seedPublishedPage(string $handle, int $areaId): void
    {
        $this->seeder->seedPage($this->pageId($handle), $areaId);
        $this->publishPageOnArea($handle, $areaId);
    }

    /**
     * @param non-empty-string $handle fixture handle
     */
    private function publishPageOnArea(string $handle, int $areaId): void
    {
        $page = $this->objFromFixture(Page::class, $handle);
        $page->publishSingle();
        DB::prepared_query(
            'UPDATE "Page_Live" SET "UseElementalGrid" = 1, "ElementalAreaID" = ? WHERE "ID" = ?',
            [$areaId, $page->ID],
        );
    }

    /**
     * @param list<'draft'|'live'> $stages
     */
    private function seedBlock(int $areaId, int $legacyId, string $title, array $stages, int $sort = 1): void
    {
        foreach ($stages as $stage) {
            $this->seeder->seedElement($legacyId, $areaId, self::CONTENT_CLASS, $sort, ['Title' => $title], $stage);
            $this->seeder->seedContentMedia($legacyId, [], $stage);
        }
    }

    /**
     * Published slides of a legacy block, in order; with `$liveOnly` they were
     * deleted from draft, as archiving their block did.
     *
     * @param list<string> $titles
     */
    private function seedSlides(int $legacyBlockId, array $titles, bool $liveOnly = false): void
    {
        foreach ($titles as $index => $title) {
            $slide = Slide::create(['Title' => $title, 'Sort' => $index + 1, 'SliderID' => $legacyBlockId]);
            $slide->write();
            $slide->publishSingle();
            if ($liveOnly) {
                $slide->deleteFromStage(Versioned::DRAFT);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function slideTitles(int $sliderId, string $stage): array
    {
        /** @var list<string> */
        return Versioned::get_by_stage(Slide::class, $stage)
            ->filter('SliderID', $sliderId)
            ->sort('Sort')
            ->column('Title');
    }

    private function migratedBlockId(string $title): int
    {
        $block = ContentElement::get()->find('Title', $title);
        self::assertInstanceOf(ContentElement::class, $block);

        return (int) $block->ID;
    }

    private function nextGridElementId(): int
    {
        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::prepared_query(\sprintf('INSERT INTO "%s" ("ClassName") VALUES (?)', $table), [GridElement::class]);
        $probe = DB::get_generated_id($table);
        DB::prepared_query(\sprintf('DELETE FROM "%s" WHERE "ID" = ?', $table), [$probe]);

        return $probe + 1;
    }

    /**
     * @return array{exitCode: int, output: string}
     */
    private function migrate(): array
    {
        return TaskRunner::run(new MigrateGridTask(), [
            '--default-viewport' => self::DEFAULT_VIEWPORT,
            '--zone' => self::ZONE,
            ...TaskRunner::ACKNOWLEDGED,
        ]);
    }

    private function migrateSucceeds(): string
    {
        $result = $this->migrate();
        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);

        return $result['output'];
    }
}

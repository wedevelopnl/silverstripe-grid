<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Task;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Migration\Service\DraftHierarchyWriter;
use WeDevelop\Grid\Migration\Service\LivePublisher;
use WeDevelop\Grid\Migration\Task\MigrateGridTask;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;
use WeDevelop\Grid\Tests\Integration\Migration\Support\SeedsPublishedLegacyContent;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestLiveRowSettingsMigrationExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestRowSettingsExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestRowSettingsMigrationExtension;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * A converting site carries its own legacy row settings — a background colour
 * and a "card row" switch it added to ElementRow — onto the new Rows and
 * Sections through the row and section field-mapping hooks, on each stage.
 */
#[CoversClass(MigrateGridTask::class)]
final class MigrateGridRowSettingsTest extends MigrationTestCase
{
    use SeedsPublishedLegacyContent;

    protected static $required_extensions = [
        Row::class => [TestRowSettingsExtension::class],
    ];

    private const int AREA_ID = 100;

    public function onBeforeLoadFixtures(): void
    {
        parent::onBeforeLoadFixtures();

        foreach (['ElementRow', 'ElementRow_Live'] as $table) {
            if (!\array_key_exists('BackgroundColour', DB::field_list($table))) {
                DB::query("ALTER TABLE \"{$table}\" ADD COLUMN \"BackgroundColour\" varchar(50) NOT NULL DEFAULT ''");
                DB::query("ALTER TABLE \"{$table}\" ADD COLUMN \"IsCardRow\" tinyint NOT NULL DEFAULT 0");
                DB::query("ALTER TABLE \"{$table}\" ADD COLUMN \"RowImageID\" int NOT NULL DEFAULT 0");
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        DraftHierarchyWriter::add_extension(TestRowSettingsMigrationExtension::class);
        LivePublisher::add_extension(TestLiveRowSettingsMigrationExtension::class);
        $this->seedPublishedPage('test_page', self::AREA_ID);
    }

    protected function tearDown(): void
    {
        DraftHierarchyWriter::remove_extension(TestRowSettingsMigrationExtension::class);
        LivePublisher::remove_extension(TestLiveRowSettingsMigrationExtension::class);

        parent::tearDown();
    }

    public function testAMappedRowSettingIsCarriedOntoTheNewRow(): void
    {
        $this->seedPublishedRow(1000, 1, 'Rooms', 'navy');
        $this->seedPublishedBlock(self::AREA_ID, 1001, 2, '<p>Rooms</p>');

        $this->migrate();

        self::assertSame(['navy'], $this->rowColours(Versioned::DRAFT));
    }

    public function testDraftAndLiveRowSettingsEachLandOnTheirOwnStage(): void
    {
        $this->seedRow(1000, 1, 'Rooms', 'navy', stage: 'draft');
        $this->seedRow(1000, 1, 'Our rooms', 'white', stage: 'live');
        $this->seedPublishedBlock(self::AREA_ID, 1001, 2, '<p>Rooms</p>');

        $this->migrate();

        self::assertSame([['Rooms', 'navy']], $this->rowTitlesAndColours(Versioned::DRAFT));
        self::assertSame([['Our rooms', 'white']], $this->rowTitlesAndColours(Versioned::LIVE));
    }

    public function testARowImageIsCarriedAsItsIdOntoEachStage(): void
    {
        $this->seedRow(1000, 1, 'Rooms', rowImageId: 11, stage: 'draft');
        $this->seedRow(1000, 1, 'Rooms', rowImageId: 12, stage: 'live');
        $this->seedPublishedBlock(self::AREA_ID, 1001, 2, '<p>Rooms</p>');

        $this->migrate();

        foreach ([Versioned::DRAFT => 11, Versioned::LIVE => 12] as $stage => $imageId) {
            $row = Versioned::get_by_stage(Row::class, $stage)->first();
            self::assertInstanceOf(Row::class, $row);
            self::assertSame($imageId, (int) $row->getField('RowImageID'), "row image on {$stage}");
        }
    }

    public function testEachSectionTakesItsStyleFromItsOwnRowUnderTheSectionsStrategy(): void
    {
        $this->seedPublishedRow(1000, 1, 'Cards', isCardRow: true);
        $this->seedPublishedBlock(self::AREA_ID, 1001, 2, '<p>Cards</p>');
        $this->seedPublishedRow(1010, 3, 'Plain');
        $this->seedPublishedBlock(self::AREA_ID, 1011, 4, '<p>Plain</p>');

        $this->migrate('sections');

        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            self::assertSame(['card', ''], $this->sectionStyles($stage), "section styles on {$stage}");
        }
    }

    /**
     * @return iterable<string, array{bool, bool, string}>
     */
    public static function singleSectionCardRows(): iterable
    {
        yield 'first row is the card row' => [true, false, 'card'];
        yield 'only a later row is a card row' => [false, true, ''];
    }

    #[DataProvider('singleSectionCardRows')]
    public function testTheOneSectionTakesItsStyleFromTheFirstRowUnderTheSingleSectionStrategy(
        bool $firstIsCard,
        bool $secondIsCard,
        string $expectedStyle,
    ): void {
        $this->seedPublishedRow(1000, 1, 'First', isCardRow: $firstIsCard);
        $this->seedPublishedBlock(self::AREA_ID, 1001, 2, '<p>First</p>');
        $this->seedPublishedRow(1010, 3, 'Second', isCardRow: $secondIsCard);
        $this->seedPublishedBlock(self::AREA_ID, 1011, 4, '<p>Second</p>');

        $this->migrate('single-section');

        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            self::assertSame([$expectedStyle], $this->sectionStyles($stage), "section style on {$stage}");
        }
    }

    public function testARowMissingOnLiveKeepsItsDraftSettingsOnLive(): void
    {
        $this->seedRow(1000, 1, 'Rooms', 'navy', stage: 'draft');
        $this->seedPublishedBlock(self::AREA_ID, 1001, 2, '<p>Rooms</p>');

        $this->migrate();

        self::assertSame([['Rooms', 'navy']], $this->rowTitlesAndColours(Versioned::LIVE));
    }

    public function testAnUntitledRowCarriesItsDefaultTitleOntoLive(): void
    {
        $this->seedPublishedRow(1000, 1, '');
        $this->seedPublishedBlock(self::AREA_ID, 1001, 2, '<p>Rooms</p>');

        $this->migrate();

        self::assertSame([['Row 1', '']], $this->rowTitlesAndColours(Versioned::DRAFT));
        self::assertSame([['Row 1', '']], $this->rowTitlesAndColours(Versioned::LIVE));
    }

    private function seedPublishedRow(int $id, int $sort, string $title, string $colour = '', bool $isCardRow = false): void
    {
        foreach (['draft', 'live'] as $stage) {
            $this->seedRow($id, $sort, $title, $colour, $isCardRow, $stage);
        }
    }

    private function seedRow(
        int $id,
        int $sort,
        string $title,
        string $colour = '',
        bool $isCardRow = false,
        string $stage = 'draft',
        int $rowImageId = 0,
    ): void {
        $this->seeder->seedElement($id, self::AREA_ID, self::ROW_CLASS, $sort, ['Title' => $title], $stage);
        $this->seeder->seedRow($id, stage: $stage);
        DB::prepared_query(
            \sprintf('UPDATE "%s" SET "BackgroundColour" = ?, "IsCardRow" = ?, "RowImageID" = ? WHERE "ID" = ?', $stage === 'live' ? 'ElementRow_Live' : 'ElementRow'),
            [$colour, $isCardRow ? 1 : 0, $rowImageId, $id],
        );
    }

    private function migrate(string $strategy = 'sections'): void
    {
        $result = TaskRunner::run(new MigrateGridTask(), [
            '--default-viewport' => self::DEFAULT_VIEWPORT,
            '--zone' => self::ZONE,
            '--strategy' => $strategy,
            ...TaskRunner::ACKNOWLEDGED,
        ]);

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
    }

    /**
     * @return list<string>
     */
    private function rowColours(string $stage): array
    {
        return \array_column($this->rowTitlesAndColours($stage), 1);
    }

    /**
     * @return list<array{string, string}>
     */
    private function rowTitlesAndColours(string $stage): array
    {
        $rows = Versioned::get_by_stage(Row::class, $stage)->sort('ID')->toArray();

        return \array_values(\array_map(
            static fn (Row $row): array => [(string) $row->Title, (string) $row->getField('BackgroundColour')],
            $rows,
        ));
    }

    /**
     * @return list<string>
     */
    private function sectionStyles(string $stage): array
    {
        $sections = Versioned::get_by_stage(Section::class, $stage)->sort('Sort')->toArray();

        return \array_values(\array_map(static fn (Section $section): string => (string) $section->Style, $sections));
    }
}

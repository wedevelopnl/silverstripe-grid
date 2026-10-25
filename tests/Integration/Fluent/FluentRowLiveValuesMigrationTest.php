<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\Service\LegacyElementReader;
use WeDevelop\Grid\Migration\Service\LivePublisher;
use WeDevelop\Grid\Migration\Task\MigrateGridWithFluentTask;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * Each locale's new Row and Section take that locale's legacy row values on
 * each stage: the draft values on draft, the published values on live. Covers
 * both legacy models in which a row's values can differ per locale.
 */
#[CoversClass(LegacyElementReader::class)]
#[CoversClass(LivePublisher::class)]
final class FluentRowLiveValuesMigrationTest extends FluentMigrationTestCase
{
    private const string ROW_CLASS = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

    /**
     * Expected Row Title, Row ExtraClass and Section ExtraClass, per locale and stage.
     */
    private const array EXPECTED = [
        'en_US' => [
            Versioned::DRAFT => ['EN Rooms', 'en-row-draft', 'en-section-draft'],
            Versioned::LIVE => ['EN Our rooms', 'en-row-live', 'en-section-live'],
        ],
        'nl_NL' => [
            Versioned::DRAFT => ['NL Kamers', 'nl-row-draft', 'nl-section-draft'],
            Versioned::LIVE => ['NL Onze kamers', 'nl-row-live', 'nl-section-live'],
        ],
    ];

    public function testFieldLocalisedRowsTakeEachLocalesValuesOnEachStage(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->addFieldLocalisedTables();

        foreach (['draft' => Versioned::DRAFT, 'live' => Versioned::LIVE] as $stage => $versionedStage) {
            [$title, $extraClass, $sectionClass] = self::EXPECTED['en_US'][$versionedStage];
            $this->seedRowWithBlock(7000, $stage, $title, $extraClass, $sectionClass);

            [$title, $extraClass, $sectionClass] = self::EXPECTED['nl_NL'][$versionedStage];
            $this->seeder->seedLocalisedElement(7000, 'nl_NL', ['Title' => $title, 'ExtraClass' => $extraClass], $stage);
            $this->seeder->seedLocalisedRow(7000, 'nl_NL', ['CustomSectionClass' => $sectionClass], $stage);
        }

        $this->migrate();

        $this->assertRowValuesPerLocaleAndStage($pageId);
    }

    public function testIsolatedRowsTakeEachLocalesValuesOnEachStage(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->addLocaleIdColumn();
        $localeIds = [
            'en_US' => (int) $this->objFromFixture(Locale::class, 'en')->ID,
            'nl_NL' => (int) $this->objFromFixture(Locale::class, 'nl')->ID,
        ];

        foreach (['draft' => Versioned::DRAFT, 'live' => Versioned::LIVE] as $stage => $versionedStage) {
            foreach (['en_US' => 7100, 'nl_NL' => 7200] as $locale => $rowId) {
                [$title, $extraClass, $sectionClass] = self::EXPECTED[$locale][$versionedStage];
                $this->seedRowWithBlock($rowId, $stage, $title, $extraClass, $sectionClass, ['LocaleID' => $localeIds[$locale]]);
            }
        }

        $this->migrate();

        $this->assertRowValuesPerLocaleAndStage($pageId);
    }

    /**
     * @param array<string, mixed> $elementFields extra legacy Element columns for both the row and its block
     */
    private function seedRowWithBlock(
        int $rowId,
        string $stage,
        string $title,
        string $extraClass,
        string $sectionClass,
        array $elementFields = [],
    ): void {
        $this->seeder->seedElement($rowId, 100, self::ROW_CLASS, 1, ['Title' => $title, 'ExtraClass' => $extraClass, ...$elementFields], $stage);
        $this->seeder->seedRow($rowId, customSectionClass: $sectionClass, stage: $stage);
        $this->seeder->seedElement($rowId + 1, 100, self::CONTENT_CLASS, 2, ['SizeMD' => 12, ...$elementFields], $stage);
        $this->seeder->seedContentMedia($rowId + 1, [], $stage);
    }

    private function migrate(): void
    {
        $result = TaskRunner::run(new MigrateGridWithFluentTask(), [
            '--default-viewport' => self::DEFAULT_VIEWPORT,
            '--zone' => self::ZONE,
            ...TaskRunner::ACKNOWLEDGED,
        ]);

        self::assertSame(Command::SUCCESS, $result['exitCode'], $result['output']);
    }

    private function assertRowValuesPerLocaleAndStage(int $pageId): void
    {
        $actual = [];
        foreach (self::EXPECTED as $locale => $stages) {
            foreach (\array_keys($stages) as $stage) {
                $actual[$locale][$stage] = $this->rowValues($pageId, $locale, $stage);
            }
        }

        self::assertSame(self::EXPECTED, $actual);
    }

    /**
     * @return array{string, string, string} Row Title, Row ExtraClass, Section ExtraClass
     */
    private function rowValues(int $pageId, string $locale, string $stage): array
    {
        return FluentState::singleton()->withState(static function (FluentState $state) use ($pageId, $locale, $stage): array {
            $state->setLocale($locale);

            return Versioned::withVersionedMode(static function () use ($pageId, $stage): array {
                Versioned::set_stage($stage);
                $section = Section::get()->filter(['ParentID' => $pageId, 'Zone' => self::ZONE])->first();
                self::assertInstanceOf(Section::class, $section);
                $row = Row::get()->filter(['ParentID' => $section->ID])->first();
                self::assertInstanceOf(Row::class, $row);

                return [(string) $row->Title, (string) $row->ExtraClass, (string) $section->ExtraClass];
            });
        });
    }
}

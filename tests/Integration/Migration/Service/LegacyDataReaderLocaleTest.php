<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(LegacyDataReader::class)]
final class LegacyDataReaderLocaleTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected $usesTransactions = false;

    private const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    private LegacyTableSeeder $seeder;

    private LegacyDataReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->truncateTables();
        $this->reader = new LegacyDataReader();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeFieldLocalisedTables();
        $this->seeder->removeLocaleIdColumn();
        $this->seeder->dropTables();
        parent::tearDown();
    }

    public function testFieldLocalisedOverlaysLocalisedValues(): void
    {
        $this->seeder->seedElement(7000, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN Title', 'SizeMD' => 6]);
        $this->seeder->seedContentMedia(7000, ['HTML' => '<p>EN</p>']);
        $this->seeder->addFieldLocalisedTables();
        $this->seeder->seedLocalisedElement(7000, 'nl_NL', ['Title' => 'NL Title']);
        $this->seeder->seedLocalisedContent(7000, 'nl_NL', ['HTML' => '<p>NL</p>']);

        $elements = $this->reader->getElementsForAreaInLocale(100, 'draft', LegacyLocalisationModel::FieldLocalised, 'nl_NL', 2);

        self::assertCount(1, $elements);
        self::assertSame('NL Title', $elements[0]->title, 'localised Title overlays base');
        self::assertSame(6, $elements[0]->sizeFields['MD'], 'layout (SizeMD) comes from base, not localised');
        self::assertNotNull($elements[0]->mediaData);
        self::assertSame('<p>NL</p>', $elements[0]->mediaData->fields['HTML'], 'localised HTML overlays base');
    }

    public function testFacadeReturnsEveryElementNotJustTheFirst(): void
    {
        $this->seeder->seedElement(7010, 100, self::CONTENT_CLASS, 1, ['Title' => 'First']);
        $this->seeder->seedElement(7011, 100, self::CONTENT_CLASS, 2, ['Title' => 'Second']);
        $this->seeder->addFieldLocalisedTables();

        $elements = $this->reader->getElementsForAreaInLocale(100, 'draft', LegacyLocalisationModel::FieldLocalised, 'nl_NL', 2);

        self::assertCount(2, $elements);
        self::assertSame(['First', 'Second'], array_map(static fn ($e): string => $e->title, $elements));
    }

    public function testFieldLocalisedFallsBackToBaseWhenUntranslated(): void
    {
        $this->seeder->seedElement(7001, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN Only']);
        $this->seeder->seedContentMedia(7001, ['HTML' => '<p>EN Only</p>']);
        $this->seeder->addFieldLocalisedTables(); // no nl row seeded

        $elements = $this->reader->getElementsForAreaInLocale(100, 'draft', LegacyLocalisationModel::FieldLocalised, 'nl_NL', 2);

        self::assertCount(1, $elements, 'untranslated element is included, not omitted');
        self::assertSame('EN Only', $elements[0]->title, 'falls back to base Title');
        self::assertNotNull($elements[0]->mediaData);
        self::assertSame('<p>EN Only</p>', $elements[0]->mediaData->fields['HTML']);
    }

    public function testIsolatedFiltersByLocaleId(): void
    {
        $this->seeder->addLocaleIdColumn();
        $this->seeder->seedElement(7100, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN', 'LocaleID' => 1]);
        $this->seeder->seedContentMedia(7100);
        $this->seeder->seedElement(7101, 100, self::CONTENT_CLASS, 2, ['Title' => 'NL', 'LocaleID' => 2]);
        $this->seeder->seedContentMedia(7101);

        $en = $this->reader->getElementsForAreaInLocale(100, 'draft', LegacyLocalisationModel::Isolated, 'en_US', 1);
        $nl = $this->reader->getElementsForAreaInLocale(100, 'draft', LegacyLocalisationModel::Isolated, 'nl_NL', 2);

        self::assertSame(['EN'], array_map(static fn ($e) => $e->title, $en));
        self::assertSame(['NL'], array_map(static fn ($e) => $e->title, $nl));
    }

    public function testNoneDelegatesToBaseRead(): void
    {
        $this->seeder->seedElement(7200, 100, self::CONTENT_CLASS, 1, ['Title' => 'Base']);
        $this->seeder->seedContentMedia(7200);

        $elements = $this->reader->getElementsForAreaInLocale(100, 'draft', LegacyLocalisationModel::None, 'en_US', 1);

        self::assertCount(1, $elements);
        self::assertSame('Base', $elements[0]->title);
    }
}

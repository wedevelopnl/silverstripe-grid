<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(LegacyLocalisationDetector::class)]
final class LegacyLocalisationDetectorTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected $usesTransactions = false;

    private LegacyTableSeeder $seeder;

    private LegacyLocalisationDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->truncateTables();
        $this->detector = new LegacyLocalisationDetector();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeFieldLocalisedTables();
        $this->seeder->removeLocaleIdColumn();
        $this->seeder->dropTables();
        parent::tearDown();
    }

    public function testNoneWhenNoLocalisationArtifacts(): void
    {
        self::assertSame(LegacyLocalisationModel::None, $this->detector->detect());
        self::assertFalse($this->detector->hasLocalisedContent());
    }

    public function testNoneWhenTheLegacyElementTableIsAbsentEntirely(): void
    {
        // With no Element table the LocaleID probe never runs, so the detector
        // must fall back to "no locale column" rather than assuming one exists.
        $this->seeder->dropTables();

        try {
            self::assertSame(LegacyLocalisationModel::None, $this->detector->detect());
        } finally {
            // tearDown's removeLocaleIdColumn() calls DB::field_list(), which needs the table.
            $this->seeder->createTables();
        }
    }

    public function testFieldLocalisedWhenLocalisedTablePresent(): void
    {
        $this->seeder->addFieldLocalisedTables();
        self::assertSame(LegacyLocalisationModel::FieldLocalised, $this->detector->detect());
        self::assertTrue($this->detector->hasLocalisedContent());
    }

    public function testNoneWhenOnlyAHandMadeBaseElementLocalisedTableExists(): void
    {
        // A hand-added workaround copy under the old wrong name is not the
        // elemental 5.x table and must not make the site read as field-localised.
        DB::query('CREATE TABLE IF NOT EXISTS "BaseElement_Localised" ("ID" int NOT NULL PRIMARY KEY)');

        try {
            self::assertSame(LegacyLocalisationModel::None, $this->detector->detect());
        } finally {
            DB::query('DROP TABLE IF EXISTS "BaseElement_Localised"');
        }
    }

    public function testIsolatedWhenLocaleIdColumnPresent(): void
    {
        $this->seeder->addLocaleIdColumn();
        self::assertSame(LegacyLocalisationModel::Isolated, $this->detector->detect());
        self::assertTrue($this->detector->hasLocalisedContent());
    }

    public function testThrowsOnAmbiguousMixedConfiguration(): void
    {
        $this->seeder->addFieldLocalisedTables();
        $this->seeder->addLocaleIdColumn();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Ambiguous legacy localisation: both Element_Localised and '
            . 'Element.LocaleID exist. This mixed Fluent configuration is unsupported.',
        );
        $this->detector->detect();
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(LegacyLocalisationDetector::class)]
final class LegacyLocalisationDetectorTest extends SapphireTest
{
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

    public function testFieldLocalisedWhenLocalisedTablePresent(): void
    {
        $this->seeder->addFieldLocalisedTables();
        self::assertSame(LegacyLocalisationModel::FieldLocalised, $this->detector->detect());
        self::assertTrue($this->detector->hasLocalisedContent());
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
        $this->expectExceptionMessageMatches('/mixed/i');
        $this->detector->detect();
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

/**
 * Facade smoke test for {@see LegacyDataReader::getElementsForAreaInLocale()}.
 * The locale-overlay behaviour itself is owned by LegacyElementReaderTest; this
 * suite only pins that the facade delegation returns the full element list.
 */
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
        $this->seeder->dropTables();
        parent::tearDown();
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
}

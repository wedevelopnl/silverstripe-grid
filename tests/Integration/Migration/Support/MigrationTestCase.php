<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use Page;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Tests\Integration\Support\CleansGridTables;

/**
 * Shared lifecycle for migration suites that drive the SS5→SS6 pipeline
 * against seeded legacy tables: owns the legacy schema constants and the
 * LegacyTableSeeder create/extend/truncate/drop lifecycle.
 *
 * Not named `*Test.php`, so PHPUnit's default suffix-based discovery skips it
 * (it is abstract and would be skipped regardless).
 */
abstract class MigrationTestCase extends SapphireTest
{
    use CleansGridTables;

    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    // The LegacyTableSeeder's CREATE TABLE DDL auto-commits in MySQL, which
    // breaks SapphireTest's savepoint-based per-test transaction wrapping. The
    // migration service uses its own transactions, so disable the wrapping and
    // clean grid tables explicitly in setUp() instead.
    protected $usesTransactions = false;

    protected const string DEFAULT_VIEWPORT = 'MD';

    protected const string ZONE = 'main';

    protected const array VIEWPORT_KEY_MAP = [
        'XS' => 'xs',
        'SM' => 'sm',
        'MD' => 'md',
        'LG' => 'lg',
        'XL' => 'xl',
    ];

    protected const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    protected const string ROW_CLASS = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

    protected LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        // FlushableTestState::setUp() clears the reading mode; restore DRAFT so
        // the migration's scaffold-suppression and stage-aware writes behave.
        Versioned::set_stage(Versioned::DRAFT);

        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->truncateTables();

        // Clean ORM grid tables from previous tests. DDL in createTables() may
        // have committed the SapphireTest transaction, so ORM records from
        // previous tests are not reliably rolled back.
        $this->cleanGridTables();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeExtensionColumns('Page');
        $this->seeder->dropTables();

        parent::tearDown();
    }

    /**
     * Resolve a fixture page's database ID.
     *
     * @param non-empty-string $identifier fixture handle from page.yml
     *
     * @return positive-int
     */
    protected function pageId(string $identifier = 'test_page'): int
    {
        $id = (int) $this->objFromFixture(Page::class, $identifier)->ID;
        \assert($id > 0);

        return $id;
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use Page;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * Shared lifecycle for migration suites that drive the SS5→SS6 pipeline
 * against seeded legacy tables: owns the legacy schema constants and creates
 * the legacy tables once per class (dropped again after the class), so each
 * test runs under SapphireTest's ordinary per-test rollback.
 *
 * Not named `*Test.php`, so PHPUnit's default suffix-based discovery skips it
 * (it is abstract and would be skipped regardless).
 */
abstract class MigrationTestCase extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

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

    // The legacy DDL runs once per class, here: after SapphireTest built the
    // temp database and before it loads fixtures and opens the per-test
    // transaction. MySQL DDL implicitly commits, so running it per test (the
    // previous design) forced `$usesTransactions = false` and a full database
    // rebuild after every test — 1-4s each. Subclasses that still issue DDL
    // inside a test body keep that mode themselves; the seeder refuses DDL
    // inside an open transaction so the rollback can never be silently lost.
    // After such a per-test rebuild the fixture state is reset too, so this
    // hook runs again and recreates the tables.
    public function onBeforeLoadFixtures(): void
    {
        parent::onBeforeLoadFixtures();

        $seeder = new LegacyTableSeeder();
        $seeder->createTables();
        $seeder->addExtensionColumns('Page');
    }

    public static function tearDownAfterClass(): void
    {
        // Drop the legacy tables before the parent's schema rebuild, which
        // would otherwise rename the extra Page columns to `_obsolete_*`.
        // A non-transactional subclass killed the database in its last
        // tearDown, so there is nothing left to drop.
        if (static::tempDB()->isUsed()) {
            $seeder = new LegacyTableSeeder();
            $seeder->removeExtensionColumns('Page');
            $seeder->dropTables();
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // FlushableTestState::setUp() clears the reading mode; restore DRAFT so
        // the migration's scaffold-suppression and stage-aware writes behave.
        Versioned::set_stage(Versioned::DRAFT);

        $this->seeder = new LegacyTableSeeder();
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

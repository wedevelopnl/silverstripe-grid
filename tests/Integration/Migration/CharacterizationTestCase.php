<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration;

use Page;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Tests\Integration\Migration\Support\CleansGridTables;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

/**
 * Shared lifecycle and run helpers for the WS4 migration characterization gate.
 *
 * The characterization (golden-master) tests pin the CURRENT observed output of
 * the SS5→SS6 grid migration pipeline. They are regression-locking, not
 * bug-fixing: each test PASSES on the first run against unmodified production
 * code and asserts whatever the pipeline produces today (including any existing
 * quirks). If a test fails, the expected snapshot is mis-pinned — the fix is to
 * correct the expectation, never to change `src/`.
 *
 * This base owns the DDL lifecycle and the non-Fluent service driver lifted
 * from {@see \WeDevelop\Grid\Tests\Integration\Migration\Service\GridMigrationServiceTest},
 * so each scenario subclass shows only the behaviour it characterizes.
 *
 * Not named `*Test.php`, so PHPUnit's default suffix-based discovery skips it
 * (it is abstract and would be skipped regardless).
 */
abstract class CharacterizationTestCase extends SapphireTest
{
    use CleansGridTables;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<int, class-string> */
    protected static $extra_dataobjects = [];

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

    protected LegacyDataReader $reader;

    protected FieldMapper $mapper;

    protected LoggerInterface $logger;

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

        $this->reader = new LegacyDataReader();
        $this->mapper = new FieldMapper();
        $this->logger = new class () extends NullLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
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

    /** Default RowPerSection strategy used by the non-Fluent characterization scenarios. */
    protected function createStrategy(): RowPerSectionStrategy
    {
        return new RowPerSectionStrategy(
            new ElementGrouper(),
            $this->mapper,
            self::DEFAULT_VIEWPORT,
            self::VIEWPORT_KEY_MAP,
        );
    }

    protected function createService(?RowMappingStrategy $strategy = null): GridMigrationService
    {
        return new GridMigrationService(
            $this->reader,
            $this->mapper,
            $strategy ?? $this->createStrategy(),
            $this->logger,
        );
    }

    /**
     * Run the migration for a single page and assert it completed cleanly.
     *
     * @param positive-int $pageId
     */
    protected function runMigration(int $pageId, ?RowMappingStrategy $strategy = null): void
    {
        $failures = $this->createService($strategy)->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: false,
            pageIds: [$pageId],
        );

        self::assertSame(0, $failures, 'Migration should complete without failures');
    }

    /**
     * Publish a fixture page to LIVE and flag legacy grid on `Page_Live`,
     * mirroring GridMigrationServiceTest's UseGrid-live setup, so the migration
     * exercises the published live path.
     *
     * Clears the page's `UseGrid` flag on DRAFT before publishing so the
     * post-migration `UseGrid === 1` assertion proves the migration enabled it,
     * rather than passing on the `Boolean(1)` DB default (the legacy seeder never
     * writes `UseGrid`). The cleared value is carried to LIVE by `publishSingle`.
     *
     * @param positive-int     $pageId
     * @param positive-int     $areaId
     * @param non-empty-string $handle fixture handle from page.yml
     */
    protected function publishPageToLive(int $pageId, int $areaId, string $handle = 'test_page'): void
    {
        $page = $this->objFromFixture(Page::class, $handle);
        $page->UseGrid = false;
        $page->write();
        $page->publishSingle();
        DB::prepared_query(
            'UPDATE "Page_Live" SET "UseElementalGrid" = 1, "ElementalAreaID" = ? WHERE "ID" = ?',
            [$areaId, $pageId],
        );
    }

    /**
     * Logged messages at a level, with PSR-3 placeholders interpolated.
     *
     * @param non-empty-string $level
     *
     * @return list<string>
     */
    protected function logMessages(string $level): array
    {
        $result = [];
        foreach ($this->logger->messages as $entry) {
            if ($entry['level'] !== $level) {
                continue;
            }

            $replacements = [];
            foreach ($entry['context'] as $key => $value) {
                $replacements['{' . $key . '}'] = \is_scalar($value) ? (string) $value : \get_debug_type($value);
            }

            $result[] = \strtr($entry['message'], $replacements);
        }

        return $result;
    }
}

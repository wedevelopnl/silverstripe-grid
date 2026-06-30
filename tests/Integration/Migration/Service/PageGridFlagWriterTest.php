<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\PageGridFlagWriter;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

/**
 * Direct coverage for the UseGrid page-flag collaborator extracted from
 * GridMigrationService. Verifies all public surface: setUseGridOnPage (draft-only,
 * draft+live, live-only) and migrateDisabledGridPages (reconciliation + logging).
 * The no-column guard (resolveUseGridTable → null) is tested via an Injector-scoped
 * DataObjectSchema mock so no production code needs to change.
 */
#[CoversClass(PageGridFlagWriter::class)]
final class PageGridFlagWriterTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    // Disable SapphireTest's per-test transaction wrapping. The LegacyTableSeeder's
    // DDL (CREATE TABLE / ALTER TABLE) issues implicit commits in MySQL, which
    // breaks savepoint-based transaction nesting.
    protected $usesTransactions = false;

    private LegacyTableSeeder $seeder;

    private LegacyDataReader $reader;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->truncateTables();

        $this->reader = new LegacyDataReader();
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

    // ─── Arm 1: setUseGridOnPage — draft only (default) ──────────

    public function testSetUseGridOnPageSetsDraftOnlyByDefault(): void
    {
        $pageId = $this->getPageId();

        $this->createWriter()->setUseGridOnPage($pageId, true);

        $draftRow = DB::prepared_query('SELECT "UseGrid" FROM "Page" WHERE "ID" = ?', [$pageId])->record();
        self::assertNotNull($draftRow);
        self::assertSame(1, (int) $draftRow['UseGrid'], 'Draft UseGrid must be 1');
    }

    // ─── Arm 2: setUseGridOnPage — draft AND live ─────────────────

    public function testSetUseGridOnPageSetsBothDraftAndLive(): void
    {
        $pageId = $this->getPageId();
        // Publish so Page_Live has a row for this page
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->publishSingle();

        $this->createWriter()->setUseGridOnPage($pageId, true, includeLive: true);

        $draftRow = DB::prepared_query('SELECT "UseGrid" FROM "Page" WHERE "ID" = ?', [$pageId])->record();
        self::assertNotNull($draftRow);
        self::assertSame(1, (int) $draftRow['UseGrid'], 'Draft UseGrid must be 1');

        $liveRow = DB::prepared_query('SELECT "UseGrid" FROM "Page_Live" WHERE "ID" = ?', [$pageId])->record();
        self::assertNotNull($liveRow);
        self::assertSame(1, (int) $liveRow['UseGrid'], 'Live UseGrid must be 1');
    }

    // ─── Arm 3: setUseGridOnPage — live only (draft excluded) ────

    public function testSetUseGridOnPageSetsLiveOnlyWhenDraftExcluded(): void
    {
        $pageId = $this->getPageId();
        // Publish so Page_Live has a row, then adjust both to a known state
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->publishSingle();
        // Pre-set draft UseGrid=1 so we can verify it is NOT changed
        DB::prepared_query('UPDATE "Page" SET "UseGrid" = 1 WHERE "ID" = ?', [$pageId]);
        // Pre-set live UseGrid=1 so we can verify it IS changed to 0
        DB::prepared_query('UPDATE "Page_Live" SET "UseGrid" = 1 WHERE "ID" = ?', [$pageId]);

        $this->createWriter()->setUseGridOnPage($pageId, false, includeDraft: false, includeLive: true);

        $draftRow = DB::prepared_query('SELECT "UseGrid" FROM "Page" WHERE "ID" = ?', [$pageId])->record();
        self::assertNotNull($draftRow);
        self::assertSame(1, (int) $draftRow['UseGrid'], 'Draft UseGrid must be unchanged (includeDraft=false)');

        $liveRow = DB::prepared_query('SELECT "UseGrid" FROM "Page_Live" WHERE "ID" = ?', [$pageId])->record();
        self::assertNotNull($liveRow);
        self::assertSame(0, (int) $liveRow['UseGrid'], 'Live UseGrid must be 0 (includeLive=true, enabled=false)');
    }

    // ─── Arm 4: migrateDisabledGridPages ─────────────────────────

    public function testMigrateDisabledGridPagesSetsUseGridFalseAndLogsCount(): void
    {
        $pageId = $this->getPageId();   // draft-disabled (UseElementalGrid=0 default)
        $pageId2 = $this->getPageId2(); // draft-enabled, live-disabled

        // page1: UseElementalGrid=0 on draft (DEFAULT from addExtensionColumns) → draft-disabled
        // page2: UseElementalGrid=1 on draft → NOT draft-disabled; live-disabled
        DB::prepared_query('UPDATE "Page" SET "UseElementalGrid" = 1 WHERE "ID" = ?', [$pageId2]);

        // Publish page2 so Page_Live has a row (UseElementalGrid=0 DEFAULT → live-disabled)
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');
        $page2->publishSingle();

        // Pre-set UseGrid=1 so the change is detectable
        DB::prepared_query('UPDATE "Page" SET "UseGrid" = 1 WHERE "ID" = ?', [$pageId]);
        DB::prepared_query('UPDATE "Page_Live" SET "UseGrid" = 1 WHERE "ID" = ?', [$pageId2]);

        $this->createWriter()->migrateDisabledGridPages();

        // Draft-disabled page should have UseGrid=0 on draft
        $draftRow = DB::prepared_query('SELECT "UseGrid" FROM "Page" WHERE "ID" = ?', [$pageId])->record();
        self::assertNotNull($draftRow);
        self::assertSame(0, (int) $draftRow['UseGrid'], 'Draft UseGrid must be 0 for draft-disabled page');

        // Live-disabled page should have UseGrid=0 on live
        $liveRow = DB::prepared_query('SELECT "UseGrid" FROM "Page_Live" WHERE "ID" = ?', [$pageId2])->record();
        self::assertNotNull($liveRow);
        self::assertSame(0, (int) $liveRow['UseGrid'], 'Live UseGrid must be 0 for live-disabled page');

        // Verify the count summary was logged
        $infoMessages = $this->getLogMessages('info');
        $logText = \implode(' ', $infoMessages);
        self::assertStringContainsString('UseGrid = 0', $logText);
        self::assertStringContainsString('1 draft', $logText);
        self::assertStringContainsString('1 live', $logText);
    }

    // ─── Arm 5: no-op when UseGrid column absent ──────────────────

    public function testSetUseGridOnPageIsNoOpWhenUseGridColumnAbsent(): void
    {
        // Simulate a project where no SiteTree subclass has UseGrid in its ORM
        // schema (i.e. GridPageExtension was never applied). resolveUseGridTable()
        // calls DataObject::getSchema()->classForField(), but DataObject caches the
        // schema instance in a private static property after first use. We must reset
        // that cache so the next call to getSchema() fetches our mock from Injector.
        // SapphireTest::setUp() nests the Injector and tearDown() unnests it, so
        // the Injector registration is automatically discarded after this test.
        // The static cache is explicitly restored in the finally block.
        // Resolve the page ID via ORM before touching the schema, since getPageId()
        // itself calls objFromFixture() which also queries DataObject::getSchema().
        $pageId = $this->getPageId();
        // Pre-set UseGrid=1 (raw SQL — does not go through the ORM schema) so that
        // a no-op can be confirmed by checking the value is still 1 afterwards.
        DB::prepared_query('UPDATE "Page" SET "UseGrid" = 1 WHERE "ID" = ?', [$pageId]);

        $schemaProp = new \ReflectionProperty(\SilverStripe\ORM\DataObject::class, 'schema');
        $savedSchema = $schemaProp->getValue(null);
        $schemaProp->setValue(null, null); // reset static cache so Injector is consulted next

        try {
            $schema = $this->createMock(DataObjectSchema::class);
            $schema->method('classForField')->willReturn(null);
            Injector::inst()->registerService($schema, DataObjectSchema::class);

            // Should return early with no SQL executed (resolveUseGridTable returns null)
            $this->createWriter()->setUseGridOnPage($pageId, false);

            $row = DB::prepared_query('SELECT "UseGrid" FROM "Page" WHERE "ID" = ?', [$pageId])->record();
            self::assertNotNull($row);
            self::assertSame(1, (int) $row['UseGrid'], 'UseGrid must be unchanged when resolveUseGridTable returns null');
        } finally {
            // Restore the real schema instance so subsequent tests are unaffected
            $schemaProp->setValue(null, $savedSchema);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function createWriter(): PageGridFlagWriter
    {
        return new PageGridFlagWriter($this->reader, $this->logger);
    }

    private function getPageId(): int
    {
        return (int) $this->objFromFixture(Page::class, 'test_page')->ID;
    }

    private function getPageId2(): int
    {
        return (int) $this->objFromFixture(Page::class, 'test_page_2')->ID;
    }

    /**
     * Get logged messages filtered by level, with PSR-3 placeholders interpolated.
     *
     * @return list<string>
     */
    private function getLogMessages(string $level): array
    {
        $result = [];
        foreach ($this->logger->messages as $entry) {
            if ($entry['level'] !== $level) {
                continue;
            }
            $replacements = [];
            foreach ($entry['context'] as $key => $value) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
            $result[] = \strtr($entry['message'], $replacements);
        }
        return $result;
    }
}

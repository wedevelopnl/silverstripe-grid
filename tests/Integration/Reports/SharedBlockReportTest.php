<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Reports;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Reports\SharedBlockReport;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(SharedBlockReport::class)]
final class SharedBlockReportTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private SharedBlockReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableAutoScaffolding();

        Versioned::set_stage(Versioned::DRAFT);
        $this->logInWithPermission('ADMIN');

        $this->report = SharedBlockReport::create();
    }

    /** @return array<string, SharedBlock> */
    private function rowsByTitle(): array
    {
        $rows = [];
        foreach ($this->report->sourceRecords() as $block) {
            $rows[(string) $block->Title] = $block;
        }

        return $rows;
    }

    public function testReportsRootTypeUsageAndStatus(): void
    {
        $pageA = $this->objFromFixture(Page::class, 'test_page');
        $pageB = $this->objFromFixture(Page::class, 'test_page_2');

        $block = GridTreeFactory::sharedBlock('Banner');
        GridTreeFactory::section($block, zone: '');
        GridTreeFactory::reference($pageA, $block, zone: 'main');
        GridTreeFactory::reference($pageA, $block, zone: 'sidebar');
        GridTreeFactory::reference($pageB, $block, zone: 'main');

        $row = $this->rowsByTitle()['Banner'] ?? null;
        self::assertInstanceOf(SharedBlock::class, $row);

        self::assertSame('Section', $row->RootTypeLabel);
        self::assertSame(2, $row->UsageCount, 'three placements across two pages');
        self::assertSame('Not published', $row->StatusLabel, 'the wire value is never shown to an author');
    }

    public function testReportsAPublishedBlockAsInSync(): void
    {
        $block = GridTreeFactory::sharedBlock('Published banner');
        GridTreeFactory::section($block, zone: '');
        $block->publishRecursive();

        $row = $this->rowsByTitle()['Published banner'] ?? null;
        self::assertInstanceOf(SharedBlock::class, $row);

        self::assertSame('Published', $row->StatusLabel);
        self::assertSame(0, $row->UsageCount);
    }

    public function testReportsAnEmptyBlock(): void
    {
        GridTreeFactory::sharedBlock('Empty');

        $row = $this->rowsByTitle()['Empty'] ?? null;
        self::assertInstanceOf(SharedBlock::class, $row);

        self::assertSame('Empty', $row->RootTypeLabel, 'an empty block has no root type');
    }

    public function testColumnsCoverTitleRootTypeUsageAndStatus(): void
    {
        self::assertSame(
            ['Title', 'RootTypeLabel', 'UsageCount', 'StatusLabel'],
            array_keys($this->report->columns()),
        );
    }

    /**
     * Asserted through the real GridField the report builds, not through a
     * formatting callback: the escaping is `casting => 'Text'`, applied by
     * GridFieldDataColumns, so only rendering a column proves it happens.
     */
    public function testTitleColumnEscapesTheBlockTitle(): void
    {
        $block = GridTreeFactory::sharedBlock('<img src=x onerror=alert(1)>');

        $rendered = $this->renderColumn($block, 'Title');

        self::assertStringNotContainsString('<img', $rendered);
        self::assertStringContainsString('&lt;img', $rendered);
    }

    public function testStatusColumnRendersTheTranslatedLabel(): void
    {
        $block = GridTreeFactory::sharedBlock('Unpublished banner');
        GridTreeFactory::section($block, zone: '');

        self::assertSame('Not published', $this->renderColumn($block, 'StatusLabel'));
    }

    /** Render one column exactly as the CMS report screen does. */
    private function renderColumn(SharedBlock $block, string $column): string
    {
        $gridField = $this->report->getReportField();
        $dataColumns = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
        self::assertInstanceOf(GridFieldDataColumns::class, $dataColumns);

        $row = $gridField->getList()->find('ID', $block->ID);
        self::assertInstanceOf(SharedBlock::class, $row);

        return (string) $dataColumns->getColumnContent($gridField, $row, $column);
    }
}

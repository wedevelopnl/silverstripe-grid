<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestLegacyReaderFilterExtension;

#[CoversClass(LegacyDataReader::class)]
final class LegacyDataReaderTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    protected static $extra_dataobjects = [TestPage::class];

    private LegacyDataReader $reader;

    private LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->reader = new LegacyDataReader();
        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->seeder->dropTables();

        parent::tearDown();
    }

    public function testGetEligiblePagesReturnsGridEnabledPages(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $this->seeder->seedPage((int) $page->ID, 100);

        $result = $this->reader->getEligiblePages('draft');

        self::assertCount(1, $result);
        self::assertSame((int) $page->ID, $result[0]['pageId']);
        self::assertSame(100, $result[0]['areaId']);
        self::assertSame(SiteTree::class, $result[0]['pageClassName']);
    }

    public function testGetEligiblePagesSkipsDisabledPages(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $this->seeder->seedPage((int) $page->ID, 100, useGrid: false);

        $result = $this->reader->getEligiblePages('draft');

        self::assertSame([], $result);
    }

    public function testGetEligiblePagesReturnsBothPageIdAndAreaId(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $this->seeder->seedPage((int) $page->ID, 200);

        $result = $this->reader->getEligiblePages('draft');

        self::assertCount(1, $result);
        self::assertArrayHasKey('pageId', $result[0]);
        self::assertArrayHasKey('areaId', $result[0]);
        self::assertArrayHasKey('pageClassName', $result[0]);
        self::assertSame(200, $result[0]['areaId']);
    }

    public function testGetEligiblePagesReturnsConcretePageClassName(): void
    {
        $page = TestPage::create();
        $page->Title = 'Subclass Reader Page';
        $page->URLSegment = 'subclass-reader';
        $page->write();
        $this->seeder->seedPage((int) $page->ID, 300);

        $result = $this->reader->getEligiblePages('draft', [(int) $page->ID]);

        self::assertCount(1, $result);
        self::assertSame(TestPage::class, $result[0]['pageClassName']);
    }

    public function testGetEligiblePagesWithPageIdFilter(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $pageId = (int) $page->ID;

        $this->seeder->seedPage($pageId, 100);

        // Filter with the actual page ID — should return it
        $result = $this->reader->getEligiblePages('draft', [$pageId]);
        self::assertCount(1, $result);

        // Filter with a non-existent ID — should return empty
        $result = $this->reader->getEligiblePages('draft', [999999]);
        self::assertSame([], $result);
    }

    public function testGetElementsForAreaReturnsSortedElements(): void
    {
        $areaId = 500;
        $this->seeder->seedElement(10, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 3);
        $this->seeder->seedElement(11, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 1);
        $this->seeder->seedElement(12, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 2);

        $elements = $this->reader->getElementsForArea($areaId, 'draft');

        self::assertCount(3, $elements);
        self::assertSame(11, $elements[0]->id);
        self::assertSame(12, $elements[1]->id);
        self::assertSame(10, $elements[2]->id);
    }

    public function testGetElementsForAreaIncludesGridFields(): void
    {
        $areaId = 501;
        $this->seeder->seedElement(20, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 1, [
            'SizeMD' => 8,
            'SizeLG' => 6,
            'OffsetMD' => 2,
            'VisibilityXS' => 'hidden',
            'VisibilityMD' => 'visible',
        ]);

        $elements = $this->reader->getElementsForArea($areaId, 'draft');

        self::assertCount(1, $elements);
        $element = $elements[0];

        self::assertSame(8, $element->sizeFields['MD']);
        self::assertSame(6, $element->sizeFields['LG']);
        self::assertSame(0, $element->sizeFields['XS']);
        self::assertSame(2, $element->offsetFields['MD']);
        self::assertSame('hidden', $element->visibilityFields['XS']);
        self::assertSame('visible', $element->visibilityFields['MD']);
        self::assertNull($element->visibilityFields['LG']);
    }

    public function testGetElementsForAreaMarksRowElements(): void
    {
        $areaId = 502;
        $rowClassName = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

        $this->seeder->seedElement(30, $areaId, $rowClassName, 1);
        $this->seeder->seedRow(30, isFluid: true, customSectionClass: 'my-section');
        $this->seeder->seedElement(31, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 2);

        $elements = $this->reader->getElementsForArea($areaId, 'draft');

        self::assertCount(2, $elements);
        self::assertTrue($elements[0]->isRow);
        self::assertFalse($elements[1]->isRow);
    }

    public function testGetRowDataReturnsSectionClass(): void
    {
        $this->seeder->seedRow(40, isFluid: true, customSectionClass: 'wide-section');

        $rowData = $this->reader->getRowData(40, 'draft');

        self::assertInstanceOf(LegacyRowData::class, $rowData);
        self::assertSame('wide-section', $rowData->customSectionClass);
    }

    public function testGetRowDataReturnsNullForMissingRow(): void
    {
        $rowData = $this->reader->getRowData(999, 'draft');

        self::assertNull($rowData);
    }

    public function testGetContentMediaDataReturnsAllFields(): void
    {
        $this->seeder->seedContentMedia(50, [
            'ContentColumns' => '6',
            'ContentVerticalAlign' => 'align-items-center',
            'ExtraColumnGap' => 5,
            'MediaType' => 'image',
            'MediaRatio' => '16x9',
            'MediaPosition' => 'order-1',
            'MediaImageID' => 42,
            'MediaVideoFullURL' => 'https://example.com/video.mp4',
        ]);

        $mediaData = $this->reader->getContentMediaData(50, 'draft');

        self::assertInstanceOf(LegacyMediaData::class, $mediaData);
        self::assertSame('6', $mediaData->fields['ContentColumns']);
        self::assertSame('align-items-center', $mediaData->fields['ContentVerticalAlign']);
        self::assertSame(5, (int) $mediaData->fields['ExtraColumnGap']);
        self::assertSame('image', $mediaData->fields['MediaType']);
        self::assertSame('16x9', $mediaData->fields['MediaRatio']);
        self::assertSame('order-1', $mediaData->fields['MediaPosition']);
        self::assertSame(42, (int) $mediaData->fields['MediaImageID']);
        self::assertSame('https://example.com/video.mp4', $mediaData->fields['MediaVideoFullURL']);
    }

    public function testGetContentMediaDataReturnsNullForMissingElement(): void
    {
        $mediaData = $this->reader->getContentMediaData(999, 'draft');

        self::assertNull($mediaData);
    }

    public function testStageHandlingDraftReadsFromBaseTables(): void
    {
        $areaId = 600;
        $this->seeder->seedElement(60, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 1, stage: 'draft');

        // Draft should find the element
        $elements = $this->reader->getElementsForArea($areaId, 'draft');
        self::assertCount(1, $elements);

        // Live should not find it (different table)
        $elements = $this->reader->getElementsForArea($areaId, 'live');
        self::assertSame([], $elements);
    }

    public function testStageHandlingLiveReadsFromLiveTables(): void
    {
        $areaId = 601;
        $this->seeder->seedElement(61, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 1, stage: 'live');

        // Live should find the element
        $elements = $this->reader->getElementsForArea($areaId, 'live');
        self::assertCount(1, $elements);

        // Draft should not find it
        $elements = $this->reader->getElementsForArea($areaId, 'draft');
        self::assertSame([], $elements);
    }

    public function testUpdateLegacyElementsHookIsInvokedAndCanMutate(): void
    {
        $areaId = 850;
        $this->seeder->seedElement(85, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 1, ['Title' => 'Keep Me']);
        $this->seeder->seedElement(86, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 2, ['Title' => 'Filter Me']);

        TestLegacyReaderFilterExtension::reset();
        LegacyDataReader::add_extension(TestLegacyReaderFilterExtension::class);

        try {
            $elements = $this->reader->getElementsForArea($areaId, 'draft');

            self::assertTrue(TestLegacyReaderFilterExtension::$hookCalled);
            self::assertSame($areaId, TestLegacyReaderFilterExtension::$receivedAreaId);
            self::assertSame('draft', TestLegacyReaderFilterExtension::$receivedStage);

            self::assertCount(1, $elements);
            self::assertSame('Keep Me', $elements[0]->title);
        } finally {
            LegacyDataReader::remove_extension(TestLegacyReaderFilterExtension::class);
        }
    }

    public function testGetElementsForAreaEagerLoadsRowData(): void
    {
        $areaId = 700;
        $rowClassName = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

        $this->seeder->seedElement(70, $areaId, $rowClassName, 1, ['Title' => 'My Row']);
        $this->seeder->seedRow(70, isFluid: true, customSectionClass: 'custom-class');

        $elements = $this->reader->getElementsForArea($areaId, 'draft');

        self::assertCount(1, $elements);
        self::assertNotNull($elements[0]->rowData);
        self::assertSame('custom-class', $elements[0]->rowData->customSectionClass);
    }

    public function testGetElementsForAreaEagerLoadsMediaData(): void
    {
        $areaId = 701;

        $this->seeder->seedElement(71, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 1);
        $this->seeder->seedContentMedia(71, ['MediaType' => 'video', 'MediaRatio' => '4x3']);

        $elements = $this->reader->getElementsForArea($areaId, 'draft');

        self::assertCount(1, $elements);
        self::assertNotNull($elements[0]->mediaData);
        self::assertSame('video', $elements[0]->mediaData->fields['MediaType']);
        self::assertSame('4x3', $elements[0]->mediaData->fields['MediaRatio']);
    }

    public function testGetElementsForAreaReturnsEmptyForMissingArea(): void
    {
        $elements = $this->reader->getElementsForArea(99999, 'draft');

        self::assertSame([], $elements);
    }

    public function testGetElementHydratesAllScalarFields(): void
    {
        $areaId = 800;

        $this->seeder->seedElement(80, $areaId, 'App\\Model\\CustomElement', 5, [
            'Title' => 'Hello World',
            'ShowTitle' => 1,
            'TitleTag' => 'h2',
            'TitleClass' => 'text-lg',
            'ExtraClass' => 'highlight',
        ]);

        $elements = $this->reader->getElementsForArea($areaId, 'draft');

        self::assertCount(1, $elements);
        $element = $elements[0];

        self::assertSame(80, $element->id);
        self::assertSame('App\\Model\\CustomElement', $element->className);
        self::assertSame('Hello World', $element->title);
        self::assertTrue($element->showTitle);
        self::assertSame('h2', $element->titleTag);
        self::assertSame('text-lg', $element->titleClass);
        self::assertSame(5, $element->sort);
        self::assertSame('highlight', $element->extraClass);
        self::assertFalse($element->isRow);
    }

    // ─── Invalid stage handling ──────────────────────────────────

    public function testGetEligiblePagesThrowsOnInvalidStage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reader->getEligiblePages('staging');
    }

    public function testGetElementsForAreaThrowsOnInvalidStage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reader->getElementsForArea(1, 'preview');
    }

    public function testGetRowDataThrowsOnInvalidStage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reader->getRowData(1, 'unknown');
    }

    public function testGetContentMediaDataThrowsOnInvalidStage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reader->getContentMediaData(1, 'invalid');
    }

    // ─── Additional coverage ─────────────────────────────────────

    public function testGetContentMediaDataWithPartialFields(): void
    {
        $this->seeder->seedContentMedia(50, ['HTML' => '<p>Sparse</p>', 'MediaType' => 'image']);

        $mediaData = $this->reader->getContentMediaData(50, 'draft');

        self::assertNotNull($mediaData);
        self::assertSame('<p>Sparse</p>', $mediaData->fields['HTML']);
        self::assertSame('image', $mediaData->fields['MediaType']);
        self::assertArrayHasKey('MediaImageID', $mediaData->fields);
    }

    public function testGetEligiblePagesReturnsMultiplePages(): void
    {
        $page1 = (int) $this->objFromFixture(SiteTree::class, 'test_page')->ID;
        $page2 = (int) $this->objFromFixture(SiteTree::class, 'test_page_2')->ID;

        $this->seeder->seedPage($page1, 100);
        $this->seeder->seedPage($page2, 200);

        $pages = $this->reader->getEligiblePages('draft');

        self::assertCount(2, $pages);

        $pageIds = \array_column($pages, 'pageId');
        self::assertContains($page1, $pageIds);
        self::assertContains($page2, $pageIds);
    }

    public function testStageIsCaseInsensitive(): void
    {
        $areaId = 950;
        $this->seeder->seedElement(95, $areaId, 'DNADesign\\Elemental\\Models\\ElementContent', 1, [
            'Title' => 'Live Element',
        ], 'live');

        $elements = $this->reader->getElementsForArea($areaId, 'LIVE');

        self::assertCount(1, $elements);
        self::assertSame('Live Element', $elements[0]->title);
    }

}

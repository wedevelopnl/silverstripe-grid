<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\Service\LegacyElementReader;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

/**
 * Direct surface tests for the element-hydration half of the split reader.
 *
 * Focuses on the batch-prefetch hydration path: that a multi-element area is
 * hydrated into the same DTO shape the single-ID accessors produce, regardless
 * of element count, and that the locale overlay paths still hydrate correctly.
 */
#[CoversClass(LegacyElementReader::class)]
final class LegacyElementReaderTest extends SapphireTest
{
    protected $usesTransactions = false;

    private const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    private const string ROW_CLASS = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

    private LegacyTableSeeder $seeder;

    private LegacyElementReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->truncateTables();
        $this->reader = new LegacyElementReader();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeFieldLocalisedTables();
        $this->seeder->removeLocaleIdColumn();
        $this->seeder->dropTables();

        parent::tearDown();
    }

    public function testGetElementsForAreaHydratesFullShape(): void
    {
        $areaId = 1000;

        $this->seeder->seedElement(10, $areaId, self::ROW_CLASS, 1, ['Title' => 'My Row']);
        $this->seeder->seedRow(10, isFluid: true, customSectionClass: 'wide-section');

        $this->seeder->seedElement(11, $areaId, self::CONTENT_CLASS, 2, [
            'Title' => 'Hello',
            'ShowTitle' => 1,
            'TitleTag' => 'h2',
            'TitleClass' => 'text-lg',
            'ExtraClass' => 'highlight',
            'SizeMD' => 8,
            'SizeLG' => 6,
            'OffsetMD' => 2,
            'VisibilityXS' => 'hidden',
            'VisibilityMD' => 'visible',
        ]);
        $this->seeder->seedContentMedia(11, ['MediaType' => 'image', 'MediaRatio' => '16x9']);

        $elements = $this->reader->getElementsForArea($areaId, 'draft');

        self::assertCount(2, $elements);

        $row = $elements[0];
        self::assertSame(10, $row->id);
        self::assertSame(self::ROW_CLASS, $row->className);
        self::assertSame('My Row', $row->title);
        self::assertTrue($row->isRow);
        self::assertNotNull($row->rowData);
        self::assertSame('wide-section', $row->rowData->customSectionClass);
        // A row delimiter has no ElementContent companion, so media stays null.
        self::assertNull($row->mediaData);

        $content = $elements[1];
        self::assertSame(11, $content->id);
        self::assertSame(self::CONTENT_CLASS, $content->className);
        self::assertSame('Hello', $content->title);
        self::assertTrue($content->showTitle);
        self::assertSame('h2', $content->titleTag);
        self::assertSame('text-lg', $content->titleClass);
        self::assertSame(2, $content->sort);
        self::assertSame('highlight', $content->extraClass);
        self::assertFalse($content->isRow);
        self::assertSame(8, $content->sizeFields['MD']);
        self::assertSame(6, $content->sizeFields['LG']);
        self::assertSame(0, $content->sizeFields['XS']);
        self::assertSame(2, $content->offsetFields['MD']);
        self::assertSame('hidden', $content->visibilityFields['XS']);
        self::assertSame('visible', $content->visibilityFields['MD']);
        self::assertNull($content->visibilityFields['LG']);
        self::assertNull($content->rowData);
        self::assertNotNull($content->mediaData);
        self::assertSame('image', $content->mediaData->fields['MediaType']);
        self::assertSame('16x9', $content->mediaData->fields['MediaRatio']);
    }

    /**
     * The batch path must produce byte-identical DTOs to the single-ID accessors,
     * for any element count. We seed a mixed area of N content + M rows, then
     * assert every batch-hydrated element's companion DTOs equal what the
     * per-element accessors (getRowData / getContentMediaData) return for the
     * same ID. Output identity is the load-bearing invariant; query-count
     * reduction is the intent behind it.
     *
     * NOTE: LegacyTableSeeder exposes no query counter, so the query-count
     * reduction itself cannot be cheaply asserted here — only output identity is.
     */
    public function testBatchHydrationMatchesPerElementAccessors(): void
    {
        $areaId = 1100;

        // 3 content elements (with media) interleaved with 2 row delimiters.
        $this->seeder->seedElement(20, $areaId, self::ROW_CLASS, 1, ['Title' => 'Row A']);
        $this->seeder->seedRow(20, isFluid: false, customSectionClass: 'section-a');

        $this->seeder->seedElement(21, $areaId, self::CONTENT_CLASS, 2, ['Title' => 'Content 1', 'SizeMD' => 4]);
        $this->seeder->seedContentMedia(21, ['MediaType' => 'image', 'ContentColumns' => '4']);

        $this->seeder->seedElement(22, $areaId, self::CONTENT_CLASS, 3, ['Title' => 'Content 2', 'SizeMD' => 8]);
        $this->seeder->seedContentMedia(22, ['MediaType' => 'video', 'MediaRatio' => '4x3']);

        $this->seeder->seedElement(23, $areaId, self::ROW_CLASS, 4, ['Title' => 'Row B']);
        $this->seeder->seedRow(23, isFluid: true, customSectionClass: 'section-b');

        $this->seeder->seedElement(24, $areaId, self::CONTENT_CLASS, 5, ['Title' => 'Content 3']);
        $this->seeder->seedContentMedia(24, ['HTML' => '<p>Three</p>']);

        $elements = $this->reader->getElementsForArea($areaId, 'draft');

        self::assertCount(5, $elements);

        foreach ($elements as $element) {
            $expectedRowData = $element->isRow ? $this->reader->getRowData($element->id, 'draft') : null;
            $expectedMediaData = $this->reader->getContentMediaData($element->id, 'draft');

            self::assertEquals(
                $expectedRowData,
                $element->rowData,
                \sprintf('rowData mismatch for element %d', $element->id),
            );
            self::assertEquals(
                $expectedMediaData,
                $element->mediaData,
                \sprintf('mediaData mismatch for element %d', $element->id),
            );
        }

        // Sanity: order preserved, classifications correct.
        self::assertSame([20, 21, 22, 23, 24], array_map(static fn (LegacyElement $e): int => $e->id, $elements));
        self::assertSame([true, false, false, true, false], array_map(static fn (LegacyElement $e): bool => $e->isRow, $elements));
    }

    public function testGetElementsForAreaReturnsEmptyForMissingArea(): void
    {
        self::assertSame([], $this->reader->getElementsForArea(99999, 'draft'));
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

        self::assertSame(['EN'], array_map(static fn (LegacyElement $e): string => $e->title, $en));
        self::assertSame(['NL'], array_map(static fn (LegacyElement $e): string => $e->title, $nl));
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

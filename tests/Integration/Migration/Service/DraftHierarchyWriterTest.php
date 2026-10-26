<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\AfterMigrateHook;
use WeDevelop\Grid\Migration\Service\DraftHierarchyWriter;
use WeDevelop\Grid\Migration\Service\DroppedMediaCollector;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestRowSettingsExtension;
use WeDevelop\Grid\Tests\Integration\Migration\Support\TestRowSettingsMigrationExtension;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementFactory;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\MigrationIdMap;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Direct coverage for the draft-write collaborator extracted from
 * GridMigrationService. The writer takes built MigrationSection DTOs and
 * writes the Section → Row → Column → content hierarchy to DRAFT, recording
 * the legacy→new id/sort mapping in a {@see MigrationIdMap}.
 */
#[CoversClass(DraftHierarchyWriter::class)]
final class DraftHierarchyWriterTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    /** $extra_dataobjects alone does not provision the temp DB — this test writes records. */
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [TestCustomElement::class, TestPage::class];

    protected static $required_extensions = [
        Row::class => [TestRowSettingsExtension::class],
    ];

    private const string ZONE = 'main';

    private const int LEGACY_ID = 4242;

    private DraftHierarchyWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        // The writer assumes its caller (GridMigrationService::run) has suppressed
        // Section/Row auto-scaffolding for the batch; replicate that precondition
        // so a single Section write does not also scaffold an extra Row + Column.
        $this->disableAutoScaffolding();

        $this->writer = new DraftHierarchyWriter(new FieldMapper(), new DroppedMediaCollector());
    }

    public function testWritesFullHierarchyToDraftUnderParentChain(): void
    {
        $pageId = $this->createPage();
        $gridSettings = $this->gridSettings();

        $this->write($pageId, [$this->buildSection($gridSettings)], new MigrationIdMap());

        $sections = Section::get()->filter([
            'ParentID' => $pageId,
            'ParentClass' => Page::class,
            'Zone' => self::ZONE,
        ]);
        self::assertCount(1, $sections, 'Exactly one Section under the page + zone');

        $section = $sections->first();
        self::assertInstanceOf(Section::class, $section);
        self::assertSame('section-extra', $section->ExtraClass);
        self::assertSame(1, (int) $section->Sort);

        $rows = Row::get()->filter(['ParentID' => (int) $section->ID, 'ParentClass' => Section::class]);
        self::assertCount(1, $rows, 'Exactly one Row under the Section');
        $row = $rows->first();
        self::assertInstanceOf(Row::class, $row);
        self::assertSame('Row title', (string) $row->Title);
        self::assertSame('row-extra', $row->ExtraClass);

        $columns = Column::get()->filter(['ParentID' => (int) $row->ID, 'ParentClass' => Row::class]);
        self::assertCount(1, $columns, 'Exactly one Column under the Row');
        $column = $columns->first();
        self::assertInstanceOf(Column::class, $column);

        $elements = ContentElement::get()->filter([
            'ParentID' => (int) $column->ID,
            'ParentClass' => Column::class,
        ]);
        self::assertCount(1, $elements, 'Exactly one content element under the Column');
        $element = $elements->first();
        self::assertInstanceOf(ContentElement::class, $element);
        self::assertSame('Hello', (string) $element->Title);
        self::assertTrue((bool) $element->ShowTitle);
        self::assertSame('h3', (string) $element->TitleTag);
        self::assertSame('el-extra', $element->ExtraClass);
        self::assertSame(1, (int) $element->Sort);
    }

    public function testPopulatesMigrationIdMap(): void
    {
        $pageId = $this->createPage();
        $idMap = new MigrationIdMap();

        $this->write($pageId, [$this->buildSection($this->gridSettings())], $idMap);

        $column = Column::get()->first();
        self::assertInstanceOf(Column::class, $column);
        $element = ContentElement::get()->first();
        self::assertInstanceOf(ContentElement::class, $element);

        self::assertTrue($idMap->hasElement(self::LEGACY_ID));
        self::assertSame((int) $element->ID, $idMap->newElementId(self::LEGACY_ID));
        self::assertSame((int) $column->ID, $idMap->newColumnId(self::LEGACY_ID));
        self::assertSame(1, $idMap->draftSort(self::LEGACY_ID));
    }

    public function testCreateSectionUsesTheExplicitSortOverrideWhenGiven(): void
    {
        $pageId = $this->createPage();
        $migration = new MigrationSection('', self::ZONE, '', 1, []);

        $withOverride = Versioned::withVersionedMode(function () use ($migration, $pageId): Section {
            Versioned::set_stage(Versioned::DRAFT);

            return $this->writer->createSection($migration, $pageId, Page::class, self::ZONE, 7);
        });

        // The DTO's own sort is 1; the explicit override must win (the live-only path
        // relies on it to append past the sections the draft pass already wrote).
        self::assertSame(7, (int) $withOverride->Sort);
    }

    public function testCreateSectionFallsBackToTheDtoSortWhenNoOverrideIsGiven(): void
    {
        $pageId = $this->createPage();
        $migration = new MigrationSection('', self::ZONE, '', 3, []);

        $section = Versioned::withVersionedMode(function () use ($migration, $pageId): Section {
            Versioned::set_stage(Versioned::DRAFT);

            return $this->writer->createSection($migration, $pageId, Page::class, self::ZONE);
        });

        self::assertSame(3, (int) $section->Sort);
    }

    public function testContentElementReceivesHtmlAndMappedMediaFields(): void
    {
        $pageId = $this->createPage();

        $element = $this->legacyContent(
            5003,
            'With media',
            new LegacyMediaData(['HTML' => '<p>Body</p>', 'ContentColumns' => '3']),
        );
        $column = new MigrationColumn($this->gridSettings(), 1, $element);
        $section = new MigrationSection('', self::ZONE, '', 1, [new MigrationRow('', '', 1, [$column])]);

        $this->write($pageId, [$section], new MigrationIdMap());

        $written = ContentElement::get()->first();
        self::assertInstanceOf(ContentElement::class, $written);
        self::assertSame('<p>Body</p>', (string) $written->HTML);
        self::assertSame(3, (int) $written->ContentColumns);
    }

    private function legacyContent(int $id, string $title, ?LegacyMediaData $media = null): LegacyElement
    {
        return LegacyElementFactory::content($id, $id, [
            'className' => 'DNADesign\\Elemental\\Models\\ElementContent',
            'title' => $title,
            'showTitle' => true,
            'titleTag' => 'h3',
            'mediaData' => $media,
        ]);
    }

    public function testColumnReceivesProvidedGridSettings(): void
    {
        $pageId = $this->createPage();
        $gridSettings = $this->gridSettings();

        $this->write($pageId, [$this->buildSection($gridSettings)], new MigrationIdMap());

        $column = Column::get()->first();
        self::assertInstanceOf(Column::class, $column);
        self::assertTrue(
            $gridSettings->equals($column->getGridSettings()),
            'Column GridSettings should equal the MigrationColumn input',
        );
    }

    public function testUpdateElementFieldMappingHookFires(): void
    {
        DraftHierarchyWriter::add_extension(TestMigrationExtension::class);

        try {
            // Fresh writer so the extension is wired onto this instance.
            $writer = new DraftHierarchyWriter(new FieldMapper(), new DroppedMediaCollector());
            $pageId = $this->createPage();
            $idMap = new MigrationIdMap();

            Versioned::withVersionedMode(function () use ($writer, $pageId, $idMap): void {
                Versioned::set_stage(Versioned::DRAFT);
                $writer->writeDraftHierarchy(
                    $pageId,
                    Page::class,
                    self::ZONE,
                    [$this->buildSection($this->gridSettings())],
                    $idMap,
                    new AfterMigrateHook($pageId, 0, null, []),
                );
            });

            $element = ContentElement::get()->first();
            self::assertInstanceOf(ContentElement::class, $element);
            self::assertSame(
                'hook-applied',
                (string) $element->Style,
                'updateElementFieldMapping hook should mutate the element via DraftHierarchyWriter',
            );
        } finally {
            DraftHierarchyWriter::remove_extension(TestMigrationExtension::class);
        }
    }

    public function testRowAndSectionMappingHooksReceiveTheLegacyRowBeforeTheWrite(): void
    {
        $legacyRow = $this->legacyRow(6001, ['BackgroundColour' => 'navy', 'IsCardRow' => 1]);
        $row = new MigrationRow('', '', 1, [$this->column()], $legacyRow);

        $this->writeWithRowMapping([new MigrationSection('', self::ZONE, '', 1, [$row], $legacyRow)], new MigrationIdMap());

        self::assertSame('navy', $this->firstRow()->getField('BackgroundColour'));
        self::assertSame('card', (string) $this->firstSection()->Style);
    }

    public function testNoRowOrSectionHookFiresWithoutALegacyRow(): void
    {
        // The hooks type their legacy row as non-nullable, so firing either one
        // here would throw a TypeError instead of writing the hierarchy.
        $row = new MigrationRow('Implicit', '', 1, [$this->column()]);

        $this->writeWithRowMapping([new MigrationSection('', self::ZONE, '', 1, [$row])], new MigrationIdMap());

        self::assertSame('Implicit', (string) $this->firstRow()->Title);
        self::assertSame('', (string) $this->firstRow()->getField('BackgroundColour'));
        self::assertSame('', (string) $this->firstSection()->Style);
    }

    public function testRecordsEachLegacyRowAgainstTheRowAndSectionItWasWrittenTo(): void
    {
        // The single-section layout: the Section takes its fields from the first
        // row only, so only that row is recorded against it.
        $first = $this->legacyRow(6001);
        $second = $this->legacyRow(6002);
        $rows = [
            new MigrationRow('', '', 1, [$this->column(5001)], $first),
            new MigrationRow('', '', 2, [$this->column(5002)], $second),
            new MigrationRow('', '', 3, [$this->column(5003)]),
        ];
        $idMap = new MigrationIdMap();

        $this->write($this->createPage(), [new MigrationSection('', self::ZONE, '', 1, $rows, $first)], $idMap);

        $rowIds = Row::get()->sort('Sort')->column('ID');
        self::assertSame([(int) $rowIds[0], (int) $rowIds[1]], [$idMap->newRowId(6001), $idMap->newRowId(6002)]);
        self::assertSame((int) $this->firstSection()->ID, $idMap->newSectionId(6001));
        self::assertNull($idMap->newSectionId(6002));
    }

    private function createPage(): int
    {
        $page = Page::create();
        $page->Title = 'Writer Target';
        $page->write();

        return (int) $page->ID;
    }

    private function gridSettings(): GridSettings
    {
        return new GridSettings(new ViewportConfig(8, 0, true));
    }

    /**
     * Build a one-section / one-row / one-column / one-element DTO tree whose
     * single content element maps to {@see ContentElement} via the FieldMapper.
     */
    private function buildSection(GridSettings $gridSettings): MigrationSection
    {
        $element = LegacyElementFactory::content(self::LEGACY_ID, 5, [
            'className' => 'DNADesign\\Elemental\\Models\\ElementContent',
            'title' => 'Hello',
            'showTitle' => true,
            'titleTag' => 'h3',
            'titleClass' => 'title-class',
            'extraClass' => 'el-extra',
        ]);

        $column = new MigrationColumn($gridSettings, 1, $element);
        $row = new MigrationRow('Row title', 'row-extra', 1, [$column]);

        return new MigrationSection('', self::ZONE, 'section-extra', 1, [$row]);
    }

    /**
     * @param array<string, mixed> $extraData
     */
    private function legacyRow(int $id, array $extraData = []): LegacyElement
    {
        return LegacyElementFactory::row($id, $id, null, ['extraData' => $extraData]);
    }

    private function column(int $legacyId = self::LEGACY_ID): MigrationColumn
    {
        return new MigrationColumn($this->gridSettings(), 1, $this->legacyContent($legacyId, "Block {$legacyId}"));
    }

    private function firstRow(): Row
    {
        $row = Row::get()->sort('ID')->first();
        self::assertInstanceOf(Row::class, $row);

        return $row;
    }

    private function firstSection(): Section
    {
        $section = Section::get()->sort('ID')->first();
        self::assertInstanceOf(Section::class, $section);

        return $section;
    }

    /**
     * @param list<MigrationSection> $sections
     */
    private function writeWithRowMapping(array $sections, MigrationIdMap $idMap): void
    {
        DraftHierarchyWriter::add_extension(TestRowSettingsMigrationExtension::class);

        try {
            // Fresh writer so the extension is wired onto this instance.
            $this->writer = new DraftHierarchyWriter(new FieldMapper(), new DroppedMediaCollector());
            $this->write($this->createPage(), $sections, $idMap);
        } finally {
            DraftHierarchyWriter::remove_extension(TestRowSettingsMigrationExtension::class);
        }
    }

    /**
     * @param list<MigrationSection> $sections
     */
    private function write(int $pageId, array $sections, MigrationIdMap $idMap): void
    {
        Versioned::withVersionedMode(function () use ($pageId, $sections, $idMap): void {
            Versioned::set_stage(Versioned::DRAFT);
            $this->writer->writeDraftHierarchy($pageId, Page::class, self::ZONE, $sections, $idMap, new AfterMigrateHook($pageId, 0, null, []));
        });
    }
}

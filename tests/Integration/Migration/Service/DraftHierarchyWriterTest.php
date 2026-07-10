<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Page;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\DraftHierarchyWriter;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
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
    /** $extra_dataobjects alone does not provision the temp DB — this test writes records. */
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [TestCustomElement::class, TestPage::class];

    // The writer is only ever invoked from GridMigrationService::run(), which
    // manages its own transactions; mirror that by disabling SapphireTest's
    // per-test transaction wrapping.
    protected $usesTransactions = false;

    private const string ZONE = 'main';

    private const int LEGACY_ID = 4242;

    private DraftHierarchyWriter $writer;

    private bool $sectionAutoScaffold;

    private bool $rowAutoScaffold;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        // The writer assumes its caller (GridMigrationService::run) has suppressed
        // Section/Row auto-scaffolding for the batch; replicate that precondition
        // so a single Section write does not also scaffold an extra Row + Column.
        $this->sectionAutoScaffold = (bool) Section::config()->get('auto_scaffold');
        $this->rowAutoScaffold = (bool) Row::config()->get('auto_scaffold');
        Section::config()->set('auto_scaffold', false);
        Row::config()->set('auto_scaffold', false);

        // No DDL/transaction rollback runs (usesTransactions = false), so purge any
        // grid records leaked from a previous test before each method.
        $this->cleanGridTables();

        $this->writer = new DraftHierarchyWriter(new FieldMapper());
    }

    protected function tearDown(): void
    {
        Section::config()->set('auto_scaffold', $this->sectionAutoScaffold);
        Row::config()->set('auto_scaffold', $this->rowAutoScaffold);

        parent::tearDown();
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
            $writer = new DraftHierarchyWriter(new FieldMapper());
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
        $element = new LegacyElement(
            id: self::LEGACY_ID,
            className: 'DNADesign\\Elemental\\Models\\ElementContent',
            title: 'Hello',
            showTitle: true,
            titleTag: 'h3',
            titleClass: 'title-class',
            sort: 5,
            extraClass: 'el-extra',
            isRow: false,
            sizeFields: [],
            offsetFields: [],
            visibilityFields: [],
        );

        $column = new MigrationColumn($gridSettings, 1, [$element]);
        $row = new MigrationRow('Row title', 'row-extra', 1, [$column]);

        return new MigrationSection('', self::ZONE, 'section-extra', 1, [$row]);
    }

    /**
     * @param list<MigrationSection> $sections
     */
    private function write(int $pageId, array $sections, MigrationIdMap $idMap): void
    {
        Versioned::withVersionedMode(function () use ($pageId, $sections, $idMap): void {
            Versioned::set_stage(Versioned::DRAFT);
            $this->writer->writeDraftHierarchy($pageId, Page::class, self::ZONE, $sections, $idMap);
        });
    }

    private function cleanGridTables(): void
    {
        $tables = [
            'WeDevelop_Grid_Test_CustomElement', 'WeDevelop_Grid_Test_CustomElement_Live',
            'WeDevelop_Grid_ContentElement', 'WeDevelop_Grid_ContentElement_Live',
            'WeDevelop_Grid_Column', 'WeDevelop_Grid_Column_Live',
            'WeDevelop_Grid_Row', 'WeDevelop_Grid_Row_Live',
            'WeDevelop_Grid_Section', 'WeDevelop_Grid_Section_Live',
            'WeDevelop_Grid_GridElement', 'WeDevelop_Grid_GridElement_Live',
            'WeDevelop_Grid_Test_Page', 'WeDevelop_Grid_Test_Page_Live',
        ];

        $allTables = DB::table_list();

        foreach ($tables as $table) {
            if (\array_key_exists(\strtolower($table), $allTables)) {
                DB::query("DELETE FROM \"{$table}\"");
            }
        }
    }
}

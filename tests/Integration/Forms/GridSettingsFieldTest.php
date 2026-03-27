<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Forms\GridSettingsField;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettingsField::class)]
final class GridSettingsFieldTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private GridAdapterInterface $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        $this->adapter = Injector::inst()->get(GridAdapterInterface::class);
    }

    private function createField(string $name = 'GridSettings'): GridSettingsField
    {
        return new GridSettingsField($name, $this->adapter);
    }

    // ── Constructor ─────────────────────────────────────────────

    public function testConstructorInitializesWithDefaultSettings(): void
    {
        $field = $this->createField();
        $viewportData = $field->getViewportData();

        // Coalesce fallback title
        self::assertSame('Grid Settings', $field->Title());

        $md = $viewportData->find('Key', 'md');
        self::assertNotNull($md);
        self::assertSame(12, $md->Width);
        self::assertSame(0, $md->Offset);
        self::assertTrue($md->Visible);
    }

    // ── setValue with GridSettings VO ────────────────────────────

    public function testSetValueWithGridSettingsVO(): void
    {
        $field = $this->createField();
        $settings = new GridSettings(new ViewportConfig(6, 2, true));
        $field->setValue($settings);

        $md = $field->getViewportData()->find('Key', 'md');
        self::assertNotNull($md);
        self::assertSame(6, $md->Width);
        self::assertSame(2, $md->Offset);
    }

    // ── setValue with form array ─────────────────────────────────

    public function testSetValueWithFormArrayComplete(): void
    {
        $field = $this->createField();
        $field->setValue([
            'md' => ['width' => '6', 'offset' => '2', 'visible' => '1'],
            'lg' => ['width' => '4', 'offset' => '1', 'override' => '1', 'visible' => '1'],
        ]);

        $viewportData = $field->getViewportData();

        $md = $viewportData->find('Key', 'md');
        self::assertNotNull($md);
        self::assertSame(6, $md->Width);
        self::assertSame(2, $md->Offset);
        self::assertTrue($md->Visible);

        $lg = $viewportData->find('Key', 'lg');
        self::assertNotNull($lg);
        self::assertSame(4, $lg->Width);
        self::assertSame(1, $lg->Offset);
        self::assertTrue($lg->Override);
    }

    public function testSetValueWithFormArrayMissingFields(): void
    {
        $field = $this->createField();
        $field->setValue([
            'md' => [],
        ]);

        $md = $field->getViewportData()->find('Key', 'md');
        self::assertNotNull($md);
        // Missing width defaults to columnCount (12)
        self::assertSame(12, $md->Width);
        // Missing offset defaults to 0
        self::assertSame(0, $md->Offset);
        // Missing visible checkbox means false
        self::assertFalse($md->Visible);
    }

    public function testSetValueWithFormArrayNoOverrides(): void
    {
        $field = $this->createField();
        $field->setValue([
            'md' => ['width' => '8', 'offset' => '0', 'visible' => '1'],
        ]);

        $viewportData = $field->getViewportData();

        // Non-default viewports should not have overrides
        $xs = $viewportData->find('Key', 'xs');
        self::assertNotNull($xs);
        self::assertFalse($xs->Override);

        $lg = $viewportData->find('Key', 'lg');
        self::assertNotNull($lg);
        self::assertFalse($lg->Override);
    }

    public function testSetValueWithFormArrayOverrideWithoutCheckbox(): void
    {
        $field = $this->createField();
        $field->setValue([
            'md' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
            'lg' => ['width' => '4', 'offset' => '1'],
        ]);

        // No 'override' key in lg data means the override is ignored
        $lg = $field->getViewportData()->find('Key', 'lg');
        self::assertNotNull($lg);
        self::assertFalse($lg->Override);
    }

    // ── getViewportData ─────────────────────────────────────────

    public function testGetViewportDataReturnsAllViewports(): void
    {
        $field = $this->createField();

        // Bootstrap adapter has 6 viewports: xs, sm, md, lg, xl, xxl
        self::assertCount(6, $field->getViewportData());
    }

    public function testGetViewportDataMarksDefaultViewport(): void
    {
        $field = $this->createField();
        $field->setValue(new GridSettings(new ViewportConfig(8, 1, true)));
        $viewportData = $field->getViewportData();

        $md = $viewportData->find('Key', 'md');
        self::assertNotNull($md);
        self::assertTrue($md->IsDefault);
        self::assertSame(8, $md->Width);
        self::assertSame(1, $md->Offset);
        self::assertTrue($md->Visible);
        self::assertSame('Medium', $md->Label);
        self::assertSame('GridSettings', $md->FieldName);

        $xs = $viewportData->find('Key', 'xs');
        self::assertNotNull($xs);
        self::assertFalse($xs->IsDefault);
    }

    public function testGetViewportDataIncludesWidthAndOffsetOptions(): void
    {
        $field = $this->createField();
        $viewportData = $field->getViewportData();

        $md = $viewportData->find('Key', 'md');
        self::assertNotNull($md);

        // Width options: 1 through 12
        self::assertCount(12, $md->WidthOptions);
        $firstWidth = $md->WidthOptions->first();
        self::assertSame(1, $firstWidth->Value);
        self::assertSame('1/12', $firstWidth->Label);
        $lastWidth = $md->WidthOptions->last();
        self::assertSame(12, $lastWidth->Value);
        self::assertSame('12/12', $lastWidth->Label);

        // Offset options: 0 through 11
        self::assertCount(12, $md->OffsetOptions);
        $firstOffset = $md->OffsetOptions->first();
        self::assertSame(0, $firstOffset->Value);
        self::assertSame('0', $firstOffset->Label);
        $lastOffset = $md->OffsetOptions->last();
        self::assertSame(11, $lastOffset->Value);
        self::assertSame('11', $lastOffset->Label);
    }

    // ── saveInto ────────────────────────────────────────────────

    public function testSaveIntoWritesGridSettingsToRecord(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $field = $this->createField();
        $field->setValue(new GridSettings(new ViewportConfig(8, 1, true)));
        $field->saveInto($column);
        $column->write();

        $reloaded = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $reloaded);

        $settings = $reloaded->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertSame(1, $settings->default->offset);
        self::assertTrue($settings->default->visible);
    }

    // ── Readonly transformation ─────────────────────────────────

    public function testPerformReadonlyTransformationReturnsReadonlyField(): void
    {
        $field = $this->createField();

        self::assertInstanceOf(ReadonlyField::class, $field->performReadonlyTransformation());
    }

    public function testReadonlySummaryFormatsDefaultViewport(): void
    {
        $field = $this->createField();
        $field->setValue(new GridSettings(new ViewportConfig(6, 0, true)));

        $readonly = $field->performReadonlyTransformation();

        // visible=true produces no suffix — kills the ternary mutant on the visibility guard
        self::assertSame('md: 6/12+0', $readonly->dataValue());
    }

    public function testReadonlySummaryIncludesOverrides(): void
    {
        $field = $this->createField();
        $field->setValue(new GridSettings(
            new ViewportConfig(6, 0, true),
            ['lg' => new ViewportConfig(4, 2, false)],
        ));

        $readonly = $field->performReadonlyTransformation();

        self::assertStringContainsString('lg: 4/12+2 (hidden)', $readonly->dataValue());
    }
}

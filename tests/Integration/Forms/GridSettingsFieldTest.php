<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Adapter\TailwindAdapter;
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

        // Wire the field to a concrete instance of the default preset (Tailwind)
        // rather than resolving GridAdapterInterface from the container, so the
        // viewport assertions below are deterministic and independent of whatever
        // adapter the container happens to be configured with. Tailwind's topology
        // (5 viewports, default `sm`, 12 columns) drives the expected values.
        $this->adapter = new TailwindAdapter();
    }

    private function createField(string $name = 'GridSettings'): GridSettingsField
    {
        return new GridSettingsField($name, $this->adapter);
    }

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

    public function testConstructorHonoursAnExplicitTitle(): void
    {
        // Pins the operand order of `$title ?? _t(...)`: _t() never returns null, so
        // flipping the coalesce would silently discard the caller's title.
        $field = new GridSettingsField('Layout', $this->adapter, 'Column layout');

        self::assertSame('Column layout', $field->Title());
    }

    public function testConstructorFallsBackToTranslatedDefaultTitle(): void
    {
        // Field name 'Layout' does NOT name_to_label to 'Grid Settings', so a
        // 'Grid Settings' title can only come from the explicit `_t` fallback.
        // Pins the `$title ?? _t(...)` default: dropping the fallback would let
        // FormField derive the title from the name ('Layout') instead.
        $field = new GridSettingsField('Layout', $this->adapter);

        self::assertSame('Grid Settings', $field->Title());
    }

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

    public function testSetValueWithFormArrayComplete(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '2', 'visible' => '1'],
            'lg' => ['width' => '4', 'offset' => '1', 'override' => '1', 'visible' => '1'],
        ]);

        $viewportData = $field->getViewportData();

        $sm = $viewportData->find('Key', 'sm');
        self::assertNotNull($sm);
        self::assertSame(6, $sm->Width);
        self::assertSame(2, $sm->Offset);
        self::assertTrue($sm->Visible);

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
            'sm' => [],
        ]);

        $sm = $field->getViewportData()->find('Key', 'sm');
        self::assertNotNull($sm);
        // Missing width defaults to columnCount (12)
        self::assertSame(12, $sm->Width);
        // Missing offset defaults to 0
        self::assertSame(0, $sm->Offset);
        // Missing visible checkbox means false
        self::assertFalse($sm->Visible);
    }

    public function testSetValueWithFormArrayNoOverrides(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '8', 'offset' => '0', 'visible' => '1'],
        ]);

        $viewportData = $field->getViewportData();

        // Non-default viewports should not have overrides
        $md = $viewportData->find('Key', 'md');
        self::assertNotNull($md);
        self::assertFalse($md->Override);

        $lg = $viewportData->find('Key', 'lg');
        self::assertNotNull($lg);
        self::assertFalse($lg->Override);
    }

    public function testSetValueWithFormArrayOverrideWithoutCheckbox(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '6', 'offset' => '0', 'visible' => '1'],
            'lg' => ['width' => '4', 'offset' => '1'],
        ]);

        // No 'override' key in lg data means the override is ignored
        $lg = $field->getViewportData()->find('Key', 'lg');
        self::assertNotNull($lg);
        self::assertFalse($lg->Override);
    }

    public function testEarlyViewportWithoutOverrideDoesNotDropLaterOverride(): void
    {
        // 'md' is an early non-default viewport with no override flag; 'lg' is a
        // later viewport that DOES carry one. The loop must `continue` past md
        // and still register lg's override. A `break` mutant would abort on md
        // and silently drop the lg override. ('sm' is the default viewport.)
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '12', 'offset' => '0', 'visible' => '1'],
            'md' => ['width' => '6', 'offset' => '0'],
            'lg' => ['width' => '4', 'offset' => '2', 'override' => '1', 'visible' => '1'],
        ]);

        $viewportData = $field->getViewportData();

        $md = $viewportData->find('Key', 'md');
        self::assertNotNull($md);
        self::assertFalse($md->Override, 'md carries no override flag');

        $lg = $viewportData->find('Key', 'lg');
        self::assertNotNull($lg);
        self::assertTrue($lg->Override, 'lg override must survive an earlier override-less viewport');
        self::assertSame(4, $lg->Width);
        self::assertSame(2, $lg->Offset);
    }

    public function testGetViewportDataReturnsAllViewports(): void
    {
        $field = $this->createField();

        // Tailwind adapter has 5 viewports: sm, md, lg, xl, 2xl
        self::assertCount(5, $field->getViewportData());
    }

    public function testGetViewportDataMarksDefaultViewport(): void
    {
        $field = $this->createField();
        $field->setValue(new GridSettings(new ViewportConfig(8, 1, true)));
        $viewportData = $field->getViewportData();

        $sm = $viewportData->find('Key', 'sm');
        self::assertNotNull($sm);
        self::assertTrue($sm->IsDefault);
        self::assertSame(8, $sm->Width);
        self::assertSame(1, $sm->Offset);
        self::assertTrue($sm->Visible);
        self::assertSame('Small', $sm->Label);
        self::assertSame('GridSettings', $sm->FieldName);

        $lg = $viewportData->find('Key', 'lg');
        self::assertNotNull($lg);
        self::assertFalse($lg->IsDefault);
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

    public function testSaveIntoWritesGridSettingsToRecord(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
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
        self::assertSame('sm: 6/12+0', $readonly->dataValue());
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

    /**
     * `Selected` flag on width/offset option ArrayData: pins the `$i === $selected`
     * identical check at lines 180 and 200 — mutating to `!==` inverts the flag
     * so the selected value becomes unselected and every other value becomes selected.
     */
    public function testWidthOptionsMarkMatchingValueAsSelected(): void
    {
        $field = $this->createField();
        $field->setValue(new GridSettings(new ViewportConfig(7, 3, true)));

        $viewportData = $field->getViewportData();
        $md = $viewportData->find('Key', 'md');
        self::assertNotNull($md);

        $selectedWidths = [];
        foreach ($md->WidthOptions as $opt) {
            if ($opt->Selected) {
                $selectedWidths[] = $opt->Value;
            }
        }
        self::assertSame([7], $selectedWidths, 'Exactly one width option (=7) must be flagged Selected');

        $selectedOffsets = [];
        foreach ($md->OffsetOptions as $opt) {
            if ($opt->Selected) {
                $selectedOffsets[] = $opt->Value;
            }
        }
        self::assertSame([3], $selectedOffsets, 'Exactly one offset option (=3) must be flagged Selected');
    }

    /**
     * Pins `$parsedOffset = is_numeric($offset) ? (int) $offset : 0;` at line 153.
     * When a form override omits offset, it must default to 0, not 1 or -1.
     */
    public function testOverrideWithMissingOffsetDefaultsToZero(): void
    {
        $field = $this->createField();
        $field->setValue([
            'sm' => ['width' => '12', 'offset' => '0', 'visible' => '1'],
            // Only width + override flag; offset field absent
            'lg' => ['width' => '6', 'override' => '1', 'visible' => '1'],
        ]);

        $viewportData = $field->getViewportData();
        $lg = $viewportData->find('Key', 'lg');
        self::assertNotNull($lg);
        self::assertSame(0, $lg->Offset);
    }

    /**
     * Tampered POST input with out-of-range lower-bound values must be clamped
     * server-side, not passed through raw. The normal UI never produces width=0
     * or negative offsets, but a crafted POST can — and width=0 is the sentinel
     * in ColumnClassResolver that yields wrong output.
     *
     * Default viewport: width "0" → clamped to 1; offset "-3" → clamped to 0.
     * Override viewport: width "-5" → clamped to 1; offset "-1" → clamped to 0.
     */
    public function testNormalizeFormDataClampsTamperedWidthAndOffset(): void
    {
        $field = $this->createField();
        $field->setValue([
            // Tampered default: width=0, negative offset
            'sm' => ['width' => '0', 'offset' => '-3', 'visible' => '1'],
            // Tampered override: negative width and offset
            'lg' => ['width' => '-5', 'offset' => '-1', 'override' => '1', 'visible' => '1'],
        ]);

        $viewportData = $field->getViewportData();

        $sm = $viewportData->find('Key', 'sm');
        self::assertNotNull($sm);
        self::assertSame(1, $sm->Width, 'width=0 in POST must be clamped to 1 (minimum positive-int)');
        self::assertSame(0, $sm->Offset, 'negative offset in POST must be clamped to 0');

        $lg = $viewportData->find('Key', 'lg');
        self::assertNotNull($lg);
        self::assertTrue($lg->Override);
        self::assertSame(1, $lg->Width, 'negative width in POST override must be clamped to 1');
        self::assertSame(0, $lg->Offset, 'negative offset in POST override must be clamped to 0');
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Fixture\UpdateContainerClassesExtension;
use WeDevelop\Grid\Tests\Integration\Fixture\UpdateRowClassesExtension;
use WeDevelop\Grid\Tests\Integration\Fixture\UpdateTitleClassOptionsExtension;
use WeDevelop\Grid\Tests\Integration\Fixture\UpdateTitleSizeClassExtension;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Tests the grid CSS class accessor methods on each container element.
 *
 * Uses the default Bootstrap adapter (wired via Injector in the test environment).
 * Column tests verify sparse storage with mobile-first cascade class generation.
 */
#[CoversClass(Section::class)]
#[CoversClass(Row::class)]
#[CoversClass(Column::class)]
final class GridClassesTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Required for onAfterWrite scaffolding to run (same as ElementContainerContractTestCase)
        Versioned::set_stage(Versioned::DRAFT);
    }

    // --- Section: getContainerClasses() ---

    public function testContainerClassesForSection(): void
    {
        $section = Section::create();
        $section->write();

        $this->assertSame('container', $section->getContainerClasses());
    }

    public function testFluidContainerClasses(): void
    {
        Config::modify()->set(Section::class, 'fluid_container', true);

        $section = Section::create();
        $section->write();

        $this->assertSame('container-fluid', $section->getContainerClasses());
    }

    // --- Row: getRowClasses() ---

    public function testRowClassesForRow(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $this->assertSame('row', $row->getRowClasses());
    }

    // --- Section: getContainerClasses() with Tailwind adapter ---

    public function testContainerClassesWithTailwindAdapter(): void
    {
        $section = Section::create();
        $section->write();
        $section->gridAdapter = new TailwindAdapter();

        $this->assertSame('container mx-auto', $section->getContainerClasses());
    }

    public function testFluidContainerClassesWithTailwindAdapter(): void
    {
        Config::modify()->set(Section::class, 'fluid_container', true);

        $section = Section::create();
        $section->write();
        $section->gridAdapter = new TailwindAdapter();

        $this->assertSame('w-full', $section->getContainerClasses());
    }

    // --- Section: getContainerClasses() with Bulma adapter ---

    public function testContainerClassesWithBulmaAdapter(): void
    {
        $section = Section::create();
        $section->write();
        $section->gridAdapter = new BulmaAdapter();

        $this->assertSame('container', $section->getContainerClasses());
    }

    public function testFluidContainerClassesWithBulmaAdapter(): void
    {
        Config::modify()->set(Section::class, 'fluid_container', true);

        $section = Section::create();
        $section->write();
        $section->gridAdapter = new BulmaAdapter();

        $this->assertSame('container is-fluid', $section->getContainerClasses());
    }

    // --- Row: getRowClasses() with Tailwind adapter ---

    public function testRowClassesWithTailwindAdapter(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $row->gridAdapter = new TailwindAdapter();

        $this->assertSame('grid grid-cols-12', $row->getRowClasses());
    }

    // --- Row: getRowClasses() with Bulma adapter ---

    public function testRowClassesWithBulmaAdapter(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $row->gridAdapter = new BulmaAdapter();

        $this->assertSame('columns is-multiline', $row->getRowClasses());
    }

    // --- Column: getColumnClasses() with sparse settings and mobile-first cascade ---

    public function testEmptySettingsProducesOnlyBaseWidthClass(): void
    {
        $column = Column::create();
        $column->write();

        // Empty sparse settings → base viewport full-width only
        $this->assertSame('col-12', $column->getColumnClasses());
    }

    public function testWidthChangeEmitsBaseAndBreakpoint(): void
    {
        $column = Column::create();
        // default=6 for md onward, overrides xs/sm to 12
        $column->setGridSettings(new GridSettings(
            new ViewportConfig(6, 0, true),
            [
                'xs' => new ViewportConfig(12, 0, true),
                'sm' => new ViewportConfig(12, 0, true),
            ],
        ));
        $column->write();

        $classes = $column->getColumnClasses();

        // Base viewport (xs) emits col-12, md changes to 6
        $this->assertSame('col-12 col-md-6', $classes);
    }

    public function testUnchangedWidthNotReEmitted(): void
    {
        $column = Column::create();
        $column->setGridSettings(new GridSettings(
            new ViewportConfig(6, 0, true),
            [
                'xs' => new ViewportConfig(12, 0, true),
                'sm' => new ViewportConfig(12, 0, true),
            ],
        ));
        $column->write();

        $classes = $column->getColumnClasses();

        // lg, xl, xxl share default=6, no extra classes emitted
        $this->assertStringNotContainsString('col-lg', $classes);
        $this->assertStringNotContainsString('col-xl', $classes);
    }

    public function testMultipleWidthChangesEmitAtEachBreakpoint(): void
    {
        $column = Column::create();
        // xs/sm=12, md=8, lg onward=6
        $column->setGridSettings(new GridSettings(
            new ViewportConfig(6, 0, true),
            [
                'xs' => new ViewportConfig(12, 0, true),
                'sm' => new ViewportConfig(12, 0, true),
                'md' => new ViewportConfig(8, 0, true),
            ],
        ));
        $column->write();

        $classes = $column->getColumnClasses();

        $this->assertSame('col-12 col-md-8 col-lg-6', $classes);
    }

    public function testOffsetEmittedOnceAndNotReEmitted(): void
    {
        $column = Column::create();
        // xs/sm=12/0, md onward=8/2
        $column->setGridSettings(new GridSettings(
            new ViewportConfig(8, 2, true),
            [
                'xs' => new ViewportConfig(12, 0, true),
                'sm' => new ViewportConfig(12, 0, true),
            ],
        ));
        $column->write();

        $classes = $column->getColumnClasses();

        $this->assertStringContainsString('col-md-8', $classes);
        $this->assertStringContainsString('offset-md-2', $classes);
        // Offset stays 2 for later viewports, not re-emitted
        $this->assertStringNotContainsString('offset-lg', $classes);
    }

    public function testOffsetResetToZeroEmitsExplicitClass(): void
    {
        $column = Column::create();
        // xs/sm=12/0, md=8/2, lg onward=6/0
        $column->setGridSettings(new GridSettings(
            new ViewportConfig(6, 0, true),
            [
                'xs' => new ViewportConfig(12, 0, true),
                'sm' => new ViewportConfig(12, 0, true),
                'md' => new ViewportConfig(8, 2, true),
            ],
        ));
        $column->write();

        $classes = $column->getColumnClasses();

        $this->assertStringContainsString('offset-md-2', $classes);
        // Explicit reset to 0 at lg
        $this->assertStringContainsString('offset-lg-0', $classes);
    }

    public function testHiddenViewportEmitsVisibilityClasses(): void
    {
        $column = Column::create();
        // xs=hidden, sm onward=visible/12
        $column->setGridSettings(new GridSettings(
            new ViewportConfig(12, 0, true),
            [
                'xs' => new ViewportConfig(12, 0, false),
            ],
        ));
        $column->write();

        $classes = $column->getColumnClasses();

        // Bootstrap xs hidden: d-none + d-sm-block
        $this->assertStringContainsString('d-none', $classes);
        $this->assertStringContainsString('d-sm-block', $classes);
    }

    public function testHiddenMidViewportEmitsCorrectPairs(): void
    {
        $column = Column::create();
        // xs/sm=12/visible, md=hidden, lg onward=6/visible
        $column->setGridSettings(new GridSettings(
            new ViewportConfig(6, 0, true),
            [
                'xs' => new ViewportConfig(12, 0, true),
                'sm' => new ViewportConfig(12, 0, true),
                'md' => new ViewportConfig(8, 0, false),
            ],
        ));
        $column->write();

        $classes = $column->getColumnClasses();

        // Base viewport is full-width
        $this->assertStringContainsString('col-12', $classes);
        // md hidden
        $this->assertStringContainsString('d-md-none', $classes);
        $this->assertStringContainsString('d-lg-block', $classes);
        // Restoring at lg emits width class
        $this->assertStringContainsString('col-lg-6', $classes);
    }

    public function testConsecutiveHiddenViewportsDoNotConflict(): void
    {
        $column = Column::create();
        // xs/sm=12/visible, md/lg=hidden, xl onward=4/visible
        $column->setGridSettings(new GridSettings(
            new ViewportConfig(4, 0, true),
            [
                'xs' => new ViewportConfig(12, 0, true),
                'sm' => new ViewportConfig(12, 0, true),
                'md' => new ViewportConfig(6, 0, false),
                'lg' => new ViewportConfig(6, 0, false),
            ],
        ));
        $column->write();

        $classes = $column->getColumnClasses();

        // md triggers hide
        $this->assertStringContainsString('d-md-none', $classes);
        // lg is still hidden, no new hide class but restore happens at xl
        // xl restores visibility
        $this->assertStringContainsString('col-xl-4', $classes);
    }

    public function testZeroOffsetNotEmittedAtBaseViewport(): void
    {
        $column = Column::create();
        $column->write();

        $classes = $column->getColumnClasses();

        $this->assertStringNotContainsString('offset', $classes);
    }

    // --- Extension hooks: getTitleSizeClass ---

    public function testUpdateTitleSizeClassExtensionModifiesResult(): void
    {
        Config::modify()->merge(GridElement::class, 'extensions', [UpdateTitleSizeClassExtension::class]);

        $section = Section::create();
        $section->TitleClass = 'original-class';
        $section->write();

        $this->assertSame('custom-title-size', $section->getTitleSizeClass());
    }

    // --- Extension hooks: getContainerClasses ---

    public function testUpdateContainerClassesExtensionAppendsClass(): void
    {
        Config::modify()->merge(Section::class, 'extensions', [UpdateContainerClassesExtension::class]);

        $section = Section::create();
        $section->write();

        $this->assertSame('container test-container-extra', $section->getContainerClasses());
    }

    // --- Extension hooks: getRowClasses ---

    public function testUpdateRowClassesExtensionAppendsClass(): void
    {
        Config::modify()->merge(Row::class, 'extensions', [UpdateRowClassesExtension::class]);

        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $this->assertSame('row test-row-extra', $row->getRowClasses());
    }

    // --- Extension hooks: getCMSFields updateTitleClassOptions ---

    public function testUpdateTitleClassOptionsExtensionAddsOption(): void
    {
        Config::modify()->merge(GridElement::class, 'extensions', [UpdateTitleClassOptionsExtension::class]);
        Config::modify()->set(Section::class, 'enable_custom_title_classes', true);

        $section = Section::create();
        $section->write();

        $fields = $section->getCMSFields();

        $titleClassField = $fields->dataFieldByName('TitleClass');
        $this->assertNotNull($titleClassField, 'TitleClass field should exist when custom classes enabled');

        /** @var \SilverStripe\Forms\DropdownField $titleClassField */
        $source = $titleClassField->getSource();
        $this->assertArrayHasKey('test-injected-class', $source);
        $this->assertSame('Injected by extension', $source['test-injected-class']);
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

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

    // --- Column: getColumnClasses() with sparse settings and mobile-first cascade ---

    public function testEmptySettingsProducesOnlyBaseWidthClass(): void
    {
        $column = Column::create();
        $column->write();

        // Empty sparse settings → base viewport full-width only
        $this->assertSame('col-12', $column->getColumnClasses());
    }

    public function testSparseWidthChangeEmitsBaseAndBreakpoint(): void
    {
        $column = Column::create();
        $column->setGridSettingsData([
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ]);
        $column->write();

        $classes = $column->getColumnClasses();

        // Base viewport inherits default (12), md overrides to 6
        $this->assertSame('col-12 col-md-6', $classes);
    }

    public function testCascadedWidthNotReEmitted(): void
    {
        $column = Column::create();
        $column->setGridSettingsData([
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ]);
        $column->write();

        $classes = $column->getColumnClasses();

        // lg, xl, xxl inherit md=6, no extra classes emitted
        $this->assertStringNotContainsString('col-lg', $classes);
        $this->assertStringNotContainsString('col-xl', $classes);
    }

    public function testMultipleWidthChangesEmitAtEachBreakpoint(): void
    {
        $column = Column::create();
        $column->setGridSettingsData([
            'md' => ['width' => 8, 'offset' => 0, 'visible' => true],
            'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ]);
        $column->write();

        $classes = $column->getColumnClasses();

        $this->assertSame('col-12 col-md-8 col-lg-6', $classes);
    }

    public function testCascadedOffsetEmittedOnceAndInherited(): void
    {
        $column = Column::create();
        $column->setGridSettingsData([
            'md' => ['width' => 8, 'offset' => 2, 'visible' => true],
        ]);
        $column->write();

        $classes = $column->getColumnClasses();

        $this->assertStringContainsString('col-md-8', $classes);
        $this->assertStringContainsString('offset-md-2', $classes);
        // Offset is inherited by later viewports, not re-emitted
        $this->assertStringNotContainsString('offset-lg', $classes);
    }

    public function testOffsetResetToZeroEmitsExplicitClass(): void
    {
        $column = Column::create();
        $column->setGridSettingsData([
            'md' => ['width' => 8, 'offset' => 2, 'visible' => true],
            'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ]);
        $column->write();

        $classes = $column->getColumnClasses();

        $this->assertStringContainsString('offset-md-2', $classes);
        // Explicit reset to 0 at lg
        $this->assertStringContainsString('offset-lg-0', $classes);
    }

    public function testHiddenViewportEmitsVisibilityClasses(): void
    {
        $column = Column::create();
        $column->setGridSettingsData([
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => false],
        ]);
        $column->write();

        $classes = $column->getColumnClasses();

        // Bootstrap xs hidden: d-none + d-sm-block
        $this->assertStringContainsString('d-none', $classes);
        $this->assertStringContainsString('d-sm-block', $classes);
    }

    public function testHiddenMidViewportEmitsCorrectPairs(): void
    {
        $column = Column::create();
        $column->setGridSettingsData([
            'md' => ['width' => 8, 'offset' => 0, 'visible' => false],
            'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ]);
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
        $column->setGridSettingsData([
            'md' => ['width' => 6, 'offset' => 0, 'visible' => false],
            'xl' => ['width' => 4, 'offset' => 0, 'visible' => true],
        ]);
        $column->write();

        $classes = $column->getColumnClasses();

        // md triggers hide, lg inherits hidden so no new classes
        $this->assertStringContainsString('d-md-none', $classes);
        $this->assertStringContainsString('d-lg-block', $classes);
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
}

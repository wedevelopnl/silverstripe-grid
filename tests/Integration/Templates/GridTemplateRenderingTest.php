<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Templates;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Adapter\BootstrapAdapter;
use WeDevelop\Grid\Adapter\BulmaAdapter;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Tests the full template rendering pipeline (element -> holder -> HTML output)
 * for each grid adapter. Uses the element's controller forTemplate() to render.
 */
#[CoversClass(GridElement::class)]
#[CoversClass(Section::class)]
#[CoversClass(Row::class)]
#[CoversClass(Column::class)]
#[CoversClass(ContentElement::class)]
final class GridTemplateRenderingTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    /**
     * Swap the active grid adapter via Injector.
     *
     * @param class-string<GridAdapterInterface> $adapterClass
     */
    private function useAdapter(string $adapterClass): void
    {
        Injector::inst()->registerService(
            new $adapterClass(),
            GridAdapterInterface::class,
        );
    }

    /** Render an element through its holder template. */
    private function render(Section|Row|Column $element): string
    {
        return $element->forTemplate();
    }

    // --- Section rendering ---

    public static function containerClassProvider(): \Generator
    {
        yield 'Bootstrap' => [BootstrapAdapter::class, 'container'];
        yield 'Tailwind' => [TailwindAdapter::class, 'container mx-auto'];
        yield 'Bulma' => [BulmaAdapter::class, 'container'];
    }

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     */
    #[DataProvider('containerClassProvider')]
    public function testSectionRendersContainerClassPerAdapter(string $adapterClass, string $expected): void
    {
        $this->useAdapter($adapterClass);

        $section = Section::create();
        $section->Title = 'Test Section';
        $section->write();

        $html = $this->render($section);

        $this->assertStringContainsString($expected, $html);
    }

    public static function fluidContainerClassProvider(): \Generator
    {
        yield 'Bootstrap' => [BootstrapAdapter::class, 'container-fluid'];
        yield 'Tailwind' => [TailwindAdapter::class, 'w-full'];
        yield 'Bulma' => [BulmaAdapter::class, 'container is-fluid'];
    }

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     */
    #[DataProvider('fluidContainerClassProvider')]
    public function testSectionRendersFluidContainerPerAdapter(string $adapterClass, string $expected): void
    {
        $this->useAdapter($adapterClass);
        Config::modify()->set(Section::class, 'fluid_container', true);

        $section = Section::create();
        $section->Title = 'Fluid Section';
        $section->write();

        $html = $this->render($section);

        $this->assertStringContainsString($expected, $html);
    }

    public function testSectionRendersSectionTag(): void
    {
        $section = Section::create();
        $section->write();

        $html = $this->render($section);

        $this->assertMatchesRegularExpression('/^<section\s/', trim($html));
    }

    public function testSectionTitleRendersWhenShowTitleTrue(): void
    {
        $section = Section::create();
        $section->Title = 'Visible Title';
        $section->ShowTitle = true;
        $section->write();

        $html = $this->render($section);

        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('Visible Title', $html);
    }

    public function testSectionTitleAbsentWhenShowTitleFalse(): void
    {
        $section = Section::create();
        $section->Title = 'Hidden Title';
        $section->ShowTitle = false;
        $section->write();

        $html = $this->render($section);

        $this->assertStringNotContainsString('<h2', $html);
    }

    // --- Row rendering ---

    public static function rowClassProvider(): \Generator
    {
        yield 'Bootstrap' => [BootstrapAdapter::class, 'row'];
        yield 'Tailwind' => [TailwindAdapter::class, 'grid grid-cols-12'];
        yield 'Bulma' => [BulmaAdapter::class, 'columns is-multiline'];
    }

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     */
    #[DataProvider('rowClassProvider')]
    public function testRowRendersRowClassPerAdapter(string $adapterClass, string $expected): void
    {
        $this->useAdapter($adapterClass);

        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $html = $this->render($row);

        $this->assertStringContainsString($expected, $html);
    }

    // --- Column rendering ---

    public static function columnWidthClassProvider(): \Generator
    {
        // Bootstrap: default viewport=md. xs/sm/lg/xl/xxl=12, md=8
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            new GridSettings(
                new ViewportConfig(12, 0, true),
                ['md' => new ViewportConfig(8, 0, true)],
            ),
            'col-md-8',
        ];

        // Tailwind: default viewport=sm. md=8, rest=12
        yield 'Tailwind' => [
            TailwindAdapter::class,
            new GridSettings(
                new ViewportConfig(12, 0, true),
                ['md' => new ViewportConfig(8, 0, true)],
            ),
            'md:col-span-8',
        ];

        // Bulma: default viewport=desktop. desktop=8, rest=12
        yield 'Bulma' => [
            BulmaAdapter::class,
            new GridSettings(
                new ViewportConfig(12, 0, true),
                ['desktop' => new ViewportConfig(8, 0, true)],
            ),
            'is-8-desktop',
        ];
    }

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     */
    #[DataProvider('columnWidthClassProvider')]
    public function testColumnRendersWidthClassesPerAdapter(string $adapterClass, GridSettings $settings, string $expected): void
    {
        $this->useAdapter($adapterClass);

        $column = Column::create();
        $column->setGridSettings($settings);
        $column->write();

        $html = $this->render($column);

        $this->assertStringContainsString($expected, $html);
    }

    public static function columnOffsetClassProvider(): \Generator
    {
        // Bootstrap: xs/sm=6/0, md=6/3, lg/xl/xxl=6/0
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            new GridSettings(
                new ViewportConfig(6, 0, true),
                ['md' => new ViewportConfig(6, 3, true)],
            ),
            'offset-md-3',
        ];

        // Tailwind: sm=6/0, md=6/3, lg/xl/2xl=6/0
        yield 'Tailwind' => [
            TailwindAdapter::class,
            new GridSettings(
                new ViewportConfig(6, 0, true),
                ['md' => new ViewportConfig(6, 3, true)],
            ),
            'md:col-start-4',
        ];

        // Bulma: mobile/tablet=6/0, desktop=6/3, widescreen/fullhd=6/0
        yield 'Bulma' => [
            BulmaAdapter::class,
            new GridSettings(
                new ViewportConfig(6, 0, true),
                ['desktop' => new ViewportConfig(6, 3, true)],
            ),
            'is-offset-3-desktop',
        ];
    }

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     */
    #[DataProvider('columnOffsetClassProvider')]
    public function testColumnRendersOffsetClassesPerAdapter(string $adapterClass, GridSettings $settings, string $expected): void
    {
        $this->useAdapter($adapterClass);

        $column = Column::create();
        $column->setGridSettings($settings);
        $column->write();

        $html = $this->render($column);

        $this->assertStringContainsString($expected, $html);
    }

    public static function columnVisibilityClassProvider(): \Generator
    {
        // Bootstrap: xs=hidden, sm onward=visible/12
        yield 'Bootstrap' => [
            BootstrapAdapter::class,
            new GridSettings(
                new ViewportConfig(12, 0, true),
                ['xs' => new ViewportConfig(12, 0, false)],
            ),
            'd-none',
        ];

        // Tailwind: sm=hidden, md onward=visible/12
        yield 'Tailwind' => [
            TailwindAdapter::class,
            new GridSettings(
                new ViewportConfig(12, 0, true),
                ['sm' => new ViewportConfig(12, 0, false)],
            ),
            'sm:hidden',
        ];

        // Bulma: mobile=hidden, tablet onward=visible/12
        yield 'Bulma' => [
            BulmaAdapter::class,
            new GridSettings(
                new ViewportConfig(12, 0, true),
                ['mobile' => new ViewportConfig(12, 0, false)],
            ),
            'is-hidden-mobile',
        ];
    }

    /**
     * @param class-string<GridAdapterInterface> $adapterClass
     */
    #[DataProvider('columnVisibilityClassProvider')]
    public function testColumnRendersVisibilityClassesForHiddenViewport(string $adapterClass, GridSettings $settings, string $expected): void
    {
        $this->useAdapter($adapterClass);

        $column = Column::create();
        $column->setGridSettings($settings);
        $column->write();

        $html = $this->render($column);

        $this->assertStringContainsString($expected, $html);
    }

    // --- Title tag and class rendering ---

    public function testSectionRendersCustomTitleTag(): void
    {
        $section = Section::create();
        $section->Title = 'Custom Tag';
        $section->TitleTag = 'h3';
        $section->ShowTitle = true;
        $section->write();

        $html = $this->render($section);

        $this->assertStringContainsString('<h3', $html);
        $this->assertStringContainsString('Custom Tag', $html);
        $this->assertStringContainsString('</h3>', $html);
    }

    public function testSectionRendersWithTitleSizeClass(): void
    {
        $section = Section::create();
        $section->Title = 'Styled Title';
        $section->TitleTag = 'h2';
        $section->TitleClass = 'display-1';
        $section->ShowTitle = true;
        $section->write();

        $html = $this->render($section);

        $this->assertStringContainsString('display-1', $html);
    }

    public function testSectionRendersDefaultH2WhenTitleTagEmpty(): void
    {
        $section = Section::create();
        $section->Title = 'Default Tag';
        $section->ShowTitle = true;
        $section->write();

        $html = $this->render($section);

        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('</h2>', $html);
    }

    public function testRowRendersTitleWhenShowTitleTrue(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $row->Title = 'Row Title';
        $row->TitleTag = 'h4';
        $row->ShowTitle = true;
        $row->write();

        $html = $this->render($row);

        $this->assertStringContainsString('<h4', $html);
        $this->assertStringContainsString('Row Title', $html);
    }

    public function testColumnRendersTitleWhenShowTitleTrue(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $column = $row->getChildren()->first();
        $this->assertInstanceOf(Column::class, $column);

        $column->Title = 'Column Title';
        $column->TitleTag = 'h5';
        $column->ShowTitle = true;
        $column->write();

        $html = $this->render($column);

        $this->assertStringContainsString('<h5', $html);
        $this->assertStringContainsString('Column Title', $html);
    }

    public function testContentElementRendersWithCustomTitleTag(): void
    {
        $element = ContentElement::create();
        $element->Title = 'Content Title';
        $element->TitleTag = 'h4';
        $element->ShowTitle = true;
        $element->write();

        $html = $element->forTemplate();

        $this->assertStringContainsString('<h4', $html);
        $this->assertStringContainsString('Content Title', $html);
        $this->assertStringContainsString('</h4>', $html);
    }
}

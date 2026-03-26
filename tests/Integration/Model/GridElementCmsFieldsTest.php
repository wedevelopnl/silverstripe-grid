<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Forms\GridSettingsField;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

#[CoversClass(GridElement::class)]
#[CoversClass(Column::class)]
final class GridElementCmsFieldsTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    // --- CMS fields ---

    public function testCmsFieldsContainTitleSettingsGroup(): void
    {
        $section = Section::create();
        $section->write();

        $fields = $section->getCMSFields();
        $group = $fields->fieldByName('Root.Main.TitleSettings');

        $this->assertInstanceOf(FieldGroup::class, $group);
    }

    public function testTitleTagDropdownHasAllHeadingOptions(): void
    {
        $section = Section::create();
        $section->write();

        $fields = $section->getCMSFields();
        $group = $fields->fieldByName('Root.Main.TitleSettings');
        $this->assertInstanceOf(FieldGroup::class, $group);

        $titleTag = $group->fieldByName('TitleTag');
        $this->assertInstanceOf(DropdownField::class, $titleTag);

        $source = $titleTag->getSource();
        $this->assertSame(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], array_keys($source));
    }

    public function testTitleClassAbsentWhenCustomTitleClassesDisabled(): void
    {
        Config::modify()->set(GridElement::class, 'enable_custom_title_classes', false);

        $section = Section::create();
        $section->write();

        $fields = $section->getCMSFields();
        $group = $fields->fieldByName('Root.Main.TitleSettings');
        $this->assertInstanceOf(FieldGroup::class, $group);

        $this->assertNull($group->fieldByName('TitleClass'));
    }

    public function testTitleClassPresentWhenCustomTitleClassesEnabled(): void
    {
        Config::modify()->set(GridElement::class, 'enable_custom_title_classes', true);

        $section = Section::create();
        $section->write();

        $fields = $section->getCMSFields();
        $group = $fields->fieldByName('Root.Main.TitleSettings');
        $this->assertInstanceOf(FieldGroup::class, $group);

        $titleClass = $group->fieldByName('TitleClass');
        $this->assertInstanceOf(DropdownField::class, $titleClass);
    }

    // --- Accessors ---

    public function testGetTitleSizeClassReturnsStoredValue(): void
    {
        $section = Section::create();
        $section->TitleClass = 'display-1';
        $section->write();

        $this->assertSame('display-1', $section->getTitleSizeClass());
    }

    public function testGetTitleSizeClassReturnsEmptyStringWhenEmpty(): void
    {
        $section = Section::create();
        $section->write();

        $this->assertSame('', $section->getTitleSizeClass());
    }

    // --- DB field defaults ---

    public function testTitleTagDefaultsToH2(): void
    {
        $section = Section::create();
        $section->write();

        $this->assertSame('h2', $section->TitleTag);
    }

    public function testTitleTagAcceptsValidEnumValues(): void
    {
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $section = Section::create();
            $section->TitleTag = $tag;
            $section->write();

            $reloaded = Section::get()->byID($section->ID);
            $this->assertSame($tag, $reloaded->TitleTag);
        }
    }

    // --- Column CMS fields ---

    public function testColumnCmsFieldsIncludesGridSettingsField(): void
    {
        $section = Section::create();
        $section->write();

        $row = $section->getChildren()->first();
        $this->assertInstanceOf(Row::class, $row);

        $column = $row->getChildren()->first();
        $this->assertInstanceOf(Column::class, $column);

        $fields = $column->getCMSFields();
        $gridSettings = $fields->fieldByName('Root.Grid.GridSettings');

        $this->assertInstanceOf(GridSettingsField::class, $gridSettings);
    }
}

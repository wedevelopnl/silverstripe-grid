<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversMethod;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

#[CoversMethod(GridElement::class, 'getType')]
final class GridElementGetTypeTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    public function testGetTypeReturnsSingularNameWhenSet(): void
    {
        $section = Section::create();
        $section->Title = 'Test';
        $section->write();

        $this->assertSame('Section', $section->getType());
    }

    public function testGetTypeFallsBackToShortClassNameWhenSingularNameEmpty(): void
    {
        Config::modify()->set(Section::class, 'singular_name', '');

        $section = Section::create();
        $section->Title = 'Test';
        $section->write();

        // Falls back to ClassInfo::shortName() → 'Section'
        $this->assertSame('Section', $section->getType());
    }

    public function testGetTypeReturnsCustomSingularName(): void
    {
        Config::modify()->set(Section::class, 'singular_name', 'Custom Block');

        $section = Section::create();
        $section->Title = 'Test';
        $section->write();

        $this->assertSame('Custom Block', $section->getType());
    }
}

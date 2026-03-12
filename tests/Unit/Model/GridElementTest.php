<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SilverStripe\Config\Collections\MemoryConfigCollection;
use SilverStripe\Core\Config\ConfigLoader;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\RootElementStub;

/**
 * Unit tests for GridElement pure methods: getAnchor(), getTypeName(),
 * getBlockSchema(), and getType().
 */
#[CoversClass(GridElement::class)]
final class GridElementTest extends TestCase
{
    private MemoryConfigCollection $configCollection;

    protected function setUp(): void
    {
        $this->configCollection = new MemoryConfigCollection();
        ConfigLoader::inst()->pushManifest($this->configCollection);
    }

    protected function tearDown(): void
    {
        ConfigLoader::inst()->popManifest();
    }

    // ─── getAnchor ──────────────────────────────────────────────────

    public function testGetAnchorFormatsWithId(): void
    {
        $element = new RootElementStub();
        $element->ID = 42;

        $this->assertSame('grid-element-42', $element->getAnchor());
    }

    public function testGetAnchorFormatsWithZeroId(): void
    {
        $element = new RootElementStub();
        $element->ID = 0;

        $this->assertSame('grid-element-0', $element->getAnchor());
    }

    // ─── getTypeName ────────────────────────────────────────────────

    public function testGetTypeNameReplacesBackslashes(): void
    {
        $element = new RootElementStub();

        $typeName = $element->getTypeName();

        $this->assertStringNotContainsString('\\', $typeName);
        $this->assertStringContainsString('-', $typeName);
    }

    public function testGetTypeNamePreservesClassStructure(): void
    {
        $element = new RootElementStub();

        $typeName = $element->getTypeName();

        // RootElementStub's class is WeDevelop\Grid\Tests\Unit\Validation\Stub\RootElementStub
        $expected = str_replace('\\', '-', RootElementStub::class);
        $this->assertSame($expected, $typeName);
    }

    // ─── getType ────────────────────────────────────────────────────

    public function testGetTypeReturnsSingularName(): void
    {
        $element = new RootElementStub();
        $this->configCollection->set(RootElementStub::class, 'singular_name', 'Section');

        $this->assertSame('Section', $element->getType());
    }

    // ─── getBlockSchema ─────────────────────────────────────────────

    public function testGetBlockSchemaIncludesRequiredKeys(): void
    {
        $this->configCollection->set(RootElementStub::class, 'singular_name', 'Section');
        $element = new RootElementStub();
        $element->ID = 5;
        $element->Title = 'My Title';

        $schema = $element->getBlockSchema();

        $this->assertArrayHasKey('id', $schema);
        $this->assertArrayHasKey('typeName', $schema);
        $this->assertArrayHasKey('type', $schema);
        $this->assertArrayHasKey('title', $schema);
        $this->assertArrayHasKey('summary', $schema);
    }

    public function testGetBlockSchemaIdMatchesElementId(): void
    {
        $this->configCollection->set(RootElementStub::class, 'singular_name', 'Section');
        $element = new RootElementStub();
        $element->ID = 99;

        $schema = $element->getBlockSchema();

        $this->assertSame(99, $schema['id']);
    }

    public function testGetBlockSchemaTitleMatchesElementTitle(): void
    {
        $this->configCollection->set(RootElementStub::class, 'singular_name', 'Section');
        $element = new RootElementStub();
        $element->Title = 'Test Section';

        $schema = $element->getBlockSchema();

        $this->assertSame('Test Section', $schema['title']);
    }

    public function testGetBlockSchemaSummaryDefaultsToEmpty(): void
    {
        $this->configCollection->set(RootElementStub::class, 'singular_name', 'Section');
        $element = new RootElementStub();

        $schema = $element->getBlockSchema();

        $this->assertSame('', $schema['summary']);
    }

    // ─── getTitleSizeClass ──────────────────────────────────────────

    public function testGetTitleSizeClassReturnsTitleClassField(): void
    {
        $element = new RootElementStub();
        $element->TitleClass = 'display-1';

        $this->assertSame('display-1', $element->getTitleSizeClass());
    }

    public function testGetTitleSizeClassReturnsEmptyWhenNotSet(): void
    {
        $element = new RootElementStub();

        $this->assertSame('', $element->getTitleSizeClass());
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridNodeMapper;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;

#[CoversClass(GridNodeMapper::class)]
final class GridNodeMapperTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/ElementTreeTest.yml';

    /** @var list<class-string> */
    protected static $extra_dataobjects = [
        TestPage::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        TestPage::class => [
            GridPageExtension::class,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    public function onBeforeLoadFixtures(): void
    {
        parent::onBeforeLoadFixtures();
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    private function getMapper(): GridNodeMapper
    {
        /** @var GridNodeMapper $mapper */
        $mapper = Injector::inst()->get(GridNodeMapper::class);

        return $mapper;
    }

    // ---- mapToNode: base fields ----

    public function testMapToNodeIncludesBaseFields(): void
    {
        $section = $this->objFromFixture(Section::class, 'section1');
        $page = $this->objFromFixture(TestPage::class, 'testpage');

        /** @var positive-int $parentId */
        $parentId = (int) $page->ID;

        $node = $this->getMapper()->mapToNode(
            $section,
            $parentId,
            ContainerType::Section,
            null,
            null,
            null,
        );

        $this->assertSame((int) $section->ID, $node->id);
        $this->assertSame($parentId, $node->parentId);
        $this->assertSame('First Section', $node->title);
        $this->assertIsInt($node->version);
        $this->assertGreaterThan(0, $node->version);
        $this->assertNull($node->obsoleteClassName);
        $this->assertTrue($node->canDelete);
        $this->assertTrue($node->canPublish);
        $this->assertTrue($node->canUnpublish);
        $this->assertTrue($node->canCreate);
        $this->assertNotNull($node->editLink);
        $this->assertIsArray($node->statusFlags);
    }

    // ---- mapToNode: blockSchema ----

    public function testMapToNodeBlockSchemaStructure(): void
    {
        $section = $this->objFromFixture(Section::class, 'section1');

        /** @var positive-int $parentId */
        $parentId = (int) $this->objFromFixture(TestPage::class, 'testpage')->ID;

        $node = $this->getMapper()->mapToNode($section, $parentId, null, null, null, null);
        $schema = $node->blockSchema;

        $expectedKeys = ['typeName', 'type', 'title', 'summary', 'label', 'icon'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $schema, "blockSchema missing key: $key");
        }

        $this->assertSame('Section', $schema['label']);
        $this->assertSame('font-icon-block-layout', $schema['icon']);
    }

    public function testMapToNodeIconFallsBackToDefault(): void
    {
        $leaf = $this->objFromFixture(GridElement::class, 'leaf1');

        /** @var positive-int $parentId */
        $parentId = (int) $this->objFromFixture(Column::class, 'col1')->ID;

        $node = $this->getMapper()->mapToNode($leaf, $parentId, null, null, null, null);

        $this->assertSame('font-icon-block-content', $node->blockSchema['icon']);
    }

    // ---- mapToNode: title fallback ----

    public function testMapToNodeUsesUntitledFallback(): void
    {
        $leaf = $this->objFromFixture(GridElement::class, 'leaf1');
        $leaf->Title = '';

        /** @var positive-int $parentId */
        $parentId = (int) $this->objFromFixture(Column::class, 'col1')->ID;

        $node = $this->getMapper()->mapToNode($leaf, $parentId, null, null, null, null);

        $this->assertSame('(untitled)', $node->title);
    }

    // ---- mapToNode: container fields pass-through ----

    public function testMapToNodePassesThroughContainerFields(): void
    {
        $section = $this->objFromFixture(Section::class, 'section1');

        /** @var positive-int $parentId */
        $parentId = (int) $this->objFromFixture(TestPage::class, 'testpage')->ID;

        $allowedTypes = [Row::class => ['label' => 'Row', 'icon' => 'font-icon-columns', 'description' => '']];
        $children = [];

        $node = $this->getMapper()->mapToNode(
            $section,
            $parentId,
            ContainerType::Section,
            $allowedTypes,
            $children,
            null,
        );

        $this->assertSame(ContainerType::Section, $node->containerType);
        $this->assertSame($allowedTypes, $node->allowedTypes);
        $this->assertSame([], $node->children);
        $this->assertNull($node->gridSettings);
    }

    // ---- mapToNode: Column gridSettings ----

    public function testMapToNodeIncludesGridSettingsForColumn(): void
    {
        $column = $this->objFromFixture(Column::class, 'col1');
        $gridSettings = $column->getGridSettings();

        /** @var positive-int $parentId */
        $parentId = (int) $this->objFromFixture(Row::class, 'row1')->ID;

        $node = $this->getMapper()->mapToNode(
            $column,
            $parentId,
            ContainerType::Column,
            null,
            null,
            $gridSettings,
        );

        $this->assertInstanceOf(GridSettings::class, $node->gridSettings);
    }

    // ---- mapToNode: extension hook ----

    public function testExtensionHookEnrichesNode(): void
    {
        GridNodeMapper::add_extension(NodeMapperTestEnricherExtension::class);

        try {
            $leaf = $this->objFromFixture(GridElement::class, 'leaf1');

            /** @var positive-int $parentId */
            $parentId = (int) $this->objFromFixture(Column::class, 'col1')->ID;

            $node = $this->getMapper()->mapToNode($leaf, $parentId, null, null, null, null);
            $data = $node->jsonSerialize();

            $this->assertArrayHasKey('extensions', $data);
            $this->assertArrayHasKey('testMapper', $data['extensions']);
            $this->assertSame('mapped', $data['extensions']['testMapper']);
        } finally {
            GridNodeMapper::remove_extension(NodeMapperTestEnricherExtension::class);
        }
    }

    // ---- getAllowedTypes: Section ----

    public function testGetAllowedTypesForSectionContainsRow(): void
    {
        $section = $this->objFromFixture(Section::class, 'section1');
        $types = $this->getMapper()->getAllowedTypes($section);

        $this->assertArrayHasKey(Row::class, $types);
        $this->assertSame('Row', $types[Row::class]['label']);
        $this->assertSame('font-icon-columns', $types[Row::class]['icon']);
        $this->assertSame(
            'Horizontal container that holds columns within a section',
            $types[Row::class]['description'],
        );
    }

    // ---- getAllowedTypes: Row ----

    public function testGetAllowedTypesForRowContainsColumn(): void
    {
        $row = $this->objFromFixture(Row::class, 'row1');
        $types = $this->getMapper()->getAllowedTypes($row);

        $this->assertArrayHasKey(Column::class, $types);
        $this->assertArrayNotHasKey(Section::class, $types);
        $this->assertArrayNotHasKey(Row::class, $types);
    }

    // ---- getAllowedTypes: Column ----

    public function testGetAllowedTypesForColumnExcludesContainers(): void
    {
        $column = $this->objFromFixture(Column::class, 'col1');
        $types = $this->getMapper()->getAllowedTypes($column);

        $this->assertNotEmpty($types);
        $this->assertArrayNotHasKey(Section::class, $types);
        $this->assertArrayNotHasKey(Row::class, $types);
        $this->assertArrayNotHasKey(Column::class, $types);
        $this->assertArrayHasKey(ContentElement::class, $types);
    }

    // ---- getAllowedTypes: caching ----

    public function testGetAllowedTypesReturnsCachedResult(): void
    {
        $mapper = $this->getMapper();
        $section = $this->objFromFixture(Section::class, 'section1');

        $first = $mapper->getAllowedTypes($section);
        $second = $mapper->getAllowedTypes($section);

        $this->assertSame($first, $second);
    }

    // ---- getAllowedTypes: type info structure ----

    public function testGetAllowedTypesTypeInfoStructure(): void
    {
        $column = $this->objFromFixture(Column::class, 'col1');
        $types = $this->getMapper()->getAllowedTypes($column);

        foreach ($types as $class => $info) {
            $this->assertIsString($class);
            $this->assertArrayHasKey('label', $info);
            $this->assertArrayHasKey('icon', $info);
            $this->assertArrayHasKey('description', $info);
            $this->assertNotEmpty($info['label']);
            $this->assertNotEmpty($info['icon']);
        }
    }
}

/**
 * Test extension that enriches element nodes via the updateElementData hook.
 */
class NodeMapperTestEnricherExtension extends Extension
{
    /**
     * @param array<string, mixed> $extensions
     */
    public function updateElementData(GridElement $element, array &$extensions): void
    {
        $extensions['testMapper'] = 'mapped';
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridNodeMapper;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\SummarizedContentElement;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\ElementStatus;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;

#[CoversClass(GridNodeMapper::class)]
#[CoversClass(ElementStatus::class)]
final class GridNodeMapperTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private GridNodeMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        $this->mapper = Injector::inst()->get(GridNodeMapper::class);
    }

    public function testMapToNodeIncludesAllFields(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'My Section');

        $allowedTypes = $this->mapper->getAllowedTypes($section);
        $parentRef = new NodeRef(NodeType::Page, (int) $page->ID);
        $node = $this->mapper->mapToNode(
            $section,
            $parentRef,
            ContainerType::Section,
            $allowedTypes,
            [],
            null,
        );

        self::assertSame((int) $section->ID, $node->getId());
        self::assertSame((int) $page->ID, $node->getParentId());
        self::assertSame(NodeType::Section, $node->self->type);
        self::assertSame(NodeType::Page, $node->parent->type);
        self::assertSame('My Section', $node->title);
        self::assertSame(ContainerType::Section, $node->containerType);
        self::assertIsArray($node->children);
        self::assertNull($node->gridSettings);

        // blockSchema fields — assert concrete values, not just key existence
        self::assertSame($section->getTypeName(), $node->blockSchema['typeName']);
        self::assertSame('Section', $node->blockSchema['type']);
        self::assertSame('My Section', $node->blockSchema['title']);

        self::assertSame('Section', $node->blockSchema['label']);
        self::assertSame('font-icon-block-layout', $node->blockSchema['icon']);

        // Edit link present for a Section with a page parent
        self::assertNotNull($node->editLink);

        // Version matches the element's version
        self::assertSame((int) $section->Version, $node->version);

        // Permission booleans — logged in with CMS_ACCESS
        self::assertTrue($node->canDelete);
        self::assertTrue($node->canPublish);
        self::assertTrue($node->canCreate);

        // Metadata fields
        self::assertNull($node->obsoleteClassName);
        self::assertSame(ElementStatus::Draft, $node->status);
        self::assertIsArray($node->extensions);
    }

    public function testMapToNodeIncludesSummaryWhenElementProvidesOne(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = SummarizedContentElement::create();
        $element->testSummary = 'Hello admin';
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        $parentRef = new NodeRef(NodeType::Column, (int) $column->ID);
        $node = $this->mapper->mapToNode(
            $element,
            $parentRef,
            null,
            null,
            null,
            null,
        );

        self::assertSame('Hello admin', $node->summary);

        $serialized = $node->jsonSerialize();
        self::assertArrayHasKey('summary', $serialized);
        self::assertSame('Hello admin', $serialized['summary']);
    }

    public function testMapToNodeOmitsSummaryWhenNull(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'My Section');

        $parentRef = new NodeRef(NodeType::Page, (int) $page->ID);
        $node = $this->mapper->mapToNode(
            $section,
            $parentRef,
            ContainerType::Section,
            null,
            [],
            null,
        );

        self::assertNull($node->summary);
        self::assertArrayNotHasKey('summary', $node->jsonSerialize());
    }

    public function testMapToNodeOmitsSummaryWhenEmptyString(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = SummarizedContentElement::create();
        $element->testSummary = '';
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        $parentRef = new NodeRef(NodeType::Column, (int) $column->ID);
        $node = $this->mapper->mapToNode(
            $element,
            $parentRef,
            null,
            null,
            null,
            null,
        );

        self::assertSame('', $node->summary);
        self::assertArrayNotHasKey('summary', $node->jsonSerialize());
    }

    public function testMapToNodePublishedSectionResolvesToPublishedStatus(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'Published Section');
        $section->publishSingle();

        $parentRef = new NodeRef(NodeType::Page, (int) $page->ID);
        $node = $this->mapper->mapToNode(
            $section,
            $parentRef,
            ContainerType::Section,
            null,
            [],
            null,
        );

        self::assertSame(ElementStatus::Published, $node->status);
    }

    public function testMapToNodeModifiedSectionResolvesToModifiedStatus(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'Section');
        $section->publishSingle();

        // Modify after publish so getStatusFlags reports 'modified'
        $section->Title = 'Section (edited)';
        $section->write();

        $parentRef = new NodeRef(NodeType::Page, (int) $page->ID);
        $node = $this->mapper->mapToNode(
            $section,
            $parentRef,
            ContainerType::Section,
            null,
            [],
            null,
        );

        self::assertSame(ElementStatus::Modified, $node->status);
    }

    public function testMapToNodeUntitledFallback(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        // Create element with explicit empty title — but GridElement::onBeforeWrite
        // auto-assigns a default title. We need to clear it after write.
        $section = GridTreeFactory::section($page, title: '');

        // Force Title to empty after write to test the mapper fallback
        $section->Title = '';

        $parentRef = new NodeRef(NodeType::Page, (int) $page->ID);
        $node = $this->mapper->mapToNode(
            $section,
            $parentRef,
            ContainerType::Section,
            null,
            [],
            null,
        );

        // The mapper falls back to '(untitled)' or its i18n equivalent
        self::assertStringContainsString('untitled', strtolower($node->title));
    }

    public function testGetAllowedTypesForSection(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $allowed = $this->mapper->getAllowedTypes($section);

        self::assertArrayHasKey(Row::class, $allowed);
    }

    public function testGetAllowedTypesForRow(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $allowed = $this->mapper->getAllowedTypes($row);

        self::assertArrayHasKey(Column::class, $allowed);
    }

    public function testGetAllowedTypesForColumn(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $allowed = $this->mapper->getAllowedTypes($column);

        self::assertArrayHasKey(ContentElement::class, $allowed);
        self::assertArrayNotHasKey(Section::class, $allowed);
        self::assertArrayNotHasKey(Row::class, $allowed);
        self::assertArrayNotHasKey(Column::class, $allowed);
    }

    public function testGetAllowedTypesConsistentAcrossCalls(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $first = $this->mapper->getAllowedTypes($section);
        $second = $this->mapper->getAllowedTypes($section);

        self::assertEquals($first, $second);
    }

    public function testGetAllowedTypesIncludesMetadata(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $allowed = $this->mapper->getAllowedTypes($section);

        // Generic structure check for all entries
        foreach ($allowed as $typeInfo) {
            self::assertArrayHasKey('label', $typeInfo);
            self::assertArrayHasKey('icon', $typeInfo);
            self::assertArrayHasKey('description', $typeInfo);
            self::assertNotEmpty($typeInfo['label']);
            self::assertNotEmpty($typeInfo['icon']);
            self::assertIsString($typeInfo['description']);
        }

        // Assert concrete values for the Row entry (Section allows only Rows)
        self::assertArrayHasKey(Row::class, $allowed);
        $rowMeta = $allowed[Row::class];
        self::assertSame('Row', $rowMeta['label']);
        self::assertSame('font-icon-columns', $rowMeta['icon']);
        self::assertSame('Horizontal container that holds columns within a section', $rowMeta['description']);
    }

    public function testGetAllowedTypesForColumnExcludesBaseClass(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $allowed = $this->mapper->getAllowedTypes($column);

        // Base GridElement class should never appear in allowed types
        self::assertArrayNotHasKey(GridElement::class, $allowed);
        // Should have at least ContentElement
        self::assertNotEmpty($allowed);
    }

    public function testGetAllowedTypesCacheReturnsIdenticalResult(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $first = $this->mapper->getAllowedTypes($section);
        $second = $this->mapper->getAllowedTypes($section);

        // Cache hit should return identical object (same reference)
        self::assertSame($first, $second);
    }

    /**
     * Pins the cache-hit early-return (ReturnRemoval mutation on line 114):
     * with the `return` removed, the second call re-computes types from config
     * and would see the second singular_name; the original returns the cached
     * first value.
     */
    public function testGetAllowedTypesCacheShieldsFromSubsequentConfigChanges(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        Config::modify()->set(Row::class, 'singular_name', 'FirstLabel');
        $first = $this->mapper->getAllowedTypes($section);

        Config::modify()->set(Row::class, 'singular_name', 'SecondLabel');
        $second = $this->mapper->getAllowedTypes($section);

        self::assertSame('FirstLabel', $first[Row::class]['label']);
        self::assertSame(
            'FirstLabel',
            $second[Row::class]['label'],
            'Cache-hit path must not re-read config',
        );
    }

    // ─── getElementTypeInfo: singular_name / icon / description fallbacks ──

    public function testElementTypeInfoLabelUsesConfiguredSingularName(): void
    {
        // Set a singular_name distinct from ClassInfo::shortName so the Ternary
        // and NotIdentical mutants at line 154 become observable.
        Config::modify()->set(ContentElement::class, 'singular_name', 'DistinctSingularLabel');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $allowed = $this->mapper->getAllowedTypes($column);

        self::assertSame('DistinctSingularLabel', $allowed[ContentElement::class]['label']);
    }

    public function testElementTypeInfoLabelFallsBackToShortNameWhenSingularNameEmpty(): void
    {
        Config::modify()->set(ContentElement::class, 'singular_name', '');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $allowed = $this->mapper->getAllowedTypes($column);

        self::assertSame(
            ClassInfo::shortName(ContentElement::class),
            $allowed[ContentElement::class]['label'],
        );
    }

    public function testElementTypeInfoIconUsesConfiguredValue(): void
    {
        Config::modify()->set(ContentElement::class, 'icon', 'custom-icon-value');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $allowed = $this->mapper->getAllowedTypes($column);

        self::assertSame('custom-icon-value', $allowed[ContentElement::class]['icon']);
    }

    public function testElementTypeInfoIconFallsBackWhenEmpty(): void
    {
        Config::modify()->set(ContentElement::class, 'icon', '');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $allowed = $this->mapper->getAllowedTypes($column);

        self::assertSame('font-icon-block-content', $allowed[ContentElement::class]['icon']);
    }

    public function testElementTypeInfoDescriptionUsesConfiguredValue(): void
    {
        Config::modify()->set(ContentElement::class, 'class_description', 'My custom description');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $allowed = $this->mapper->getAllowedTypes($column);

        self::assertSame('My custom description', $allowed[ContentElement::class]['description']);
    }

    public function testElementTypeInfoDescriptionFallsBackToEmptyWhenUnset(): void
    {
        Config::modify()->set(ContentElement::class, 'class_description', '');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $allowed = $this->mapper->getAllowedTypes($column);

        self::assertSame('', $allowed[ContentElement::class]['description']);
    }

    public function testElementTypeInfoDescriptionFallsBackToEmptyForNonStringConfig(): void
    {
        // class_description is the is_string() arm of the description guard:
        //   is_string($description) && $description !== '' ? $description : ''
        // A non-string config value (here an int) must fall back to ''. The
        // LogicalAnd mutant (`&&` → `||`) would short-circuit on the truthy
        // `$description !== ''` and leak the int through, so this case kills it.
        Config::modify()->set(ContentElement::class, 'class_description', 123);

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $allowed = $this->mapper->getAllowedTypes($column);

        self::assertSame('', $allowed[ContentElement::class]['description']);
    }

    /**
     * mapToNode icon fallback at line 62 mirrors getElementTypeInfo's icon guard.
     * Configuring an empty icon pins the LogicalAnd / is_string guard.
     */
    public function testMapToNodeIconFallsBackWhenConfigEmpty(): void
    {
        Config::modify()->set(Section::class, 'icon', '');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'My Section');

        $parentRef = new NodeRef(NodeType::Page, (int) $page->ID);
        $node = $this->mapper->mapToNode(
            $section,
            $parentRef,
            ContainerType::Section,
            null,
            [],
            null,
        );

        self::assertSame('font-icon-block-content', $node->blockSchema['icon']);
    }
}

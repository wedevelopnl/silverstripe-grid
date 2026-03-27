<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridNodeMapper;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\ContainerType;

#[CoversClass(GridNodeMapper::class)]
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
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'My Section');

        $allowedTypes = $this->mapper->getAllowedTypes($section);
        $node = $this->mapper->mapToNode(
            $section,
            $page->ID,
            ContainerType::Section,
            $allowedTypes,
            [],
            null,
        );

        self::assertSame((int) $section->ID, $node->id);
        self::assertSame((int) $page->ID, $node->parentId);
        self::assertSame('My Section', $node->title);
        self::assertSame(ContainerType::Section, $node->containerType);
        self::assertIsArray($node->children);
        self::assertNull($node->gridSettings);

        // blockSchema fields — assert concrete values, not just key existence
        self::assertSame($section->getTypeName(), $node->blockSchema['typeName']);
        self::assertSame('Section', $node->blockSchema['type']);
        self::assertSame('My Section', $node->blockSchema['title']);
        self::assertSame($section->getSummary(), $node->blockSchema['summary']);
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
        self::assertIsArray($node->statusFlags);
        self::assertIsArray($node->extensions);
    }

    public function testMapToNodeUntitledFallback(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        // Create element with explicit empty title — but GridElement::onBeforeWrite
        // auto-assigns a default title. We need to clear it after write.
        $section = GridTreeFactory::section($page, title: '');

        // Force Title to empty after write to test the mapper fallback
        $section->Title = '';

        $node = $this->mapper->mapToNode(
            $section,
            $page->ID,
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
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $allowed = $this->mapper->getAllowedTypes($section);

        self::assertArrayHasKey(Row::class, $allowed);
    }

    public function testGetAllowedTypesForRow(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $allowed = $this->mapper->getAllowedTypes($row);

        self::assertArrayHasKey(Column::class, $allowed);
    }

    public function testGetAllowedTypesForColumn(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
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
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $first = $this->mapper->getAllowedTypes($section);
        $second = $this->mapper->getAllowedTypes($section);

        self::assertEquals($first, $second);
    }

    public function testGetAllowedTypesIncludesMetadata(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
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
}

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

        // blockSchema fields
        self::assertArrayHasKey('typeName', $node->blockSchema);
        self::assertArrayHasKey('type', $node->blockSchema);
        self::assertArrayHasKey('title', $node->blockSchema);
        self::assertArrayHasKey('summary', $node->blockSchema);
        self::assertArrayHasKey('label', $node->blockSchema);
        self::assertArrayHasKey('icon', $node->blockSchema);

        // Permission booleans
        self::assertIsBool($node->canDelete);
        self::assertIsBool($node->canPublish);
        self::assertIsBool($node->canUnpublish);
        self::assertIsBool($node->canCreate);

        // Version (written once, so at least 1)
        self::assertGreaterThanOrEqual(1, $node->version);
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

        foreach ($allowed as $typeInfo) {
            self::assertArrayHasKey('label', $typeInfo);
            self::assertArrayHasKey('icon', $typeInfo);
            self::assertArrayHasKey('description', $typeInfo);
            self::assertNotEmpty($typeInfo['label']);
            self::assertNotEmpty($typeInfo['icon']);
            self::assertIsString($typeInfo['description']);
        }
    }
}

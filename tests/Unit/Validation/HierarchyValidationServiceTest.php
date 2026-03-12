<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SilverStripe\Config\Collections\MemoryConfigCollection;
use SilverStripe\Core\Config\ConfigLoader;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\AllowedParentStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\DisallowedParentStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\NonRootElementStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\PageStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\RootElementStub;
use WeDevelop\Grid\Validation\ElementAllowanceTrait;
use WeDevelop\Grid\Validation\HierarchyValidationService;

/**
 * Unit tests for HierarchyValidationService using concrete stub classes
 * and a MemoryConfigCollection for static config values.
 *
 * All elements, pages, and container parents use stub classes (not mocks) because
 * the validation code calls static::config() via the Configurable trait,
 * which PHPUnit mocks cannot handle.
 */
#[CoversClass(HierarchyValidationService::class)]
#[CoversClass(ElementAllowanceTrait::class)]
final class HierarchyValidationServiceTest extends TestCase
{
    private HierarchyValidationService $service;

    private MemoryConfigCollection $configCollection;

    protected function setUp(): void
    {
        $this->configCollection = new MemoryConfigCollection();
        ConfigLoader::inst()->pushManifest($this->configCollection);
        $this->service = new HierarchyValidationService();
    }

    protected function tearDown(): void
    {
        ConfigLoader::inst()->popManifest();
    }

    // ─── Orphan elements ────────────────────────────────────────────

    public function testOrphanElementReturnsOk(): void
    {
        $element = new RootElementStub();
        $element->setParentObject(null);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isOk());
    }

    // ─── Page-level placement ───────────────────────────────────────

    public function testPageLevelWithCanBeRootTrueReturnsOk(): void
    {
        $page = new PageStub();
        $element = new RootElementStub();
        $element->setParentObject($page);
        $this->configCollection->set(RootElementStub::class, 'can_be_root', true);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isOk());
    }

    public function testPageLevelWithCanBeRootFalseReturnsFail(): void
    {
        $page = new PageStub();
        $element = new NonRootElementStub();
        $element->setParentObject($page);
        $this->configCollection->set(NonRootElementStub::class, 'can_be_root', false);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isErr());
        $this->assertCount(1, $result->errors());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    public function testPageLevelWithCanBeRootNullReturnsOk(): void
    {
        $page = new PageStub();
        $element = new RootElementStub();
        $element->setParentObject($page);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isOk());
    }

    // ─── Container-level placement ──────────────────────────────────

    public function testContainerLevelAllowedReturnsOk(): void
    {
        $parent = new AllowedParentStub();
        $this->configCollection->set(AllowedParentStub::class, 'allowed_elements', [RootElementStub::class]);
        $element = new RootElementStub();
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isOk());
    }

    public function testContainerLevelNotInAllowedReturnsFail(): void
    {
        $parent = new AllowedParentStub();
        $this->configCollection->set(AllowedParentStub::class, 'allowed_elements', ['SomeOtherClass']);
        $element = new NonRootElementStub();
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isErr());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    public function testContainerLevelInDisallowedReturnsFail(): void
    {
        $parent = new DisallowedParentStub();
        $this->configCollection->set(DisallowedParentStub::class, 'disallowed_elements', [RootElementStub::class]);
        $element = new RootElementStub();
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isErr());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    public function testContainerLevelNoRestrictionsReturnsOk(): void
    {
        $parent = new AllowedParentStub();
        $element = new RootElementStub();
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isOk());
    }

    public function testFailMessageIncludesElementAndParentNames(): void
    {
        $parent = new AllowedParentStub();
        $this->configCollection->set(AllowedParentStub::class, 'allowed_elements', ['SomeOtherClass']);
        $this->configCollection->set(NonRootElementStub::class, 'singular_name', 'Row');
        $this->configCollection->set(AllowedParentStub::class, 'singular_name', 'Section');
        $element = new NonRootElementStub();
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Row', $result->errors()[0]->message);
        $this->assertStringContainsString('Section', $result->errors()[0]->message);
    }
}

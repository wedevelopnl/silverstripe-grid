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
use WeDevelop\Grid\Validation\ReorderValidator;

/**
 * Unit tests for ReorderValidator — covers same-parent short-circuit
 * and cross-parent hierarchy checks.
 *
 * Uses concrete stub classes (not mocks) for elements, pages, and container
 * parents because the validation code calls static::config() via Configurable.
 */
#[CoversClass(ReorderValidator::class)]
#[CoversClass(ElementAllowanceTrait::class)]
final class ReorderValidatorTest extends TestCase
{
    private ReorderValidator $validator;

    private MemoryConfigCollection $configCollection;

    protected function setUp(): void
    {
        $this->configCollection = new MemoryConfigCollection();
        ConfigLoader::inst()->pushManifest($this->configCollection);
        $this->validator = new ReorderValidator();
    }

    protected function tearDown(): void
    {
        ConfigLoader::inst()->popManifest();
    }

    // ─── Same-parent moves ──────────────────────────────────────────

    public function testSameParentMoveIsAlwaysValid(): void
    {
        $parent = new AllowedParentStub();
        $parent->ID = 10;
        $element = new RootElementStub();
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $parent);

        $this->assertTrue($result->isOk());
    }

    // ─── Cross-parent: page-level ───────────────────────────────────

    public function testCrossParentToPageWithCanBeRootTrueReturnsOk(): void
    {
        $page = new PageStub();
        $page->ID = 20;
        $element = new RootElementStub();
        $element->ID = 1;
        $element->ParentID = 10;
        $this->configCollection->set(RootElementStub::class, 'can_be_root', true);

        $result = $this->validator->validate($element, $page);

        $this->assertTrue($result->isOk());
    }

    public function testCrossParentToPageWithCanBeRootFalseReturnsFail(): void
    {
        $page = new PageStub();
        $page->ID = 20;
        $element = new NonRootElementStub();
        $element->ID = 1;
        $element->ParentID = 10;
        $this->configCollection->set(NonRootElementStub::class, 'can_be_root', false);

        $result = $this->validator->validate($element, $page);

        $this->assertTrue($result->isErr());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    public function testCrossParentToPageWithCanBeRootNullReturnsOk(): void
    {
        $page = new PageStub();
        $page->ID = 20;
        $element = new RootElementStub();
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $page);

        $this->assertTrue($result->isOk());
    }

    // ─── Cross-parent: container-level ──────────────────────────────

    public function testCrossParentToContainerAllowedReturnsOk(): void
    {
        $target = new AllowedParentStub();
        $target->ID = 20;
        $this->configCollection->set(AllowedParentStub::class, 'allowed_elements', [RootElementStub::class]);
        $element = new RootElementStub();
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $target);

        $this->assertTrue($result->isOk());
    }

    public function testCrossParentToContainerNotInAllowedReturnsFail(): void
    {
        $target = new AllowedParentStub();
        $target->ID = 20;
        $this->configCollection->set(AllowedParentStub::class, 'allowed_elements', ['SomeOtherClass']);
        $element = new NonRootElementStub();
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $target);

        $this->assertTrue($result->isErr());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    public function testCrossParentToContainerInDisallowedReturnsFail(): void
    {
        $target = new DisallowedParentStub();
        $target->ID = 20;
        $this->configCollection->set(DisallowedParentStub::class, 'disallowed_elements', [RootElementStub::class]);
        $element = new RootElementStub();
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $target);

        $this->assertTrue($result->isErr());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    public function testCrossParentToContainerNoRestrictionsReturnsOk(): void
    {
        $target = new AllowedParentStub();
        $target->ID = 20;
        $element = new RootElementStub();
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $target);

        $this->assertTrue($result->isOk());
    }
}

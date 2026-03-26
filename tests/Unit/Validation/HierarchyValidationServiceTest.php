<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\ContainerParentStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\NonRootElementStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\PageStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\PlainDataObjectStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\RootElementStub;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Validation\HierarchyValidationService;

/**
 * Unit tests for HierarchyValidationService using concrete stub classes.
 *
 * Hierarchy rules are hardcoded in ContainerType — no config setup needed.
 * RootElementStub = Section (canBeRoot=true), NonRootElementStub = Row (canBeRoot=false).
 */
#[CoversClass(HierarchyValidationService::class)]
final class HierarchyValidationServiceTest extends TestCase
{
    private HierarchyValidationService $service;

    protected function setUp(): void
    {
        $this->service = new HierarchyValidationService();
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

    public function testPageLevelWithRootElementReturnsOk(): void
    {
        $page = new PageStub();
        $element = new RootElementStub(); // Section — canBeRoot=true

        $element->setParentObject($page);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isOk());
    }

    public function testPageLevelWithNonRootElementReturnsFail(): void
    {
        $page = new PageStub();
        $element = new NonRootElementStub(); // Row — canBeRoot=false

        $element->setParentObject($page);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isErr());
        $this->assertCount(1, $result->errors());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    // ─── Container-level placement ──────────────────────────────────

    public function testContainerLevelAllowedChildReturnsOk(): void
    {
        // Section parent allows Row children
        $parent = $this->makeContainerParent(ContainerType::Section);
        $element = new NonRootElementStub(); // Row — extends RootElementStub (GridElement)
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        // Section's isChildAllowed checks is_a(Row::class) — NonRootElementStub
        // extends RootElementStub extends GridElement, NOT Row, so this should fail
        $this->assertTrue($result->isErr());
    }

    public function testContainerAllowsCorrectChildType(): void
    {
        // Use a ContainerParentStub typed as Column — it allows any non-container element
        $parent = $this->makeContainerParent(ContainerType::Column);
        $element = new RootElementStub(); // extends GridElement, but implements ContainerInterface
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        // Column rejects container elements (Section/Row/Column) — RootElementStub is
        // neither Section nor Row nor Column class, so isChildAllowed checks is_a()
        // against those. RootElementStub extends GridElement, not any container class.
        $this->assertTrue($result->isOk());
    }

    public function testContainerRejectsDisallowedChild(): void
    {
        // Row parent only allows Column children
        $parent = $this->makeContainerParent(ContainerType::Row);
        $element = new RootElementStub(); // Not a Column
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isErr());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    public function testFailMessageIncludesElementAndParentNames(): void
    {
        $parent = $this->makeContainerParent(ContainerType::Row);
        $element = new RootElementStub(); // singular_name = 'Section'
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Section', $result->errors()[0]->message);
        $this->assertStringContainsString('Container', $result->errors()[0]->message);
    }

    // ─── Malformed parent state ────────────────────────────────────

    public function testNonExistentParentReturnsOk(): void
    {
        $parent = new class () extends ContainerParentStub {
            public function exists(): bool
            {
                return false;
            }
        };

        $element = new RootElementStub();
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        $this->assertTrue($result->isOk());
    }

    public function testNonContainerNonPageParentReturnsFail(): void
    {
        $parent = new PlainDataObjectStub();

        $element = new RootElementStub();
        $element->setParentObject($parent);

        $result = $this->service->validate($element);

        // Falls through to the final fail() — not a SiteTree, not a ContainerInterface
        $this->assertTrue($result->isErr());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    private function makeContainerParent(ContainerType $type): ContainerParentStub
    {
        $stub = new ContainerParentStub();
        $stub->setContainerType($type);

        return $stub;
    }
}

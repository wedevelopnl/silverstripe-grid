<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\ContainerParentStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\NonRootElementStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\PageStub;
use WeDevelop\Grid\Tests\Unit\Validation\Stub\RootElementStub;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Validation\ReorderValidator;

/**
 * Unit tests for ReorderValidator — covers same-parent short-circuit
 * and cross-parent hierarchy checks.
 *
 * Hierarchy rules are hardcoded in ContainerType — no config setup needed.
 */
#[CoversClass(ReorderValidator::class)]
final class ReorderValidatorTest extends TestCase
{
    private ReorderValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ReorderValidator();
    }

    // ─── Same-parent moves ──────────────────────────────────────────

    public function testSameParentMoveIsAlwaysValid(): void
    {
        $parent = $this->makeContainerParent(ContainerType::Section);
        $parent->ID = 10;
        $element = new RootElementStub();
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $parent);

        $this->assertTrue($result->isOk());
    }

    // ─── Cross-parent: page-level ───────────────────────────────────

    public function testCrossParentToPageWithRootElementReturnsOk(): void
    {
        $page = new PageStub();
        $page->ID = 20;
        $element = new RootElementStub(); // Section — canBeRoot=true
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $page);

        $this->assertTrue($result->isOk());
    }

    public function testCrossParentToPageWithNonRootElementReturnsFail(): void
    {
        $page = new PageStub();
        $page->ID = 20;
        $element = new NonRootElementStub(); // Row — canBeRoot=false
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $page);

        $this->assertTrue($result->isErr());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    // ─── Cross-parent: container-level ──────────────────────────────

    public function testCrossParentToContainerAllowedReturnsOk(): void
    {
        // Column allows any non-container GridElement
        $target = $this->makeContainerParent(ContainerType::Column);
        $target->ID = 20;
        $element = new RootElementStub(); // extends GridElement (not a real container class)
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $target);

        $this->assertTrue($result->isOk());
    }

    public function testCrossParentToContainerNotAllowedReturnsFail(): void
    {
        // Row only allows Column children
        $target = $this->makeContainerParent(ContainerType::Row);
        $target->ID = 20;
        $element = new RootElementStub(); // Not a Column
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $target);

        $this->assertTrue($result->isErr());
        $this->assertSame('placement', $result->errors()[0]->field);
    }

    public function testCrossParentToSectionRejectsNonRowElement(): void
    {
        // Section only allows Row children
        $target = $this->makeContainerParent(ContainerType::Section);
        $target->ID = 20;
        $element = new RootElementStub(); // Not a Row
        $element->ID = 1;
        $element->ParentID = 10;

        $result = $this->validator->validate($element, $target);

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

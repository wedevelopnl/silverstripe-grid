<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\ContainerType;

#[CoversClass(ContainerType::class)]
final class ContainerTypeTest extends TestCase
{
    // ─── canBeRoot ──────────────────────────────────────────────────

    public function testSectionCanBeRoot(): void
    {
        $this->assertTrue(ContainerType::Section->canBeRoot());
    }

    public function testRowCannotBeRoot(): void
    {
        $this->assertFalse(ContainerType::Row->canBeRoot());
    }

    public function testColumnCannotBeRoot(): void
    {
        $this->assertFalse(ContainerType::Column->canBeRoot());
    }

    // ─── allowedChildClass ──────────────────────────────────────────

    public function testSectionAllowedChildIsRow(): void
    {
        $this->assertSame(Row::class, ContainerType::Section->allowedChildClass());
    }

    public function testRowAllowedChildIsColumn(): void
    {
        $this->assertSame(Column::class, ContainerType::Row->allowedChildClass());
    }

    public function testColumnAllowedChildIsNull(): void
    {
        $this->assertNull(ContainerType::Column->allowedChildClass());
    }

    // ─── isChildAllowed: Section ────────────────────────────────────

    public function testSectionAllowsRow(): void
    {
        $this->assertTrue(ContainerType::Section->isChildAllowed(Row::class));
    }

    public function testSectionRejectsColumn(): void
    {
        $this->assertFalse(ContainerType::Section->isChildAllowed(Column::class));
    }

    public function testSectionRejectsSection(): void
    {
        $this->assertFalse(ContainerType::Section->isChildAllowed(Section::class));
    }

    public function testSectionRejectsGenericElement(): void
    {
        $this->assertFalse(ContainerType::Section->isChildAllowed(GridElement::class));
    }

    // ─── isChildAllowed: Row ────────────────────────────────────────

    public function testRowAllowsColumn(): void
    {
        $this->assertTrue(ContainerType::Row->isChildAllowed(Column::class));
    }

    public function testRowRejectsRow(): void
    {
        $this->assertFalse(ContainerType::Row->isChildAllowed(Row::class));
    }

    public function testRowRejectsSection(): void
    {
        $this->assertFalse(ContainerType::Row->isChildAllowed(Section::class));
    }

    public function testRowRejectsGenericElement(): void
    {
        $this->assertFalse(ContainerType::Row->isChildAllowed(GridElement::class));
    }

    // ─── isChildAllowed: Column ─────────────────────────────────────

    public function testColumnRejectsSection(): void
    {
        $this->assertFalse(ContainerType::Column->isChildAllowed(Section::class));
    }

    public function testColumnRejectsRow(): void
    {
        $this->assertFalse(ContainerType::Column->isChildAllowed(Row::class));
    }

    public function testColumnRejectsColumn(): void
    {
        $this->assertFalse(ContainerType::Column->isChildAllowed(Column::class));
    }

    public function testColumnAcceptsNonContainerElement(): void
    {
        $this->assertTrue(ContainerType::Column->isChildAllowed(GridElement::class));
    }

}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use InvalidArgumentException;
use SilverStripe\CMS\Model\SiteTree;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Pages and grid elements live in separate DB tables with independent
 * auto-increment sequences, so a page and an element can share the same
 * numeric ID. Anywhere identity is stored as a bare int is a latent collision
 * bug; always pair it with the NodeType.
 */
enum NodeType: string
{
    case Page = 'page';
    case Section = 'section';
    case Row = 'row';
    case Column = 'column';
    case Element = 'element';

    /**
     * Sections/Rows/Columns are matched by hierarchy. Any non-container
     * GridElement subclass is classified as Element. SiteTree subclasses
     * are classified as Page.
     *
     * @param class-string $class
     */
    public static function fromClass(string $class): self
    {
        return match (true) {
            is_a($class, Section::class, true) => self::Section,
            is_a($class, Row::class, true) => self::Row,
            is_a($class, Column::class, true) => self::Column,
            is_a($class, GridElement::class, true) => self::Element,
            is_a($class, SiteTree::class, true) => self::Page,
            default => throw new InvalidArgumentException(sprintf(
                'Cannot resolve NodeType for class %s',
                $class,
            )),
        };
    }

    /**
     * The canonical class that represents this NodeType when looking up
     * records via the ORM. Element resolves to the abstract GridElement
     * base because leaf subclasses cannot be enumerated statically.
     *
     * @return class-string
     */
    public function toClass(): string
    {
        return match ($this) {
            self::Page => SiteTree::class,
            self::Section => Section::class,
            self::Row => Row::class,
            self::Column => Column::class,
            self::Element => GridElement::class,
        };
    }

    /**
     * The NodeType a node of this type must be parented to.
     *
     * The inverse of the containment rules in {@see ContainerType::canBeRoot()}
     * and {@see ContainerType::allowedChildClass()}: those answer "what may this
     * container hold?", this answers "what must hold this node?". Page is the
     * tree root and has no parent, hence null.
     */
    public function expectedParentType(): ?self
    {
        return match ($this) {
            self::Page => null,
            self::Section => self::Page,
            self::Row => self::Section,
            self::Column => self::Row,
            self::Element => self::Column,
        };
    }

    public function isContainer(): bool
    {
        return match ($this) {
            self::Section, self::Row, self::Column => true,
            self::Page, self::Element => false,
        };
    }

    public function isDraggable(): bool
    {
        return $this !== self::Page;
    }
}

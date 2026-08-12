<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model;

enum ContainerType: string
{
    case Section = 'section';
    case Row = 'row';
    case Column = 'column';

    /** Human-readable name of this container's child type (e.g. "row" for Section). */
    public function childTypeName(): string
    {
        return match ($this) {
            self::Section => 'row',
            self::Row => 'column',
            self::Column => 'element',
        };
    }

    /** @return class-string<Model\GridElement> */
    public function toElementClass(): string
    {
        return match ($this) {
            self::Section => Section::class,
            self::Row => Row::class,
            self::Column => Column::class,
        };
    }

    /** Whether elements of this container type can be placed at page level. */
    public function canBeRoot(): bool
    {
        return $this === self::Section;
    }

    /**
     * The single allowed child class for this container type, or null if
     * the container accepts any non-container GridElement (Column).
     *
     * @return class-string<Model\GridElement>|null
     */
    public function allowedChildClass(): ?string
    {
        return match ($this) {
            self::Section => Row::class,
            self::Row => Column::class,
            self::Column => null,
        };
    }

    /**
     * Section/Row: only the specific child class (or subclasses) is allowed.
     * Column: any non-container GridElement is allowed.
     *
     * @param class-string $elementClass
     */
    public function isChildAllowed(string $elementClass): bool
    {
        $allowed = $this->allowedChildClass();

        if ($allowed !== null) {
            return is_a($elementClass, $allowed, true);
        }

        // Column: any non-container GridElement
        return !is_a($elementClass, Section::class, true)
            && !is_a($elementClass, Row::class, true)
            && !is_a($elementClass, Column::class, true);
    }

    /**
     * Whether $class names an element type an author may create inside this
     * container.
     *
     * {@see isChildAllowed()} answers containment for an element that already
     * exists, so its callers hold an instance and the class is a GridElement by
     * construction. This answers it for a bare class name off a request body,
     * which must first be shown to name an element at all — the API hands it
     * straight to `Injector::create()`. A subclass, never the `GridElement` base
     * itself: that is the scaffold every element inherits, not an authorable type,
     * and the type picker never offers it.
     *
     * Type rules only; whether the current member may create one stays a
     * separate `canCreate()` check at the controller.
     *
     * @phpstan-assert-if-true class-string<GridElement> $class
     */
    public function isChildCreatable(string $class): bool
    {
        return is_subclass_of($class, GridElement::class)
            && $this->isChildAllowed($class);
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

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
            self::Section => Model\Section::class,
            self::Row => Model\Row::class,
            self::Column => Model\Column::class,
        };
    }
}

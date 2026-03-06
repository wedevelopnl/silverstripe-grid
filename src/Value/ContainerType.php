<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use WeDevelop\Grid\Model;

enum ContainerType: string
{
    case Section = 'section';
    case Row = 'row';
    case Column = 'column';

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

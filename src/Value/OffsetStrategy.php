<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

enum OffsetStrategy: string
{
    case Margin = 'margin';
    case GridPlacement = 'grid-placement';
}

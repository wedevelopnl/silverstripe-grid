<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

enum AspectRatio: string
{
    case Auto = 'auto';
    case Square = '1x1';
    case FourByThree = '4x3';
    case SixteenByNine = '16x9';
}

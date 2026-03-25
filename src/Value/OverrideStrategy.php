<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

enum OverrideStrategy: string
{
    case Isolated = 'isolated';
    case Cascade = 'cascade';
}

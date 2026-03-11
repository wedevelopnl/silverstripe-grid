<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

enum MediaPosition: string
{
    case First = 'first';
    case Last = 'last';
    case LastOnDesktop = 'last-on-desktop';
}

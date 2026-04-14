<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

enum ValidationErrorCode: string
{
    case Generic = 'generic';
    case OwnershipDenied = 'ownership_denied';
    case HierarchyViolation = 'hierarchy_violation';
    case InvalidGridSettings = 'invalid_grid_settings';
}

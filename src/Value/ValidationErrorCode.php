<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

enum ValidationErrorCode: string
{
    case Generic = 'generic';
    case OwnershipDenied = 'ownership_denied';
    case HierarchyViolation = 'hierarchy_violation';
    /**
     * @deprecated 6.0.0 Never constructed — grid-settings failures reach the
     *     client as `Generic` via WriteResult. Will be removed in 7.0.0 unless
     *     the grid-settings validation path is wired to it.
     */
    case InvalidGridSettings = 'invalid_grid_settings';
}

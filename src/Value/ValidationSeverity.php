<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Scheduled for removal in 7.0.0, together with the deprecated
 * {@see ValidationError::$severity} property that is its only consumer: nothing
 * reads a severity and `Warning` is never produced.
 *
 * Not tagged `@deprecated` at the enum level, because `Error` remains the
 * required default of that property for as long as it exists.
 */
enum ValidationSeverity: string
{
    case Error = 'error';

    /** @deprecated 6.0.0 Never produced by any validator. Will be removed in 7.0.0. */
    case Warning = 'warning';
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fixture;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Test extension that appends a class via the
 * {@see Row::getRowClasses()} extension hook.
 */
class UpdateRowClassesExtension extends Extension implements TestOnly
{
    protected function updateRowClasses(string &$classes): void
    {
        $classes .= ' test-row-extra';
    }
}

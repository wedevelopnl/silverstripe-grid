<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fixture;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Test extension that appends a class via the
 * {@see Section::getContainerClasses()} extension hook.
 */
class UpdateContainerClassesExtension extends Extension implements TestOnly
{
    protected function updateContainerClasses(string &$classes): void
    {
        $classes .= ' test-container-extra';
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fixture;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Test extension that overrides the title size class via the
 * {@see GridElement::getTitleSizeClass()} extension hook.
 */
class UpdateTitleSizeClassExtension extends Extension implements TestOnly
{
    protected function updateTitleSizeClass(string &$class): void
    {
        $class = 'custom-title-size';
    }
}

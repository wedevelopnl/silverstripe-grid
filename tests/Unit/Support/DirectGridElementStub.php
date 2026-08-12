<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Support;

use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\GridElement;

/**
 * A custom element that extends GridElement directly rather than ContentElement,
 * as `docs/usage/custom-elements.md` sanctions for blocks with no HTML body.
 */
class DirectGridElementStub extends GridElement implements TestOnly
{
    private static string $table_name = 'WeDevelop_Grid_Tests_DirectGridElementStub';
}

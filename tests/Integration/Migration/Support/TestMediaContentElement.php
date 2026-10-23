<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\ContentElement;

/**
 * A project's own text block type: the test applies BlockMediaExtension to this
 * subclass (not to ContentElement), so the media columns live in its own table.
 */
class TestMediaContentElement extends ContentElement implements TestOnly
{
    private static string $table_name = 'WeDevelop_Grid_Test_MediaContentElement';
}

<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\GridElement;

/**
 * Test-only GridElement subclass for verifying ClassName mapping in migration tests.
 */
class TestCustomElement extends GridElement implements TestOnly
{
    private static string $table_name = 'TestCustomElement';
}

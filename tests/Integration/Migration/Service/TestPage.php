<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Test-only SiteTree subclass for verifying that migration uses
 * the concrete page class name in polymorphic ParentClass fields.
 */
class TestPage extends SiteTree implements TestOnly
{
    private static string $table_name = 'TestPage';
}

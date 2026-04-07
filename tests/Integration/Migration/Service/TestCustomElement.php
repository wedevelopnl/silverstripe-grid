<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\GridElement;

/**
 * Test-only GridElement subclass with custom DB fields for verifying
 * that custom element types with project-specific fields can be migrated
 * via the extension hook chain.
 */
class TestCustomElement extends GridElement implements TestOnly
{
    private static string $table_name = 'TestCustomElement';

    /** @var array<string, string> */
    private static array $db = [
        'Subtitle' => 'Varchar(255)',
        'ButtonText' => 'Varchar(255)',
    ];
}

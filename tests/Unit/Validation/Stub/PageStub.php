<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation\Stub;

use SilverStripe\CMS\Model\SiteTree;

/**
 * Lightweight SiteTree stub that bypasses the DataObject constructor.
 * Used for page-level placement validation tests.
 */
class PageStub extends SiteTree
{
    /** @var int */
    public $ID = 0; // @phpstan-ignore property.phpDocType

    /**
     * @param array<string, mixed>|int|null $record
     */
    public function __construct(mixed $record = null, $creationType = self::CREATE_OBJECT, $queryParams = [])
    {
        // Intentionally empty — bypass DataObject constructor
    }

    public function exists(): bool
    {
        return true;
    }
}

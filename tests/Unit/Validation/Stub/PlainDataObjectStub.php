<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation\Stub;

use SilverStripe\ORM\DataObject;

/**
 * Lightweight DataObject stub that is neither a SiteTree nor a ContainerInterface.
 * Used to test hierarchy validation when the parent is an unexpected type.
 */
class PlainDataObjectStub extends DataObject
{
    private static string $table_name = 'PlainDataObjectStub';

    private static string $singular_name = 'PlainObject';

    /** @var int */
    public $ID = 1; // @phpstan-ignore property.phpDocType

    /**
     * @param array<string, mixed>|int|null $record
     */
    public function __construct(mixed $record = null, $creationType = self::CREATE_OBJECT, $queryParams = [])
    {
        // Intentionally skip DataObject constructor
    }

    public function exists(): bool
    {
        return true;
    }

    public function singular_name(): string
    {
        return 'PlainObject';
    }
}

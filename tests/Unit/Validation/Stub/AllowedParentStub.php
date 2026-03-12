<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation\Stub;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;

/**
 * Lightweight test stub for container parent with allowed_elements config.
 *
 * Extends DataObject for instanceof checks in ElementAllowanceTrait but
 * must NOT be instantiated directly — use createMock() or get() with a mocked DB.
 * Instead, we override __construct to avoid DataObject's constructor.
 */
class AllowedParentStub extends DataObject
{
    private static string $singular_name = 'Container';

    /** @var list<string>|null */
    private static ?array $allowed_elements = null;

    /** @var list<string> */
    private static array $disallowed_elements = [];

    private static bool $stop_element_inheritance = false;

    /** @var int */
    public $ID = 0; // @phpstan-ignore property.phpDocType

    /**
     * Skip the DataObject constructor entirely — we only need
     * config() and ID to satisfy the validation trait.
     *
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

    public function singular_name(): string
    {
        return static::config()->get('singular_name') ?? 'Container';
    }
}

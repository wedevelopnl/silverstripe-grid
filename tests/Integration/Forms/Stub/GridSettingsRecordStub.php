<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObjectInterface;

/**
 * Minimal DataObjectInterface stub that captures field writes via dynamic properties.
 *
 * Used by GridSettingsFieldTest to assert saveInto() output without a real DataObject.
 */
final class GridSettingsRecordStub implements DataObjectInterface, TestOnly
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct()
    {
    }

    public function write(): void
    {
    }

    public function delete(): void
    {
    }

    public function __get(string $property): mixed
    {
        return $this->data[$property] ?? null;
    }

    public function __set(string $property, mixed $value): void
    {
        $this->data[$property] = $value;
    }

    public function setCastedField($fieldName, $val): static
    {
        $this->data[$fieldName] = $val;

        return $this;
    }
}

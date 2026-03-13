<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation\Stub;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Value\ContainerType;

/**
 * Lightweight test stub for container parents with a configurable ContainerType.
 *
 * @implements ContainerInterface<DataObject>
 */
class ContainerParentStub extends DataObject implements ContainerInterface
{
    private static string $table_name = 'ContainerParentStub';

    private static string $singular_name = 'Container';

    /** @var int */
    public $ID = 0; // @phpstan-ignore property.phpDocType

    private ContainerType $containerType = ContainerType::Section;

    /**
     * @param array<string, mixed>|int|null $record
     */
    public function __construct(mixed $record = null, $creationType = self::CREATE_OBJECT, $queryParams = [])
    {
        // Intentionally skip DataObject constructor
    }

    public function setContainerType(ContainerType $containerType): void
    {
        $this->containerType = $containerType;
    }

    public function getContainerType(): ContainerType
    {
        return $this->containerType;
    }

    /** @return HasManyList<DataObject> */
    public function getChildren(): HasManyList
    {
        throw new \RuntimeException('Not implemented in stub');
    }

    public function hasChildren(): bool
    {
        return false;
    }

    public function exists(): bool
    {
        return true;
    }

    public function singular_name(): string
    {
        return 'Container';
    }
}

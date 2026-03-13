<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation\Stub;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\ContainerType;

/**
 * Lightweight stub for root elements (Section-like, canBeRoot = true).
 * Implements ContainerInterface with ContainerType::Section.
 * Bypasses DataObject constructor to avoid framework boot.
 *
 * @implements ContainerInterface<DataObject>
 */
class RootElementStub extends GridElement implements ContainerInterface
{
    private static string $singular_name = 'Section';

    /** @var int */
    public $ID = 1; // @phpstan-ignore property.phpDocType

    /** @var int */
    public $ParentID = 0; // @phpstan-ignore property.phpDocType

    /** @var string */
    public $ParentClass = ''; // @phpstan-ignore property.phpDocType

    /** @var string */
    public $Title = ''; // @phpstan-ignore property.phpDocType

    /** @var string */
    public $TitleClass = ''; // @phpstan-ignore property.phpDocType

    private ?DataObject $parentObject = null;

    /**
     * @param array<string, mixed>|int|null $record
     */
    public function __construct(mixed $record = null, $creationType = self::CREATE_OBJECT, $queryParams = [])
    {
        // Intentionally empty — bypass DataObject constructor
    }

    public function setParentObject(?DataObject $parent): void
    {
        $this->parentObject = $parent;
    }

    public function Parent(): ?DataObject
    {
        return $this->parentObject;
    }

    public function exists(): bool
    {
        return $this->ID > 0;
    }

    public function singular_name(): string
    {
        return 'Section';
    }

    public function getContainerType(): ContainerType
    {
        return ContainerType::Section;
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

    /**
     * No-op — bypasses Extensible::extend() which requires ClassInfo.
     * @param array<mixed> &...$arguments
     */
    public function extend($method, &...$arguments)
    {
        // Intentionally empty — unit tests don't need extension hooks
    }
}

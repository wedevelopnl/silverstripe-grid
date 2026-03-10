<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Contract;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use WeDevelop\Grid\Value\ContainerType;

/**
 * @template T of DataObject
 */
interface ContainerInterface
{
    /** @return HasManyList<T> */
    public function getChildren(): HasManyList;

    public function hasChildren(): bool;

    public function getContainerType(): ContainerType;
}

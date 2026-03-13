<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation\Stub;

use WeDevelop\Grid\Value\ContainerType;

/**
 * Stub for non-root elements (Row-like, canBeRoot = false).
 * Inherits ContainerInterface from RootElementStub but overrides
 * getContainerType() to return Row.
 */
class NonRootElementStub extends RootElementStub
{
    private static string $singular_name = 'Row';

    public function getContainerType(): ContainerType
    {
        return ContainerType::Row;
    }

    public function singular_name(): string
    {
        return 'Row';
    }
}

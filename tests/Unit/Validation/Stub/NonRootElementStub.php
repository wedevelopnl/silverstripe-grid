<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation\Stub;

/**
 * Separate stub class for elements with can_be_root = false.
 * Needs a distinct class so config() returns different values.
 */
class NonRootElementStub extends RootElementStub
{
    private static string $singular_name = 'Row';
}

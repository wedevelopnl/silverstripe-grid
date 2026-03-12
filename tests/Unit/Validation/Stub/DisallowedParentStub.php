<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Validation\Stub;

/**
 * Separate stub class so config() returns different values than AllowedParentStub.
 */
class DisallowedParentStub extends AllowedParentStub
{
    private static string $singular_name = 'Section';
}

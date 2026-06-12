<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationErrorCode;

#[CoversClass(ValidationErrorCode::class)]
final class ValidationErrorCodeTest extends TestCase
{
    public function testCodeDefaultsToGeneric(): void
    {
        $error = new ValidationError(message: 'y', field: 'x');
        self::assertSame(ValidationErrorCode::Generic, $error->code);
    }
}

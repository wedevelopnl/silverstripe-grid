<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationErrorCode;

final class ValidationErrorCodeTest extends TestCase
{
    public function testValidationErrorCarriesCode(): void
    {
        $error = new ValidationError(
            message: 'Cannot edit target page',
            field: 'pageId',
            code: ValidationErrorCode::OwnershipDenied,
        );

        self::assertSame(ValidationErrorCode::OwnershipDenied, $error->code);
    }

    public function testCodeDefaultsToGeneric(): void
    {
        $error = new ValidationError(message: 'y', field: 'x');
        self::assertSame(ValidationErrorCode::Generic, $error->code);
    }
}

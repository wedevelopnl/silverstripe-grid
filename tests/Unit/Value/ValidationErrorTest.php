<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationErrorCode;
use WeDevelop\Grid\Value\ValidationSeverity;

final class ValidationErrorTest extends TestCase
{
    public function testTranslateReturnsMessageVerbatimWhenKeyIsNull(): void
    {
        $error = new ValidationError(message: 'plain English');
        self::assertSame('plain English', $error->translate());
    }

    public function testKeyAndParamsArePreservedOnTheValueObject(): void
    {
        $error = new ValidationError(
            message: 'A {thing} cannot be placed here',
            key: 'WeDevelop\\Grid\\Tests\\Unit\\Value\\ValidationErrorTest.PLACEMENT',
            params: ['thing' => 'Section'],
        );
        self::assertSame('WeDevelop\\Grid\\Tests\\Unit\\Value\\ValidationErrorTest.PLACEMENT', $error->key);
        self::assertSame(['thing' => 'Section'], $error->params);
    }

    public function testExistingFieldsRemainAccessibleAndDefaultsUnchanged(): void
    {
        $error = new ValidationError(
            message: 'fail',
            field: 'placement',
            severity: ValidationSeverity::Warning,
            code: ValidationErrorCode::HierarchyViolation,
        );
        self::assertSame('fail', $error->message);
        self::assertSame('placement', $error->field);
        self::assertSame(ValidationSeverity::Warning, $error->severity);
        self::assertSame(ValidationErrorCode::HierarchyViolation, $error->code);
        self::assertNull($error->key);
        self::assertSame([], $error->params);
    }
}

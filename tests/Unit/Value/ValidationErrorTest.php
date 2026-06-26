<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ValidationError;

#[CoversClass(ValidationError::class)]
final class ValidationErrorTest extends TestCase
{
    public function testTranslateReturnsMessageVerbatimWhenKeyIsNull(): void
    {
        $error = new ValidationError(message: 'plain English');
        self::assertSame('plain English', $error->translate());
    }

    public function testTranslateReturnsMessageVerbatimWhenKeyIsNullEvenWithParams(): void
    {
        // params are ignored on the null-key path — the raw message is returned
        // unmodified rather than being run through placeholder injection.
        $error = new ValidationError(
            message: 'A {thing} cannot be placed here',
            params: ['thing' => 'Section'],
        );

        self::assertSame('A {thing} cannot be placed here', $error->translate());
    }
}

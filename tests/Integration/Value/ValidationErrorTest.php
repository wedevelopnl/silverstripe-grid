<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Validation\HierarchyValidationService;
use WeDevelop\Grid\Value\ValidationError;

#[CoversClass(ValidationError::class)]
final class ValidationErrorTest extends SapphireTest
{
    public function testTranslateResolvesCatalogueKeyAndInjectsParams(): void
    {
        $error = new ValidationError(
            message: 'fallback ignored when key resolves',
            key: HierarchyValidationService::class . '.PAGE_LEVEL_REJECTED',
            params: ['element' => 'Row'],
        );

        // key (pos 1) resolves to the catalogue string; params (pos 3) inject {element}
        self::assertSame('A Row cannot be placed at the page level.', $error->translate());
    }

    public function testTranslateFallsBackToMessageWhenKeyAbsentFromCatalogue(): void
    {
        $error = new ValidationError(
            message: 'English fallback wins',
            key: 'WeDevelop\\Grid\\Value\\ValidationErrorTest.UNDEFINED_KEY_FOR_TEST',
        );

        // pos 2: with no catalogue entry, _t() returns the fallback message verbatim
        self::assertSame('English fallback wins', $error->translate());
    }
}

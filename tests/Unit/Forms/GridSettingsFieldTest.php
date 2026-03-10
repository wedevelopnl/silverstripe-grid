<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Forms\GridSettingsField;
use WeDevelop\Grid\Value\Viewport;

/**
 * Unit tests for GridSettingsField expand/compact logic.
 *
 * Uses reflection to bypass the FormField constructor (which requires
 * SilverStripe's config manifest, unavailable in pure unit tests).
 */
#[CoversClass(GridSettingsField::class)]
final class GridSettingsFieldTest extends TestCase
{
    private GridAdapterInterface&MockObject $adapter;

    private GridSettingsField $field;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(GridAdapterInterface::class);
        $this->adapter->method('getViewports')->willReturn([
            new Viewport('xs', 'Extra Small'),
            new Viewport('sm', 'Small'),
            new Viewport('md', 'Medium'),
        ]);
        $this->adapter->method('getColumnCount')->willReturn(12);

        // Create instance without calling FormField::__construct() (needs config manifest)
        $ref = new \ReflectionClass(GridSettingsField::class);
        /** @var GridSettingsField $field */
        $field = $ref->newInstanceWithoutConstructor();
        $this->field = $field;

        // Inject the adapter dependency
        $adapterProp = $ref->getProperty('adapter');
        $adapterProp->setValue($this->field, $this->adapter);
    }

    public function testExpandEmptyJsonProducesAllDefaults(): void
    {
        $result = $this->field->expandFromSparse([]);

        $this->assertSame([
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => false],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => true],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => true],
        ], $result);
    }

    public function testExpandPartialSparseMarksOverridesAsNotInherited(): void
    {
        $sparse = [
            'md' => ['width' => 6, 'offset' => 2, 'visible' => true],
        ];

        $result = $this->field->expandFromSparse($sparse);

        // xs: defaults, not inherited (first viewport)
        $this->assertFalse($result['xs']['inherit']);
        $this->assertSame(12, $result['xs']['width']);

        // sm: inherits from xs
        $this->assertTrue($result['sm']['inherit']);
        $this->assertSame(12, $result['sm']['width']);

        // md: explicit override, not inherited
        $this->assertFalse($result['md']['inherit']);
        $this->assertSame(6, $result['md']['width']);
        $this->assertSame(2, $result['md']['offset']);
    }

    public function testExpandFirstViewportOverride(): void
    {
        $sparse = [
            'xs' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ];

        $result = $this->field->expandFromSparse($sparse);

        $this->assertSame(6, $result['xs']['width']);
        $this->assertFalse($result['xs']['inherit']);

        // sm and md inherit the xs override
        $this->assertTrue($result['sm']['inherit']);
        $this->assertSame(6, $result['sm']['width']);
        $this->assertTrue($result['md']['inherit']);
        $this->assertSame(6, $result['md']['width']);
    }

    public function testExpandFullSparseHasNoInherits(): void
    {
        $sparse = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => false],
        ];

        $result = $this->field->expandFromSparse($sparse);

        $this->assertFalse($result['xs']['inherit']);
        $this->assertFalse($result['sm']['inherit']);
        $this->assertFalse($result['md']['inherit']);
    }

    public function testCompactAllInheritProducesEmptySparse(): void
    {
        $full = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => false],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => true],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => true],
        ];

        $result = $this->field->compactToSparse($full);

        $this->assertSame([], $result);
    }

    public function testCompactFirstViewportDiffersFromDefaults(): void
    {
        $full = [
            'xs' => ['width' => 6, 'offset' => 0, 'visible' => true, 'inherit' => false],
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true, 'inherit' => true],
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true, 'inherit' => true],
        ];

        $result = $this->field->compactToSparse($full);

        $this->assertSame([
            'xs' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ], $result);
    }

    public function testCompactMiddleViewportOverride(): void
    {
        $full = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => false],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => true],
            'md' => ['width' => 6, 'offset' => 2, 'visible' => true, 'inherit' => false],
        ];

        $result = $this->field->compactToSparse($full);

        $this->assertSame([
            'md' => ['width' => 6, 'offset' => 2, 'visible' => true],
        ], $result);
    }

    public function testCompactUninheritedButSameValuesNotStored(): void
    {
        $full = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => false],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => false],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => true],
        ];

        $result = $this->field->compactToSparse($full);

        $this->assertSame([], $result);
    }

    public function testCompactVisibilityChange(): void
    {
        $full = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => false],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => false, 'inherit' => false],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => true, 'inherit' => false],
        ];

        $result = $this->field->compactToSparse($full);

        $this->assertSame([
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => false],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => true],
        ], $result);
    }

    public function testRoundTripPreservesSemantics(): void
    {
        $sparse = [
            'xs' => ['width' => 6, 'offset' => 1, 'visible' => true],
            'md' => ['width' => 4, 'offset' => 0, 'visible' => false],
        ];

        $expanded = $this->field->expandFromSparse($sparse);
        $compacted = $this->field->compactToSparse($expanded);

        $this->assertSame($sparse, $compacted);
    }

    public function testRoundTripEmptySparse(): void
    {
        $expanded = $this->field->expandFromSparse([]);
        $compacted = $this->field->compactToSparse($expanded);

        $this->assertSame([], $compacted);
    }

    public function testRoundTripAllViewportsSet(): void
    {
        $sparse = [
            'xs' => ['width' => 8, 'offset' => 2, 'visible' => true],
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => false],
        ];

        $expanded = $this->field->expandFromSparse($sparse);
        $compacted = $this->field->compactToSparse($expanded);

        $this->assertSame($sparse, $compacted);
    }

    public function testRoundTripFirstViewportDefaultsAreOmitted(): void
    {
        // First viewport with default values gets stripped in compact
        $sparse = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => false],
        ];

        $expanded = $this->field->expandFromSparse($sparse);
        $compacted = $this->field->compactToSparse($expanded);

        // xs is omitted because it matches defaults
        $this->assertArrayNotHasKey('xs', $compacted);
        $this->assertSame(6, $compacted['sm']['width']);
        $this->assertSame(4, $compacted['md']['width']);
    }

    public function testExpandVisibilityInheritsCascade(): void
    {
        $sparse = [
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => false],
        ];

        $result = $this->field->expandFromSparse($sparse);

        // xs: defaults (visible)
        $this->assertTrue($result['xs']['visible']);

        // sm: explicitly hidden
        $this->assertFalse($result['sm']['visible']);
        $this->assertFalse($result['sm']['inherit']);

        // md: inherits hidden state from sm
        $this->assertFalse($result['md']['visible']);
        $this->assertTrue($result['md']['inherit']);
    }
}

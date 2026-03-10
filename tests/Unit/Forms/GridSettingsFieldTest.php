<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Forms\GridSettingsField;
use WeDevelop\Grid\Service\GridSettingsCompactor;
use WeDevelop\Grid\Value\Viewport;

/**
 * Unit tests for GridSettingsField expand/compact logic with default-viewport-anchored semantics.
 *
 * Uses `sm` as the default viewport (mid-list) to verify that non-first defaults work correctly.
 * Uses reflection to bypass the FormField constructor (which requires SilverStripe's config manifest).
 */
#[CoversClass(GridSettingsField::class)]
#[CoversClass(GridSettingsCompactor::class)]
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
        $this->adapter->method('getDefaultViewport')->willReturn(new Viewport('sm', 'Small'));

        // Create instance without calling FormField::__construct() (needs config manifest)
        $ref = new \ReflectionClass(GridSettingsField::class);
        /** @var GridSettingsField $field */
        $field = $ref->newInstanceWithoutConstructor();
        $this->field = $field;

        // Inject the adapter and compactor dependencies
        $adapterProp = $ref->getProperty('adapter');
        $adapterProp->setValue($this->field, $this->adapter);

        $compactorProp = $ref->getProperty('compactor');
        $compactorProp->setValue($this->field, new GridSettingsCompactor($this->adapter));
    }

    // --- Expand tests ---

    public function testExpandEmptyJsonProducesAllDefaults(): void
    {
        $result = $this->field->expandFromSparse([]);

        // All viewports get implicit defaults (12, 0, true)
        // Default viewport (sm): override=false (it IS the anchor)
        // Non-default viewports: override=false (they match the default viewport's effective values)
        $this->assertSame([
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
        ], $result);
    }

    public function testExpandDefaultViewportOverride(): void
    {
        // Default viewport (sm) has explicit value different from implicit defaults
        $sparse = [
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
        ];

        $result = $this->field->expandFromSparse($sparse);

        // xs: cascades from implicit defaults (12, 0, true) — differs from sm → override=true
        $this->assertTrue($result['xs']['override']);
        $this->assertSame(12, $result['xs']['width']);

        // sm: default viewport, override is always false
        $this->assertFalse($result['sm']['override']);
        $this->assertSame(6, $result['sm']['width']);
        $this->assertSame(1, $result['sm']['offset']);

        // md: cascades from sm (6, 1, true) — matches default viewport values → override=false
        $this->assertFalse($result['md']['override']);
        $this->assertSame(6, $result['md']['width']);
    }

    public function testExpandNonDefaultViewportOverride(): void
    {
        // md has an explicit override in sparse, different from default viewport's effective values
        $sparse = [
            'md' => ['width' => 4, 'offset' => 2, 'visible' => false],
        ];

        $result = $this->field->expandFromSparse($sparse);

        // xs: implicit defaults (12, 0, true) — matches default sm's effective (12, 0, true) → no override
        $this->assertFalse($result['xs']['override']);

        // sm: cascades from xs (12, 0, true) — default viewport → override=false
        $this->assertFalse($result['sm']['override']);
        $this->assertSame(12, $result['sm']['width']);

        // md: explicit override, differs from sm → override=true
        $this->assertTrue($result['md']['override']);
        $this->assertSame(4, $result['md']['width']);
        $this->assertSame(2, $result['md']['offset']);
        $this->assertFalse($result['md']['visible']);
    }

    public function testExpandCascadeProducesOverrideFlags(): void
    {
        // xs has explicit value that cascades to sm (default viewport)
        // This means sm gets a different value than implicit defaults,
        // and md cascades from sm
        $sparse = [
            'xs' => ['width' => 8, 'offset' => 0, 'visible' => true],
        ];

        $result = $this->field->expandFromSparse($sparse);

        // xs: effective (8, 0, true). sm default is (8, 0, true) via cascade.
        // xs differs from sm's effective? No — sm cascades from xs, so sm=(8,0,true), xs=(8,0,true) → same
        $this->assertFalse($result['xs']['override']);

        // sm: default viewport, cascade from xs → (8, 0, true) → override=false
        $this->assertFalse($result['sm']['override']);
        $this->assertSame(8, $result['sm']['width']);

        // md: cascade from sm → (8, 0, true), matches default → override=false
        $this->assertFalse($result['md']['override']);
        $this->assertSame(8, $result['md']['width']);
    }

    public function testExpandFullSparseWithMixedOverrides(): void
    {
        $sparse = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => false],
        ];

        $result = $this->field->expandFromSparse($sparse);

        // xs: (12, 0, true) differs from default sm (6, 1, true) → override=true
        $this->assertTrue($result['xs']['override']);
        // sm: default viewport → override=false
        $this->assertFalse($result['sm']['override']);
        // md: (4, 2, false) differs from default sm (6, 1, true) → override=true
        $this->assertTrue($result['md']['override']);
    }

    public function testExpandVisibilityCascadeToDefault(): void
    {
        // xs explicitly hidden, cascades to sm (default viewport)
        $sparse = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => false],
        ];

        $result = $this->field->expandFromSparse($sparse);

        // xs: (12, 0, false), default sm effective is (12, 0, false) via cascade
        // xs differs from sm? No, they're the same → override=false
        $this->assertFalse($result['xs']['override']);

        // sm: default viewport, cascades hidden from xs → (12, 0, false) → override=false
        $this->assertFalse($result['sm']['override']);
        $this->assertFalse($result['sm']['visible']);

        // md: cascades from sm → (12, 0, false), matches default → override=false
        $this->assertFalse($result['md']['override']);
        $this->assertFalse($result['md']['visible']);
    }

    // --- Compact tests ---

    public function testCompactAllDefaultsProducesEmptySparse(): void
    {
        $full = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
        ];

        $result = $this->field->compactToSparse($full);

        $this->assertSame([], $result);
    }

    public function testCompactDefaultViewportDiffersFromImplicitDefaults(): void
    {
        // Default viewport sm has values that differ from implicit defaults (12, 0, true)
        // Non-overridden xs and md use default viewport values
        $full = [
            'xs' => ['width' => 6, 'offset' => 1, 'visible' => true, 'override' => false],
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true, 'override' => false],
            'md' => ['width' => 6, 'offset' => 1, 'visible' => true, 'override' => false],
        ];

        $result = $this->field->compactToSparse($full);

        // xs is not overridden → uses default viewport values (6, 1, true)
        // xs effective (6, 1, true) differs from prev (12, 0, true) → stored
        // sm is default viewport → uses own values (6, 1, true)
        // sm effective same as xs effective → not stored
        // md is not overridden → uses default viewport values (6, 1, true)
        // md effective same as sm effective → not stored
        $this->assertSame([
            'xs' => ['width' => 6, 'offset' => 1, 'visible' => true],
        ], $result);
    }

    public function testCompactWithNonDefaultOverride(): void
    {
        // md overrides with different values
        $full = [
            'xs' => ['width' => 6, 'offset' => 0, 'visible' => true, 'override' => false],
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true, 'override' => false],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => true, 'override' => true],
        ];

        $result = $this->field->compactToSparse($full);

        // xs: not overridden → eff=(6,0,true), diff from prev (12,0,true) → stored
        // sm: default → eff=(6,0,true), same as xs → not stored
        // md: overridden → eff=(4,2,true), diff from sm → stored
        $this->assertSame([
            'xs' => ['width' => 6, 'offset' => 0, 'visible' => true],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => true],
        ], $result);
    }

    public function testCompactNonOverriddenBreaksCascade(): void
    {
        // xs overrides with different values, sm is default, md is not overridden
        // md must explicitly store to "break" cascade back to default values
        $full = [
            'xs' => ['width' => 8, 'offset' => 0, 'visible' => true, 'override' => true],
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true, 'override' => false],
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true, 'override' => false],
        ];

        $result = $this->field->compactToSparse($full);

        // xs: overridden → eff=(8,0,true), diff from prev (12,0,true) → stored
        // sm: default → eff=(6,0,true), diff from xs (8,0,true) → stored
        // md: not overridden → eff=(6,0,true), same as sm → not stored
        $this->assertSame([
            'xs' => ['width' => 8, 'offset' => 0, 'visible' => true],
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ], $result);
    }

    public function testCompactVisibilityOverride(): void
    {
        $full = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => false, 'override' => true],
        ];

        $result = $this->field->compactToSparse($full);

        // md: overridden, visible=false differs from sm → stored
        $this->assertSame([
            'md' => ['width' => 12, 'offset' => 0, 'visible' => false],
        ], $result);
    }

    // --- Round-trip tests ---

    public function testRoundTripEmptySparse(): void
    {
        $expanded = $this->field->expandFromSparse([]);
        $compacted = $this->field->compactToSparse($expanded);

        $this->assertSame([], $compacted);
    }

    public function testRoundTripDefaultViewportOnly(): void
    {
        $sparse = [
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
        ];

        $expanded = $this->field->expandFromSparse($sparse);
        $compacted = $this->field->compactToSparse($expanded);

        // After expand: xs=(12,0,true) override=true (differs from sm), sm=(6,1,true), md=(6,1,true)
        // After compact: xs stored (differs from implicit defaults),
        //   sm stored (differs from xs), md not stored (same as sm)
        // The sparse output differs from input but produces identical effective values
        // when read back through mobile-first cascade
        $reExpanded = $this->field->expandFromSparse($compacted);
        $this->assertSame($expanded, $reExpanded);
    }

    public function testRoundTripWithOverrides(): void
    {
        $sparse = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => false],
        ];

        $expanded = $this->field->expandFromSparse($sparse);
        $compacted = $this->field->compactToSparse($expanded);

        // Verify semantic round-trip: expand(compact(expand(sparse))) === expand(sparse)
        $reExpanded = $this->field->expandFromSparse($compacted);
        $this->assertSame($expanded, $reExpanded);
    }

    public function testRoundTripAllMatchingDefaults(): void
    {
        // All viewports at implicit defaults → empty sparse → all defaults again
        $sparse = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => true],
        ];

        $expanded = $this->field->expandFromSparse($sparse);
        $compacted = $this->field->compactToSparse($expanded);

        $this->assertSame([], $compacted);

        // And re-expand produces same result
        $reExpanded = $this->field->expandFromSparse($compacted);
        $this->assertSame($expanded, $reExpanded);
    }

    public function testRoundTripPreservesEffectiveValuesWhenSparseChanges(): void
    {
        // Only xs override in sparse — after round-trip through default-viewport model,
        // the effective values must be preserved even if sparse representation changes
        $sparse = [
            'xs' => ['width' => 8, 'offset' => 0, 'visible' => true],
        ];

        $expanded = $this->field->expandFromSparse($sparse);
        $compacted = $this->field->compactToSparse($expanded);

        // Verify effective values are identical
        $reExpanded = $this->field->expandFromSparse($compacted);
        foreach (['xs', 'sm', 'md'] as $key) {
            $this->assertSame($expanded[$key]['width'], $reExpanded[$key]['width'], "Width mismatch for $key");
            $this->assertSame($expanded[$key]['offset'], $reExpanded[$key]['offset'], "Offset mismatch for $key");
            $this->assertSame($expanded[$key]['visible'], $reExpanded[$key]['visible'], "Visible mismatch for $key");
        }
    }
}

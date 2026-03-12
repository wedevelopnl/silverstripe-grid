<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Service\GridSettingsCompactor;
use WeDevelop\Grid\Value\Viewport;

/**
 * Unit tests for GridSettingsCompactor expand/compact/applyViewportUpdate logic.
 *
 * Uses `sm` as the default viewport (mid-list) to verify that non-first defaults work correctly.
 */
#[CoversClass(GridSettingsCompactor::class)]
final class GridSettingsCompactorTest extends TestCase
{
    private GridAdapterInterface&MockObject $adapter;

    private GridSettingsCompactor $compactor;

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

        $this->compactor = new GridSettingsCompactor($this->adapter);
    }

    // --- Expand tests ---

    public function testExpandEmptyJsonProducesAllDefaults(): void
    {
        $result = $this->compactor->expandFromSparse([]);

        $this->assertSame([
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
            'md' => ['width' => 12, 'offset' => 0, 'visible' => true, 'override' => false],
        ], $result);
    }

    public function testExpandDefaultViewportOverride(): void
    {
        $sparse = [
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
        ];

        $result = $this->compactor->expandFromSparse($sparse);

        $this->assertTrue($result['xs']['override']);
        $this->assertSame(12, $result['xs']['width']);
        $this->assertFalse($result['sm']['override']);
        $this->assertSame(6, $result['sm']['width']);
        $this->assertSame(1, $result['sm']['offset']);
        $this->assertFalse($result['md']['override']);
        $this->assertSame(6, $result['md']['width']);
    }

    public function testExpandNonDefaultViewportOverride(): void
    {
        $sparse = [
            'md' => ['width' => 4, 'offset' => 2, 'visible' => false],
        ];

        $result = $this->compactor->expandFromSparse($sparse);

        $this->assertFalse($result['xs']['override']);
        $this->assertFalse($result['sm']['override']);
        $this->assertSame(12, $result['sm']['width']);
        $this->assertTrue($result['md']['override']);
        $this->assertSame(4, $result['md']['width']);
        $this->assertSame(2, $result['md']['offset']);
        $this->assertFalse($result['md']['visible']);
    }

    public function testExpandCascadeProducesOverrideFlags(): void
    {
        $sparse = [
            'xs' => ['width' => 8, 'offset' => 0, 'visible' => true],
        ];

        $result = $this->compactor->expandFromSparse($sparse);

        $this->assertFalse($result['xs']['override']);
        $this->assertFalse($result['sm']['override']);
        $this->assertSame(8, $result['sm']['width']);
        $this->assertFalse($result['md']['override']);
        $this->assertSame(8, $result['md']['width']);
    }

    public function testExpandVisibilityCascadeToDefault(): void
    {
        $sparse = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => false],
        ];

        $result = $this->compactor->expandFromSparse($sparse);

        $this->assertFalse($result['xs']['override']);
        $this->assertFalse($result['sm']['override']);
        $this->assertFalse($result['sm']['visible']);
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

        $result = $this->compactor->compactToSparse($full);

        $this->assertSame([], $result);
    }

    public function testCompactDefaultViewportDiffersFromImplicitDefaults(): void
    {
        $full = [
            'xs' => ['width' => 6, 'offset' => 1, 'visible' => true, 'override' => false],
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true, 'override' => false],
            'md' => ['width' => 6, 'offset' => 1, 'visible' => true, 'override' => false],
        ];

        $result = $this->compactor->compactToSparse($full);

        $this->assertSame([
            'xs' => ['width' => 6, 'offset' => 1, 'visible' => true],
        ], $result);
    }

    public function testCompactWithNonDefaultOverride(): void
    {
        $full = [
            'xs' => ['width' => 6, 'offset' => 0, 'visible' => true, 'override' => false],
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true, 'override' => false],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => true, 'override' => true],
        ];

        $result = $this->compactor->compactToSparse($full);

        $this->assertSame([
            'xs' => ['width' => 6, 'offset' => 0, 'visible' => true],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => true],
        ], $result);
    }

    public function testCompactNonOverriddenBreaksCascade(): void
    {
        $full = [
            'xs' => ['width' => 8, 'offset' => 0, 'visible' => true, 'override' => true],
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true, 'override' => false],
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true, 'override' => false],
        ];

        $result = $this->compactor->compactToSparse($full);

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

        $result = $this->compactor->compactToSparse($full);

        $this->assertSame([
            'md' => ['width' => 12, 'offset' => 0, 'visible' => false],
        ], $result);
    }

    // --- Round-trip tests ---

    public function testRoundTripEmptySparse(): void
    {
        $expanded = $this->compactor->expandFromSparse([]);
        $compacted = $this->compactor->compactToSparse($expanded);

        $this->assertSame([], $compacted);
    }

    public function testRoundTripDefaultViewportOnly(): void
    {
        $sparse = [
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
        ];

        $expanded = $this->compactor->expandFromSparse($sparse);
        $compacted = $this->compactor->compactToSparse($expanded);
        $reExpanded = $this->compactor->expandFromSparse($compacted);

        $this->assertSame($expanded, $reExpanded);
    }

    public function testRoundTripWithOverrides(): void
    {
        $sparse = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => true],
            'sm' => ['width' => 6, 'offset' => 1, 'visible' => true],
            'md' => ['width' => 4, 'offset' => 2, 'visible' => false],
        ];

        $expanded = $this->compactor->expandFromSparse($sparse);
        $compacted = $this->compactor->compactToSparse($expanded);
        $reExpanded = $this->compactor->expandFromSparse($compacted);

        $this->assertSame($expanded, $reExpanded);
    }

    // --- applyViewportUpdate tests ---

    public function testApplyViewportUpdateDefaultViewportOnEmptySettings(): void
    {
        $result = $this->compactor->applyViewportUpdate(
            [],
            'sm',
            ['width' => 6, 'offset' => 1, 'visible' => true],
        );

        // Expand: all at (12,0,true). Update sm to (6,1,true). Compact.
        // xs effective=(12,0,true) non-overridden → uses default sm values (6,1,true)
        // xs eff=(6,1,true) differs from prev (12,0,true) → stored
        // sm eff=(6,1,true) same as xs → not stored
        // md eff=(6,1,true) same as sm → not stored
        $this->assertSame([
            'xs' => ['width' => 6, 'offset' => 1, 'visible' => true],
        ], $result);
    }

    public function testApplyViewportUpdateNonDefaultOnEmptySettings(): void
    {
        $result = $this->compactor->applyViewportUpdate(
            [],
            'xs',
            ['width' => 6, 'offset' => 0, 'visible' => true],
        );

        // xs override=true, eff=(6,0,true), differs from prev (12,0,true) → stored
        // sm default, eff=(12,0,true) (default values), differs from xs (6,0,true) → stored
        // md non-overridden, eff=(12,0,true), same as sm → not stored
        $this->assertSame([
            'xs' => ['width' => 6, 'offset' => 0, 'visible' => true],
            'sm' => ['width' => 12, 'offset' => 0, 'visible' => true],
        ], $result);
    }

    /**
     * Regression test for the cascade bug: modifying only xs on empty settings
     * must NOT cascade xs width to sm/md. The default viewport (sm) must remain
     * at implicit defaults (width=12).
     */
    public function testApplyViewportUpdateXsOnEmptySettingsDoesNotCascadeToDefault(): void
    {
        $sparse = $this->compactor->applyViewportUpdate(
            [],
            'xs',
            ['width' => 6, 'offset' => 0, 'visible' => true],
        );

        // Verify the sparse result contains a cascade reset at the default viewport
        $this->assertArrayHasKey('sm', $sparse, 'Default viewport must have cascade reset entry');
        $this->assertSame(12, $sparse['sm']['width'], 'Default viewport must stay at full width');

        // Verify round-trip: expanding the sparse should show xs=6, sm=12, md=12
        $expanded = $this->compactor->expandFromSparse($sparse);
        $this->assertSame(6, $expanded['xs']['width']);
        $this->assertSame(12, $expanded['sm']['width']);
        $this->assertSame(12, $expanded['md']['width']);
    }

    public function testApplyViewportUpdateMdOnEmptySettingsDoesNotAffectDefault(): void
    {
        $sparse = $this->compactor->applyViewportUpdate(
            [],
            'md',
            ['width' => 4, 'offset' => 2, 'visible' => false],
        );

        // md override=true, differs from sm → stored
        // xs and sm stay at defaults, no entries needed
        $this->assertSame([
            'md' => ['width' => 4, 'offset' => 2, 'visible' => false],
        ], $sparse);

        $expanded = $this->compactor->expandFromSparse($sparse);
        $this->assertSame(12, $expanded['xs']['width']);
        $this->assertSame(12, $expanded['sm']['width']);
        $this->assertSame(4, $expanded['md']['width']);
    }

    public function testApplyViewportUpdatePreservesExistingOverrides(): void
    {
        $existing = [
            'md' => ['width' => 4, 'offset' => 2, 'visible' => true],
        ];

        $result = $this->compactor->applyViewportUpdate(
            $existing,
            'xs',
            ['width' => 8, 'offset' => 0, 'visible' => true],
        );

        // xs override → stored, sm cascade reset → stored, md override preserved → stored
        $expanded = $this->compactor->expandFromSparse($result);
        $this->assertSame(8, $expanded['xs']['width']);
        $this->assertSame(12, $expanded['sm']['width']);
        $this->assertSame(4, $expanded['md']['width']);
        $this->assertSame(2, $expanded['md']['offset']);
    }

    public function testApplyViewportUpdateResettingToDefaultRemovesEntry(): void
    {
        $existing = [
            'md' => ['width' => 4, 'offset' => 2, 'visible' => true],
        ];

        // Update md back to default values
        $result = $this->compactor->applyViewportUpdate(
            $existing,
            'md',
            ['width' => 12, 'offset' => 0, 'visible' => true],
        );

        // md now matches default → no sparse entries needed
        $this->assertSame([], $result);
    }

    public function testApplyViewportUpdateRoundTrip(): void
    {
        // Apply multiple sequential updates and verify each produces correct cascade
        $sparse = [];

        $sparse = $this->compactor->applyViewportUpdate($sparse, 'xs', ['width' => 6, 'offset' => 0, 'visible' => true]);
        $sparse = $this->compactor->applyViewportUpdate($sparse, 'md', ['width' => 4, 'offset' => 2, 'visible' => false]);

        $expanded = $this->compactor->expandFromSparse($sparse);
        $this->assertSame(6, $expanded['xs']['width']);
        $this->assertSame(12, $expanded['sm']['width']);
        $this->assertSame(4, $expanded['md']['width']);
        $this->assertSame(2, $expanded['md']['offset']);
        $this->assertFalse($expanded['md']['visible']);
    }

    /**
     * Sequential updates: first non-default viewport, then default viewport.
     * All non-overridden viewports must adopt the new default, not the old one.
     */
    public function testSequentialNonDefaultThenDefaultUpdatePropagatesNewDefault(): void
    {
        // Step 1: update xs (non-default) on empty settings
        $sparse = $this->compactor->applyViewportUpdate(
            [],
            'xs',
            ['width' => 6, 'offset' => 0, 'visible' => true],
        );

        // Step 2: update sm (default) to width=8
        $sparse = $this->compactor->applyViewportUpdate(
            $sparse,
            'sm',
            ['width' => 8, 'offset' => 0, 'visible' => true],
        );

        // Assert: xs keeps its override, all others use new default
        $expanded = $this->compactor->expandFromSparse($sparse);
        $this->assertSame(6, $expanded['xs']['width']);
        $this->assertTrue($expanded['xs']['override']);
        $this->assertSame(8, $expanded['sm']['width']);
        $this->assertFalse($expanded['sm']['override']);
        $this->assertSame(8, $expanded['md']['width']);
        $this->assertFalse($expanded['md']['override']);
    }

    /**
     * When a non-default viewport's cascade values match the current default,
     * changing the default must promote that viewport to an override so its
     * original values are preserved — not silently replaced by the new default.
     *
     * Regression: without re-evaluating override flags after updating the default
     * viewport, md stays override=false and compactToSparse uses the new default
     * values for md, losing its original offset=0.
     */
    public function testDefaultUpdatePromotesMatchingNonOverriddenViewportToOverride(): void
    {
        // md's values match sm's — expandFromSparse will mark md as override=false
        $sparse = [
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true],
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ];

        // Change sm (default) offset to 2 — md should keep offset=0
        $result = $this->compactor->applyViewportUpdate(
            $sparse,
            'sm',
            ['width' => 6, 'offset' => 2, 'visible' => true],
        );

        $expanded = $this->compactor->expandFromSparse($result);

        $this->assertSame(6, $expanded['sm']['width']);
        $this->assertSame(2, $expanded['sm']['offset']);
        $this->assertSame(6, $expanded['md']['width']);
        $this->assertSame(0, $expanded['md']['offset'], 'md must preserve original offset, not inherit new default');
        $this->assertTrue($expanded['md']['override'], 'md must be promoted to override');
    }

    /**
     * Same scenario with visibility: a non-default viewport is visible and matches
     * the current default, then the default is changed to hidden. The non-default
     * viewport must remain visible.
     */
    public function testDefaultUpdatePromotesViewportWhenVisibilityChanges(): void
    {
        $sparse = [
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true],
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ];

        // Change sm (default) to hidden — md should stay visible
        $result = $this->compactor->applyViewportUpdate(
            $sparse,
            'sm',
            ['width' => 6, 'offset' => 0, 'visible' => false],
        );

        $expanded = $this->compactor->expandFromSparse($result);

        $this->assertFalse($expanded['sm']['visible']);
        $this->assertTrue($expanded['md']['visible'], 'md must stay visible, not inherit hidden from new default');
        $this->assertTrue($expanded['md']['override']);
    }

    /**
     * When the default viewport changes and a non-default viewport had an explicit
     * sparse entry, its values are preserved even when the new default differs.
     */
    public function testDefaultUpdatePreservesExplicitSparseEntryWidth(): void
    {
        // md matches sm at (6, 0, true) — both have explicit sparse entries
        $sparse = [
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true],
            'md' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ];

        // Change sm width to 8 — md had explicit width=6, must keep it
        $result = $this->compactor->applyViewportUpdate(
            $sparse,
            'sm',
            ['width' => 8, 'offset' => 0, 'visible' => true],
        );

        $expanded = $this->compactor->expandFromSparse($result);

        $this->assertSame(8, $expanded['sm']['width']);
        $this->assertSame(6, $expanded['md']['width']);
        $this->assertTrue($expanded['md']['override']);
    }

    /**
     * When updating a below-default viewport, non-sparse viewports between the
     * target and the next sparse entry must re-cascade from the updated values.
     *
     * Regression: without re-cascade, intermediate viewports retain stale values
     * from the old expansion. E.g., changing xs from hidden→visible should also
     * make sm visible (via cascade), but sm's stale visible=false persisted.
     *
     * Uses a 4-viewport adapter (xs, sm, md[default], lg) to model the scenario.
     */
    public function testUpdateBelowDefaultReCascadesIntermediateViewports(): void
    {
        $adapter = $this->createMock(GridAdapterInterface::class);
        $adapter->method('getViewports')->willReturn([
            new Viewport('xs', 'Extra Small'),
            new Viewport('sm', 'Small'),
            new Viewport('md', 'Medium'),
            new Viewport('lg', 'Large'),
        ]);
        $adapter->method('getColumnCount')->willReturn(12);
        $adapter->method('getDefaultViewport')->willReturn(new Viewport('md', 'Medium'));

        $compactor = new GridSettingsCompactor($adapter);

        // xs=hidden, md has width=4, lg has width=6
        // sm has NO explicit entry — it cascades from xs (hidden)
        $sparse = [
            'xs' => ['width' => 12, 'offset' => 0, 'visible' => false],
            'md' => ['width' => 4, 'offset' => 0, 'visible' => true],
            'lg' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ];

        // Unhide xs — sm should inherit visible=true via re-cascade
        $result = $compactor->applyViewportUpdate(
            $sparse,
            'xs',
            ['width' => 12, 'offset' => 0, 'visible' => true],
        );

        $expanded = $compactor->expandFromSparse($result);

        $this->assertTrue($expanded['xs']['visible']);
        $this->assertTrue($expanded['sm']['visible'], 'sm must inherit visible=true from updated xs');
        $this->assertTrue($expanded['md']['visible']);
    }

    /**
     * Viewports without explicit sparse entries should follow the new default
     * when the default viewport is updated — they were never user-customized.
     */
    public function testDefaultUpdateLetsImplicitViewportsFollowNewDefault(): void
    {
        // Only sm has an explicit entry; md inherits via cascade
        $sparse = [
            'sm' => ['width' => 6, 'offset' => 0, 'visible' => true],
        ];

        // Change sm width to 8 — md was never set, should follow
        $result = $this->compactor->applyViewportUpdate(
            $sparse,
            'sm',
            ['width' => 8, 'offset' => 0, 'visible' => true],
        );

        $expanded = $this->compactor->expandFromSparse($result);

        $this->assertSame(8, $expanded['sm']['width']);
        $this->assertSame(8, $expanded['md']['width']);
        $this->assertFalse($expanded['md']['override']);
    }
}

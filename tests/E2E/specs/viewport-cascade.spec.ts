import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

/**
 * Viewport cascade / override behaviour — user journey.
 *
 * Fixture `viewport-cascade` stores a single column with:
 *   default  = { width: 12, offset: 0, visible: true }
 *   md       = { width: 6,  offset: 0, visible: true }
 *
 * The spec covers two surfaces:
 *
 *  1. Editor: switching the viewport picker updates the column width
 *     badge. We assert the badge shows the default at non-`md` viewports
 *     and the override at `md`.
 *
 *  2. Frontend: publishing the page causes the server to emit Bootstrap
 *     classes for the resolved settings. We assert the published column
 *     carries the expected `col-*` class chain.
 *
 * ---------------------------------------------------------------------
 * DEVIATION FROM TASK 27 AS WRITTEN
 * ---------------------------------------------------------------------
 * Task 27 asks the spec to demonstrate that an `md` override "cascades
 * forward to lg" under the cascade strategy. Two problems with running
 * this through the real E2E surface:
 *
 *  a. Cascade actually walks largest → smallest, not smallest → largest.
 *     See `GridSettingsResolver::resolveCascade()` and the test case
 *     "override at md cascades down only" in
 *     `tests/Unit/Service/GridSettingsResolverTest.php`. An `md` override
 *     therefore applies from `md` downwards (md, sm, xs), not upwards.
 *
 *  b. The override strategy is a global, compile-time DI constructor
 *     argument on `GridSettingsResolver` (see `_config/grid.yml`). It
 *     cannot be toggled per page, per column, per request, or per spec
 *     — flipping it to `cascade` would also change the expected class
 *     chain asserted by `viewport-switch.spec.ts`. Additionally, the
 *     frontend editor uses a separate JS helper
 *     (`resolveViewportSettings` in `client/src/utils/gridAdapter.ts`)
 *     that implements *isolated* semantics only, so cascade behaviour
 *     is not observable through the editor badges regardless.
 *
 * Cascade semantics at the service layer are exhaustively covered by
 * `GridSettingsResolverTest` (unit). This E2E spec therefore focuses on
 * the user-observable, runtime-default *isolated* resolution of the same
 * fixture data — verifying that a single-viewport override round-trips
 * through the editor UI and the published frontend markup.
 */
// Asserts md as the default viewport, Bootstrap labels ("Medium"/"Small"/
// "Large"), and the col-* class chain emitted by the Bootstrap adapter. Port
// to an adapter-agnostic variant before dropping the @bootstrap-only tag.
test.describe('Viewport cascade / override rendering', { tag: '@bootstrap-only' }, () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('editor badges and published frontend reflect the stored default + md override', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'viewport-cascade');

    await test.step('navigate to the page editor', async () => {
      await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });
    });

    const column = page.getByTestId('column-block').filter({ hasText: 'Cascade Column' });
    const badge = column.getByTestId('column-badge');

    await test.step('default viewport (md) shows the md override width', async () => {
      // The Bootstrap adapter's default viewport is `md`. On first load
      // the badge should reflect the stored md override width (6/12).
      const mediumButton = page.getByRole('button', { name: 'Medium', exact: true });
      await expect(mediumButton).toHaveAttribute('aria-pressed', 'true');
      await expect(badge).toHaveText('6/12');
    });

    await test.step('switching to sm (no override) falls back to the default width', async () => {
      await page.getByRole('button', { name: 'Small', exact: true }).click();
      await expect(badge).toHaveText('12/12');
    });

    await test.step('switching to lg (no override) also falls back to the default width', async () => {
      // Under isolated resolution — the project default — an override
      // at md does *not* carry into lg; lg uses the default width.
      await page.getByRole('button', { name: 'Large', exact: true }).click();
      await expect(badge).toHaveText('12/12');
    });

    await test.step('switching back to md shows the override width again', async () => {
      await page.getByRole('button', { name: 'Medium', exact: true }).click();
      await expect(badge).toHaveText('6/12');
    });

    await test.step('published frontend renders the expected Bootstrap class chain', async () => {
      await page.getByRole('button', { name: /Publish/ }).click();
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({
        timeout: 10_000,
      });

      const livePath = fixture.pageUrl.split('?')[0];
      await page.goto(livePath);
      await expect(page.locator('h1')).toContainText('E2E Viewport Cascade Test Page');

      // Under isolated resolution the effective map is
      //   xs=12, sm=12, md=6, lg=12, xl=12, xxl=12
      // ColumnClassResolver walks smallest → largest and only emits a
      // class when the effective width changes:
      //   xs → col-12        (first viewport always emits)
      //   sm → (same as xs)  — no class
      //   md → col-md-6      (width changes from 12 → 6)
      //   lg → col-lg-12     (width changes back from 6 → 12)
      //   xl → (same as lg)  — no class
      //   xxl → (same as xl) — no class
      const frontendColumn = page.locator('div[data-element="column"]').first();
      const classes = (await frontendColumn.getAttribute('class')) ?? '';
      const classList = classes.split(/\s+/).filter(Boolean).sort();
      expect(classList).toEqual(
        ['col-12', 'col-lg-12', 'col-md-6'].sort(),
      );
    });
  });
});

import { expect, test } from '@playwright/test';
import { loadAndNavigate, resetFixtures } from '../helpers/fixtures';
import { forcePreviewViewMode, forceSplitViewMode } from '../helpers/preview';

/**
 * Editor tunes a responsive layout across framework breakpoints with the
 * CMS preview bar synced to the in-editor ViewportSwitcher.
 *
 * Clicking a viewport in either surface updates the other AND actually
 * resizes the preview iframe — proving the store → React consumer path
 * and the vendor `changeSize` piggyback both wire up end-to-end.
 */

// Split mode requires a minimum viewport width — the CMS applies a
// `split-disabled` guard on narrower screens. Override Playwright's default.
test.use({ viewport: { width: 1600, height: 900 } });

test.describe('CMS preview viewport sync', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('editor tunes responsive layout across breakpoints and preview rescales', async ({
    page,
  }) => {
    await loadAndNavigate(page, 'element-tree');
    await forceSplitViewMode(page);

    const editorSwitcher = page.getByTestId('viewport-switcher');
    const cmsSelector = page.getByTestId('cms-preview-viewport-selector');
    const leftBadge = page
      .getByTestId('column-block')
      .first()
      .getByTestId('column-badge');
    const rightBadge = page
      .getByTestId('column-block')
      .nth(1)
      .getByTestId('column-badge');

    // Helper: read the rendered dimensions of the preview iframe. The
    // iframe fills its `.preview-device-outer` wrapper which our bridge
    // sizes via the injected stylesheet — assert on the actual rendered
    // box so we catch any regression where styles inject but don't apply.
    const deviceSize = () =>
      page.evaluate(() => {
        const iframe = document.querySelector<HTMLIFrameElement>(
          'iframe[name="cms-preview-iframe"]',
        );
        if (iframe === null) return null;
        const rect = iframe.getBoundingClientRect();
        return { width: Math.round(rect.width), height: Math.round(rect.height) };
      });

    await test.step('split mode renders both viewport selectors at the adapter default (md)', async () => {
      await expect(editorSwitcher).toBeVisible();
      await expect(cmsSelector).toBeVisible({ timeout: 15_000 });

      await expect(
        editorSwitcher.getByRole('button', { name: 'Medium', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect(
        cmsSelector.getByRole('button', { name: 'Medium', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');

      // md layout from fixture: col1 = 8/12, col2 = 4/12.
      await expect(leftBadge).toHaveText('8/12');
      await expect(rightBadge).toHaveText('4/12');

      // Initial resize — preview iframe narrows to Bootstrap md.
      // Height comes from min(900, max(500, round(768 * 0.75))) = 576.
      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual({ width: 768, height: 576 });
    });

    await test.step('switching viewport in the editor drives the CMS bar, flips column overrides, and rescales preview', async () => {
      await editorSwitcher
        .getByRole('button', { name: 'Extra Small', exact: true })
        .click();

      await expect(
        cmsSelector.getByRole('button', { name: 'Extra Small', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect(
        cmsSelector.getByRole('button', { name: 'Medium', exact: true }),
      ).toHaveAttribute('aria-pressed', 'false');

      // Editor column badges switch to the xs overrides from the fixture:
      // col1 → 12/12, col2 → hidden.
      await expect(leftBadge).toHaveText('12/12');
      await expect(rightBadge).toHaveText('hidden');

      // Preview iframe narrows to the mobile-first fallback width (375px)
      // because Bootstrap xs has minWidth: 0. Height is clamped to the
      // 500px floor of the monotonic formula.
      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual({ width: 375, height: 500 });
    });

    await test.step('switching viewport in the CMS bar drives the editor and rescales preview', async () => {
      await cmsSelector
        .getByRole('button', { name: 'Large', exact: true })
        .click();

      await expect(
        editorSwitcher.getByRole('button', { name: 'Large', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect(
        editorSwitcher.getByRole('button', { name: 'Extra Small', exact: true }),
      ).toHaveAttribute('aria-pressed', 'false');

      // Column badges flip to the lg overrides: col1 = 6/12, col2 = 6/12.
      await expect(leftBadge).toHaveText('6/12');
      await expect(rightBadge).toHaveText('6/12');

      // Preview iframe widens to Bootstrap lg. Height: 992 * 0.75 = 744.
      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual({ width: 992, height: 744 });
    });

    await test.step('surviving a content-area swap remounts the selector and keeps bi-directional sync working', async () => {
      // Simulate a CMS action that swaps the content area (e.g. save or
      // publish Pjax). A full reload is the strongest version of that —
      // if the bridge survives this, it survives every lighter DOM
      // churn SilverStripe might throw at it. Without the remount fix
      // the MutationObserver would have been disconnected by the prior
      // attemptUnmount and the selector would never reappear.
      await page.reload({ waitUntil: 'load' });
      await forceSplitViewMode(page);

      // Selector must remount on the fresh DOM.
      await expect(cmsSelector).toBeVisible({ timeout: 15_000 });

      // Sync still works end-to-end after the remount — switch viewport
      // via the CMS bar and confirm the editor and iframe dimensions
      // follow through the freshly-mounted React root.
      await cmsSelector
        .getByRole('button', { name: 'Small', exact: true })
        .click();
      await expect(
        editorSwitcher.getByRole('button', { name: 'Small', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual({ width: 576, height: 500 });
    });
  });

  test('viewport selector remains functional in preview-only mode', async ({ page }) => {
    // In preview-only mode the CMS hides the content panel entirely, so
    // the in-editor ViewportSwitcher isn't reachable — the user only has
    // the CMS bar selector. This journey proves our selector is the
    // single control surface that still works, and that toggling back to
    // split mode preserves the user's last-picked viewport.
    await loadAndNavigate(page, 'element-tree');
    await forceSplitViewMode(page);

    const cmsSelector = page.getByTestId('cms-preview-viewport-selector');
    const editorSwitcher = page.getByTestId('viewport-switcher');
    const deviceSize = () =>
      page.evaluate(() => {
        const iframe = document.querySelector<HTMLIFrameElement>(
          'iframe[name="cms-preview-iframe"]',
        );
        if (iframe === null) return null;
        const rect = iframe.getBoundingClientRect();
        return { width: Math.round(rect.width), height: Math.round(rect.height) };
      });

    await test.step('baseline in split mode — both surfaces present at md default', async () => {
      await expect(cmsSelector).toBeVisible({ timeout: 15_000 });
      await expect(editorSwitcher).toBeVisible();
      await expect(
        cmsSelector.getByRole('button', { name: 'Medium', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
    });

    await test.step('switching to preview-only mode hides the editor but keeps the CMS bar selector functional', async () => {
      await forcePreviewViewMode(page);

      // Editor is no longer visible — users have only the CMS bar selector.
      await expect(editorSwitcher).toBeHidden();
      await expect(cmsSelector).toBeVisible();

      // Clicking a viewport in the CMS bar resizes the preview.
      await cmsSelector.getByRole('button', { name: 'Small', exact: true }).click();
      await expect(
        cmsSelector.getByRole('button', { name: 'Small', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual({ width: 576, height: 500 });
    });

    await test.step('switching back to split mode preserves the active viewport on both surfaces', async () => {
      await forceSplitViewMode(page);

      await expect(editorSwitcher).toBeVisible();
      await expect(
        editorSwitcher.getByRole('button', { name: 'Small', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect(
        cmsSelector.getByRole('button', { name: 'Small', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect
        .poll(deviceSize, { timeout: 5_000 })
        .toEqual({ width: 576, height: 500 });
    });
  });
});

import { expect, test } from '@playwright/test';
import { loadAndNavigate, resetFixtures } from '../helpers/fixtures';
import { forceSplitViewMode } from '../helpers/preview';

/**
 * Editor tunes a responsive layout across framework breakpoints with the
 * CMS preview bar synced to the in-editor ViewportSwitcher.
 *
 * Clicking a viewport in either surface updates the other and also flips
 * the editor's column settings to that viewport's overrides — proving the
 * store → React consumer path is wired end-to-end, not just that two
 * button groups happen to toggle `aria-pressed` together.
 */
// Split mode requires a minimum viewport width — the CMS applies a
// `split-disabled` guard on narrower screens. Override Playwright's default
// 1280x720 so the ViewModeToggle actually offers Split mode.
test.use({ viewport: { width: 1600, height: 900 } });

test.describe('CMS preview viewport sync', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('editor tunes responsive layout across breakpoints via synced CMS preview bar', async ({
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

    await test.step('split mode renders both viewport selectors at the adapter default', async () => {
      // Editor switcher is present as soon as the grid loads.
      await expect(editorSwitcher).toBeVisible();
      // CMS bar selector appears only after the bridge's MutationObserver
      // sees the vendor #preview-size-dropdown mount.
      await expect(cmsSelector).toBeVisible({ timeout: 15_000 });

      // Both sides default to the adapter's default viewport (md).
      await expect(
        editorSwitcher.getByRole('button', { name: 'Medium', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect(
        cmsSelector.getByRole('button', { name: 'Medium', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');

      // md layout from fixture: col1 = 8/12, col2 = 4/12.
      await expect(leftBadge).toHaveText('8/12');
      await expect(rightBadge).toHaveText('4/12');
    });

    await test.step('switching viewport in the editor drives the CMS bar and flips column overrides', async () => {
      await editorSwitcher
        .getByRole('button', { name: 'Extra Small', exact: true })
        .click();

      // CMS bar reflects the same viewport.
      await expect(
        cmsSelector.getByRole('button', { name: 'Extra Small', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect(
        cmsSelector.getByRole('button', { name: 'Medium', exact: true }),
      ).toHaveAttribute('aria-pressed', 'false');

      // Editor column badges switch to the xs overrides from the fixture:
      // col1 → 12/12 (full width on mobile), col2 → hidden.
      await expect(leftBadge).toHaveText('12/12');
      await expect(rightBadge).toHaveText('hidden');
    });

    await test.step('switching viewport in the CMS bar drives the editor', async () => {
      await cmsSelector
        .getByRole('button', { name: 'Large', exact: true })
        .click();

      // Editor follows.
      await expect(
        editorSwitcher.getByRole('button', { name: 'Large', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true');
      await expect(
        editorSwitcher.getByRole('button', { name: 'Extra Small', exact: true }),
      ).toHaveAttribute('aria-pressed', 'false');

      // Column badges flip to the lg overrides: col1 = 6/12, col2 = 6/12.
      await expect(leftBadge).toHaveText('6/12');
      await expect(rightBadge).toHaveText('6/12');
    });
  });
});

import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Enable CMS split mode and wait for the preview iframe to load a real page.
 *
 * In a fresh browser session the CMS defaults to content-only mode. This
 * helper sets the split-mode preference in localStorage (where the CMS
 * persists it) and reloads the page so the CMS initialises in split mode.
 *
 * Call once after loadAndNavigate, before any preview refresh assertions.
 */
export async function enablePreviewMode(page: Page): Promise<void> {
  // The CMS preview reads mode from localStorage on initialisation
  await page.evaluate(() => {
    window.localStorage.setItem('cms-preview-state-mode', 'split');
  });

  // Reload so the CMS picks up the stored mode
  await page.reload({ waitUntil: 'load' });
  await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

  // Wait for the preview iframe to load a real page (not about:blank)
  const previewIframe = page.frameLocator('iframe[name="cms-preview-iframe"]');
  await expect(previewIframe.locator('body')).toBeAttached({ timeout: 15_000 });
}

/**
 * Strictly force the CMS into split view mode (preview panel visible).
 *
 * Triggers a `change` on the native `<select id="preview-mode-dropdown-in-content-select">`
 * so the entwine handler calls `.cms-preview`.changeMode internally. Works
 * regardless of whether the CMS initialised in content or preview mode.
 *
 * Requires a viewport wide enough for the CMS to offer Split mode (the CMS
 * adds a `split-disabled` class on narrower screens). Tests needing this
 * helper must call `test.use({ viewport: { width: 1600, height: 900 } })`
 * or similar.
 */
export async function forceSplitViewMode(page: Page): Promise<void> {
  await page
    .locator('#preview-mode-dropdown-in-content-select')
    .waitFor({ state: 'attached', timeout: 15_000 });

  await page.evaluate(() => {
    const select = document.querySelector<HTMLSelectElement>(
      '#preview-mode-dropdown-in-content-select',
    );
    if (select === null) {
      throw new Error('preview-mode-dropdown-in-content-select not found');
    }
    const option = select.querySelector<HTMLOptionElement>('option.font-icon-columns');
    if (option === null) {
      throw new Error('Split mode option not found in mode dropdown');
    }
    select.value = option.value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
  });

  await expect(page.locator('.cms-container--split-mode')).toHaveCount(1, { timeout: 15_000 });
  await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

  const previewIframe = page.frameLocator('iframe[name="cms-preview-iframe"]');
  await expect(previewIframe.locator('body')).toBeAttached({ timeout: 15_000 });
}

/**
 * Register a listener for the preview iframe to reload.
 * Must be called BEFORE the action that triggers the mutation.
 *
 * Returns a promise that resolves when the preview iframe navigates.
 *
 * Usage:
 *   const previewRefresh = waitForPreviewRefresh(page);
 *   // ... perform mutation ...
 *   await previewRefresh;
 */
export function waitForPreviewRefresh(page: Page): Promise<unknown> {
  return page.waitForEvent('framenavigated', {
    predicate: (frame) => frame.name() === 'cms-preview-iframe',
    timeout: 10_000,
  });
}

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

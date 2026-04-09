import type { Page } from '@playwright/test';

/**
 * Sets the value of a SilverStripe Chosen.js-wrapped <select> field.
 *
 * Chosen.js hides the native <select> via `display:none` and replaces it
 * with a custom widget. That breaks Playwright's `selectOption` visibility
 * check: the action resolves the locator to the hidden native <select>
 * and times out waiting for it to become visible. The failure is flaky
 * because Chosen's hide runs async after jQuery ready — Playwright either
 * races ahead of the hide (success) or behind it (30s timeout).
 *
 * This helper bypasses the race by driving jQuery directly so the native
 * select's value and the Chosen widget's label both update deterministically,
 * independent of Chosen's init timing.
 *
 * @param page - Playwright Page instance
 * @param name - `name` attribute of the underlying <select>
 * @param value - option value (not label)
 */
export async function selectChosenValue(
  page: Page,
  name: string,
  value: string,
): Promise<void> {
  await page.evaluate(
    ({ name, value }) => {
      jQuery(`select[name="${name}"]`).val(value).trigger('change').trigger('chosen:updated');
    },
    { name, value },
  );
}

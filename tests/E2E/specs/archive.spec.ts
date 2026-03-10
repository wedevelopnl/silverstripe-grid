import { test, expect } from '@playwright/test';
import { resetFixtures, loadAndNavigate } from '../helpers/fixtures';

test.describe('Archive element actions', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('content editor archives elements at different hierarchy levels', async ({ page }) => {
    const fixture = await loadAndNavigate(page, 'archive-test');

    await test.step('Archive a content element from Column A1a', async () => {
      const columnA1a = page.getByTestId('column-block').filter({ hasText: 'Column A1a' });
      const elementX = columnA1a.getByTestId('element-card').filter({ hasText: 'Content Element X' });

      // Open the actions menu on the element card
      await elementX.getByTestId('actions-menu-trigger').click();
      await page.getByRole('menuitem', { name: 'Archive' }).click();

      // Confirm the archive dialog — scope to the one that's open
      const dialog = page.locator('dialog[open][data-testid="confirm-dialog"]');
      await expect(dialog).toBeVisible();
      await expect(dialog).toContainText('Archive "Content Element X"?');

      await dialog.getByRole('button', { name: 'Archive' }).click();

      // Verify element is gone (Playwright auto-retries until assertion passes)
      await expect(
        columnA1a.getByTestId('element-card').filter({ hasText: 'Content Element X' }),
      ).toHaveCount(0, { timeout: 10_000 });
    });

    await test.step('Archive Column A1b', async () => {
      const rowA1 = page.getByTestId('row-block').filter({ hasText: 'Row A1' });

      // Find Column A1b's actions menu in the column header
      const columnA1bHeader = rowA1.getByTestId('column-header').filter({ hasText: 'Column A1b' });
      await columnA1bHeader.getByTestId('actions-menu-trigger').click();
      await page.getByRole('menuitem', { name: 'Archive' }).click();

      const dialog = page.locator('dialog[open][data-testid="confirm-dialog"]');
      await expect(dialog).toBeVisible();

      await dialog.getByRole('button', { name: 'Archive' }).click();

      // Verify Column A1b is gone from Row A1
      await expect(
        rowA1.getByTestId('column-block').filter({ hasText: 'Column A1b' }),
      ).toHaveCount(0, { timeout: 10_000 });
    });

    await test.step('Archive entire Section A', async () => {
      const sectionA = page.getByTestId('section-block').filter({ hasText: 'Section A' });
      // Use the section header's actions menu (first trigger within the section)
      await sectionA.getByTestId('section-header').getByTestId('actions-menu-trigger').click();
      await page.getByRole('menuitem', { name: 'Archive' }).click();

      const dialog = page.locator('dialog[open][data-testid="confirm-dialog"]');
      await expect(dialog).toBeVisible();
      // Section A should mention child elements in the confirmation
      await expect(dialog).toContainText('child element');

      await dialog.getByRole('button', { name: 'Archive' }).click();

      // Verify Section A is gone
      await expect(
        page.getByTestId('section-block').filter({ hasText: 'Section A' }),
      ).toHaveCount(0, { timeout: 10_000 });
    });

    await test.step('Reload and verify persistence', async () => {
      await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

      // Only Section B should remain
      await expect(page.getByTestId('section-block')).toHaveCount(1);
      await expect(page.getByTestId('section-block')).toContainText('Section B');

      // Content Element Y should still be present in Section B
      await expect(page.getByTestId('element-card').filter({ hasText: 'Content Element Y' })).toHaveCount(1);
    });
  });
});

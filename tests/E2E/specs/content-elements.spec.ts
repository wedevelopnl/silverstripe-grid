import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

test.describe('Content elements', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('content editor adds elements to columns and navigates to edit page', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'content-elements');
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    // --- Step 1: Verify populated column shows existing elements ---
    const elementCards = page.getByTestId('element-card');
    await expect(elementCards).toHaveCount(2);
    await expect(page.getByText('Existing Text Block')).toBeVisible();
    await expect(page.getByText('Existing Image Block')).toBeVisible();

    // --- Step 2: Add element to empty column ---
    const addButtons = page.getByTestId('add-content-button');
    // Both columns should have an add button (one empty, one populated)
    await expect(addButtons).toHaveCount(2);

    // Click the first add button (empty column)
    await addButtons.first().click();

    // Modal should open
    const picker = page.getByTestId('element-type-picker');
    await expect(picker).toBeVisible();

    // At least one type tile should be available
    const tiles = page.getByTestId('element-type-tile');
    await expect(tiles.first()).toBeVisible();

    // Select the first type
    await tiles.first().click();

    // Modal should close
    await expect(picker).toBeHidden();

    // Wait for tree to reload — empty column now has an element
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });
    await expect(elementCards).toHaveCount(3);

    // --- Step 3: Dismiss modal without creating ---
    await addButtons.first().click();
    await expect(picker).toBeVisible();

    // Press Escape to close
    await page.keyboard.press('Escape');
    await expect(picker).toBeHidden();

    // No new element created
    await expect(elementCards).toHaveCount(3);

    // --- Step 4: Click element card to navigate to edit page ---
    const firstCard = elementCards.first();
    await firstCard.click();

    // Should navigate to the ModelAdmin edit page
    await expect(page).toHaveURL(/grid-elements/);
  });
});

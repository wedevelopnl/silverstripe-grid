import { expect, test } from '@playwright/test';
import { resetFixtures } from '../helpers/fixtures';

test.describe('Build page from scratch', () => {
  // The page title "E2E Build Test" generates URL segment "e2e-build-test",
  // which resetFixtures identifies and deletes by the "e2e-" prefix.
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('editor creates a new page, builds a grid structure, and publishes to frontend', async ({
    page,
  }) => {
    // Step 1: Navigate to Pages admin and wait for the site tree to load
    await page.goto('/admin/pages');
    await expect(page.getByRole('link', { name: 'Add new Page' })).toBeVisible({ timeout: 15_000 });
    await page.getByRole('link', { name: 'Add new Page' }).click();

    // The wizard appears: Step 1 (location) defaults to "Top level",
    // Step 2 (type) defaults to "Page". Click "Create" to proceed.
    await expect(page.getByRole('button', { name: 'Create' })).toBeVisible({ timeout: 10_000 });
    await page.getByRole('button', { name: 'Create' }).click();

    // Wait for the page editor to load, then enter a title
    await expect(page).toHaveURL(/\/admin\/pages\/edit\/show\/\d+/, { timeout: 10_000 });
    await expect(page.getByRole('textbox', { name: 'Page name' })).toBeVisible({ timeout: 10_000 });
    await page.getByRole('textbox', { name: 'Page name' }).fill('E2E Build Test');

    // Save the page to persist the title
    await page.getByRole('button', { name: /Save/ }).first().click();
    await expect(
      page.getByTestId('grid-editor-loading'),
    ).toBeHidden({ timeout: 15_000 });

    // Step 2: Empty state — "Add Section" button visible, no section blocks
    await expect(page.getByTestId('add-child-empty')).toBeVisible();
    await expect(page.getByTestId('section-block')).toHaveCount(0);
    await expect(
      page.getByTestId('add-child-button').filter({ hasText: 'Add Section' }),
    ).toBeVisible();

    // Step 3: Add first section
    await page.getByTestId('add-child-button').filter({ hasText: 'Add Section' }).click();
    await expect(page.getByTestId('section-block').first()).toBeVisible({ timeout: 10_000 });

    // Auto-scaffolding creates Section → Row → Column
    await expect(page.getByTestId('section-block')).toHaveCount(1);
    await expect(page.getByTestId('row-block')).toHaveCount(1);
    await expect(page.getByTestId('column-block')).toHaveCount(1);

    // Section-level empty state is gone, append button visible
    await expect(page.getByTestId('add-child-empty').filter({ hasText: 'No sections yet' })).toHaveCount(0);
    await expect(
      page.getByTestId('add-child-append').filter({ hasText: 'Add Section' }),
    ).toBeVisible();

    // Step 4: Add a second section via append button
    await page.getByTestId('add-child-append').filter({ hasText: 'Add Section' }).click();
    await expect(page.getByTestId('section-block')).toHaveCount(2, { timeout: 10_000 });
    await expect(page.getByTestId('row-block')).toHaveCount(2);
    await expect(page.getByTestId('column-block')).toHaveCount(2);

    // Step 5: Add a second row to the first section
    const firstSection = page.getByTestId('section-block').first();
    await firstSection.getByTestId('add-child-append').filter({ hasText: 'Add Row' }).click();
    await expect(firstSection.getByTestId('row-block')).toHaveCount(2, { timeout: 10_000 });
    await expect(page.getByTestId('column-block')).toHaveCount(3);

    // Step 6: Add a second column to the first row of the first section
    const firstRow = firstSection.getByTestId('row-block').first();
    await firstRow.getByTestId('add-child-append').filter({ hasText: 'Add Column' }).click();
    await expect(firstRow.getByTestId('column-block')).toHaveCount(2, { timeout: 10_000 });

    // Verify final grid state: 2 sections, 3 rows, 4 columns
    await expect(page.getByTestId('section-block')).toHaveCount(2);
    await expect(page.getByTestId('row-block')).toHaveCount(3);
    await expect(page.getByTestId('column-block')).toHaveCount(4);

    // Step 7: Publish the page
    await page.getByRole('button', { name: /Publish/ }).click();
    await expect(
      page.getByRole('button', { name: /Published/ }),
    ).toBeVisible({ timeout: 10_000 });

    // Step 8: Verify the published page renders on the frontend
    await page.goto('/e2e-build-test');
    await expect(page.locator('h1')).toContainText('E2E Build Test');

    // Both sections should render as <section> elements on the frontend
    await expect(page.locator('section.element')).toHaveCount(2);
  });
});

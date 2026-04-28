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

    // Step 2: Empty page — no sections, just the "Add Section" empty state
    await expect(page.getByTestId('section-block')).toHaveCount(0);
    await expect(page.getByTestId('add-child-empty').filter({ hasText: 'Add Section' })).toBeVisible();

    // Step 3: Add first section — verify it arrives with auto-scaffolded children
    await page.getByTestId('add-child-button').filter({ hasText: 'Add Section' }).click();

    const firstSection = page.getByTestId('section-block').first();
    await expect(firstSection).toBeVisible({ timeout: 10_000 });
    await expect(firstSection.getByTestId('row-block')).toHaveCount(1);
    await expect(firstSection.getByTestId('column-block')).toHaveCount(1);

    // The section-level empty state is replaced by an append button
    await expect(page.getByTestId('add-child-empty').filter({ hasText: 'Add Section' })).toHaveCount(0);
    await expect(page.getByTestId('add-child-append').filter({ hasText: 'Add Section' })).toBeVisible();

    // Step 4: Add a second section — verify it also has its own scaffolded row + column
    await page.getByTestId('add-child-append').filter({ hasText: 'Add Section' }).click();
    await expect(page.getByTestId('section-block')).toHaveCount(2, { timeout: 10_000 });

    const secondSection = page.getByTestId('section-block').nth(1);
    await expect(secondSection.getByTestId('row-block')).toHaveCount(1);
    await expect(secondSection.getByTestId('column-block')).toHaveCount(1);

    // Step 5: Add a second row to the first section — verify it has a scaffolded column
    await firstSection.getByTestId('add-child-append').filter({ hasText: 'Add Row' }).click();
    await expect(firstSection.getByTestId('row-block')).toHaveCount(2, { timeout: 10_000 });

    const newRow = firstSection.getByTestId('row-block').nth(1);
    await expect(newRow.getByTestId('column-block')).toHaveCount(1);

    // Step 6: Add a second column to the first row of the first section
    const firstRow = firstSection.getByTestId('row-block').first();
    await firstRow.getByTestId('add-child-append').filter({ hasText: 'Add Column' }).click();
    await expect(firstRow.getByTestId('column-block')).toHaveCount(2, { timeout: 10_000 });

    // Step 7: Publish the page
    await page.getByRole('button', { name: /Publish/ }).click();
    await expect(
      page.getByRole('button', { name: /Published/ }),
    ).toBeVisible({ timeout: 10_000 });

    // Step 8: Verify the published page renders on the frontend
    await page.goto('/e2e-build-test');
    await expect(page.locator('h1')).toContainText('E2E Build Test');
  });
});

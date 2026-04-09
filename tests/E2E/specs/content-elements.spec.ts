import { expect, test } from '@playwright/test';
import { loadFixture, loadAndNavigate, resetFixtures } from '../helpers/fixtures';
import { selectChosenValue } from '../helpers/forms';

test.describe('Content elements', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('content editor adds elements, configures titles, edits content, publishes, and verifies frontend rendering', async ({ page }) => {
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

    // Modal should open — use getByRole('dialog') since only one dialog is open at a time
    const picker = page.getByRole('dialog');
    await expect(picker).toBeVisible();

    // At least one type tile should be available
    const tiles = picker.getByTestId('element-type-tile');
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

    // Should navigate to the element edit page via the page editor
    await expect(page).toHaveURL(/\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/);

    // --- Step 5: Fill in the edit form including title settings ---
    // Wait for the form to be ready
    await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });
    await page.getByRole('textbox', { name: 'Title' }).fill('My Edited Element');

    // Configure title display: set heading level to h4 and enable visibility
    await selectChosenValue(page, 'TitleTag', 'h4');
    await page.locator('input[name="ShowTitle"]').check();

    // Set HTML content — try TinyMCE API first, fall back to textarea
    const hasTinyMce = await page.waitForFunction(
      () => (window as any).tinymce?.activeEditor?.initialized,
      null,
      { timeout: 5_000 },
    ).then(() => true).catch(() => false);

    if (hasTinyMce) {
      await page.evaluate(() => {
        (window as any).tinymce.activeEditor.setContent('<p>Hello from the grid</p>');
        (window as any).tinymce.activeEditor.fire('change');
      });
    } else {
      const htmlField = page.locator('textarea[name="HTML"]');
      await htmlField.fill('<p>Hello from the grid</p>');
    }

    // Save the element
    await page.getByRole('button', { name: /Save/ }).first().click();

    // Wait for save to complete — toast notification confirms success
    await expect(page.locator('.toast__content')).toContainText('Saved', { timeout: 15_000 });

    // --- Step 6: Navigate back to page editor via breadcrumb ---
    await page.getByRole('link', { name: 'E2E Content Elements Page' }).click();
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    // --- Step 7: Publish the page ---
    await page.getByRole('button', { name: /Publish/ }).click();
    await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({ timeout: 15_000 });

    // --- Step 8: Verify frontend rendering ---
    // Strip ?stage=Stage from the fixture URL to get the live URL
    const liveUrl = fixture.pageUrl.split('?')[0];
    await page.goto(liveUrl);

    // Verify the edited element renders its content
    await expect(page.locator('.content-element')).toContainText(['Hello from the grid']);

    // Verify pre-populated elements render their body content
    await expect(page.locator('.content-element').filter({ hasText: 'Text block body content' })).toBeVisible();
    await expect(page.locator('.content-element').filter({ hasText: 'Image block body content' })).toBeVisible();

    // Verify title configuration: section title renders as h3 (set in fixture)
    await expect(page.getByRole('heading', { level: 3, name: 'Content Section' })).toBeVisible();

    // Verify title configuration: edited element title renders as h4 (set in CMS form)
    await expect(page.getByRole('heading', { level: 4, name: 'My Edited Element' })).toBeVisible();
  });

  test('content editor navigates to container edit forms via title links and updates titles', async ({ page }) => {
    await loadAndNavigate(page, 'content-elements');

    // --- Step 1: Click section title to navigate to edit form ---
    await test.step('Edit section title via title link', async () => {
      await page.getByTestId('section-edit-link').click();
      await expect(page).toHaveURL(/\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/);

      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });
      await page.getByRole('textbox', { name: 'Title' }).fill('Updated Section Title');
      await page.getByRole('button', { name: /Save/ }).first().click();
      await expect(page.getByText('Saved Section "Updated Section Title" successfully.')).toBeVisible({ timeout: 15_000 });

      await page.getByRole('link', { name: 'E2E Content Elements Page' }).click();
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });
      await expect(page.getByTestId('section-title')).toContainText('Updated Section Title');
    });

    // --- Step 2: Click row title to navigate to edit form ---
    await test.step('Edit row title via title link', async () => {
      await page.getByTestId('row-edit-link').first().click();
      await expect(page).toHaveURL(/\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/);

      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });
      await page.getByRole('textbox', { name: 'Title' }).fill('Updated Row Title');
      await page.getByRole('button', { name: /Save/ }).first().click();
      await expect(page.getByText('Saved Row "Updated Row Title" successfully.')).toBeVisible({ timeout: 15_000 });

      await page.getByRole('link', { name: 'E2E Content Elements Page' }).click();
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });
      await expect(page.getByTestId('row-title').first()).toContainText('Updated Row Title');
    });

    // --- Step 3: Click column title to navigate to edit form ---
    await test.step('Edit column title via title link', async () => {
      await page.getByTestId('column-edit-link').first().click();
      await expect(page).toHaveURL(/\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/);

      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });
      await page.getByRole('textbox', { name: 'Title' }).fill('Updated Column Title');
      await page.getByRole('button', { name: /Save/ }).first().click();
      await expect(page.getByText('Saved Column "Updated Column Title" successfully.')).toBeVisible({ timeout: 15_000 });

      await page.getByRole('link', { name: 'E2E Content Elements Page' }).click();
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });
      await expect(page.getByTestId('column-title').first()).toContainText('Updated Column Title');
    });
  });
});

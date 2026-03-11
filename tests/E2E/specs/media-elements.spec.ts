import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

test.describe('Media elements', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('content editor configures media layout and verifies frontend rendering', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'media-elements');
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    // --- Step 1: Verify fixture loaded with image ---
    await test.step('Verify fixture loaded with image attached', async () => {
      const elementCards = page.getByTestId('element-card');
      await expect(elementCards).toHaveCount(2);
      const cardTitles = page.getByTestId('element-card-title');
      await expect(cardTitles).toHaveText(['Media Element', 'Text Element']);

      // Open the media element edit form
      await elementCards.first().click();
      await expect(page).toHaveURL(/\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/);
      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });

      // Navigate to Media tab and verify image is attached
      await page.getByRole('tab', { name: 'Media' }).click();

      // MediaType should show 'image' and an upload field should have a file
      const uploadField = page.locator('.uploadfield-item__title');
      await expect(uploadField).toBeVisible({ timeout: 10_000 });
    });

    // --- Step 2: Configure layout settings ---
    await test.step('Configure layout settings', async () => {
      await page.getByRole('tab', { name: 'Layout' }).click();

      await page.locator('select[name="ContentColumns"]').selectOption('8');
      await page.locator('select[name="MediaPosition"]').selectOption('last');
      await page.locator('select[name="VerticalAlignment"]').selectOption('center');
      await page.locator('input[name="GapSize"]').fill('3');

      await page.getByRole('button', { name: /Save/ }).first().click();
      await expect(page.locator('.toast__content')).toContainText('Saved', { timeout: 15_000 });
    });

    // --- Step 3: Configure media settings ---
    await test.step('Configure media settings', async () => {
      await page.getByRole('tab', { name: 'Media' }).click();

      await page.locator('select[name="MediaRatio"]').selectOption('16x9');
      await page.locator('input[name="MediaCaption"]').fill('Test media caption');

      await page.getByRole('button', { name: /Save/ }).first().click();
      await expect(page.locator('.toast__content')).toContainText('Saved', { timeout: 15_000 });
    });

    // --- Step 4: Navigate back and publish ---
    await test.step('Navigate back and publish page', async () => {
      await page.getByRole('link', { name: 'E2E Media Elements Page' }).click();
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

      await page.getByRole('button', { name: /Publish/ }).click();
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({ timeout: 15_000 });
    });

    // --- Step 5: Verify frontend rendering ---
    await test.step('Verify frontend rendering with media layout', async () => {
      const liveUrl = fixture.pageUrl.split('?')[0];
      await page.goto(liveUrl);

      // Image renders inside a figure with the media-block--image class
      const mediaFigure = page.locator('figure.media-block--image');
      await expect(mediaFigure).toBeVisible();

      // Image has alt text from caption
      const img = mediaFigure.locator('img');
      await expect(img).toHaveAttribute('alt', 'Test media caption');

      // Image src is populated (resized/served by SilverStripe)
      const src = await img.getAttribute('src');
      expect(src).toBeTruthy();

      // Caption renders in figcaption
      await expect(mediaFigure.locator('figcaption')).toContainText('Test media caption');

      // Side-by-side layout: the content-element has a row wrapper with two child divs
      const contentElement = page.locator('.content-element').filter({ has: mediaFigure });
      const rowWrapper = contentElement.locator('[class*="row"]');
      await expect(rowWrapper).toBeVisible();
      // Row wrapper has exactly 2 direct child divs (media column + content column)
      await expect(rowWrapper.locator('> div')).toHaveCount(2);

      // Content column renders the element body text
      await expect(contentElement).toContainText('Media element body content');

      // The plain text element also renders
      await expect(page.locator('.content-element').filter({ hasText: 'Text element body content' })).toBeVisible();
    });
  });
});

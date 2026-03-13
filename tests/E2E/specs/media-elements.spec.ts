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

    // --- Step 2: Verify MediaType toggles VideoCustomThumbnail visibility ---
    await test.step('Verify MediaType toggles VideoCustomThumbnail visibility', async () => {
      // We're already on the Media tab from Step 1
      // Native <select> is hidden by Chosen.js — interact with the Chosen widget
      const mediaTypeHolder = page.locator('[id$="_MediaType_Holder"]');
      const chosenContainer = mediaTypeHolder.locator('.chosen-container');
      const videoThumbnailHolder = page.locator('[id$="_VideoCustomThumbnail_Holder"]');

      // Initial state: MediaType=image, VideoCustomThumbnail is hidden
      await expect(page.locator('select[name="MediaType"]')).toHaveValue('image');
      await expect(videoThumbnailHolder).toBeHidden();

      // Switch to video — VideoCustomThumbnail becomes visible
      await chosenContainer.click();
      await mediaTypeHolder.locator('.chosen-results li').filter({ hasText: 'Video' }).click();
      await expect(videoThumbnailHolder).toBeVisible();

      // Switch back to image — VideoCustomThumbnail is hidden again
      await chosenContainer.click();
      await mediaTypeHolder.locator('.chosen-results li').filter({ hasText: 'Image' }).click();
      await expect(videoThumbnailHolder).toBeHidden();
    });

    // --- Step 3: Verify picker visual feedback and conditional field visibility ---
    await test.step('Verify column width picker UX behavior', async () => {
      await page.getByRole('tab', { name: 'Layout' }).click();

      const picker = page.locator('.column-width-picker');
      await expect(picker).toBeVisible();

      const fullWidthOption = picker.locator('label:has(input[value="0"])');
      const splitOption8 = picker.locator('label:has(input[value="8"])');
      const splitOption6 = picker.locator('label:has(input[value="6"])');

      // Holder IDs are form-prefixed — match by suffix
      const mediaPositionHolder = page.locator('[id$="_MediaPosition_Holder"]');
      const verticalAlignmentHolder = page.locator('[id$="_VerticalAlignment_Holder"]');
      const gapSizeHolder = page.locator('[id$="_GapSize_Holder"]');

      // Initial state: full width is selected, dependent fields are hidden
      await expect(fullWidthOption.locator('input')).toBeChecked();
      await expect(mediaPositionHolder).toBeHidden();
      await expect(verticalAlignmentHolder).toBeHidden();
      await expect(gapSizeHolder).toBeHidden();

      // Select a column split — dependent fields appear, selected state moves
      await splitOption8.click();
      await expect(splitOption8.locator('input')).toBeChecked();
      await expect(fullWidthOption.locator('input')).not.toBeChecked();
      await expect(mediaPositionHolder).toBeVisible();
      await expect(verticalAlignmentHolder).toBeVisible();
      await expect(gapSizeHolder).toBeVisible();

      // Switch to a different split — selected state follows
      await splitOption6.click();
      await expect(splitOption6.locator('input')).toBeChecked();
      await expect(splitOption8.locator('input')).not.toBeChecked();
      // Dependent fields remain visible for any non-zero split
      await expect(mediaPositionHolder).toBeVisible();

      // Switch back to full width — dependent fields hide again
      await fullWidthOption.click();
      await expect(fullWidthOption.locator('input')).toBeChecked();
      await expect(splitOption6.locator('input')).not.toBeChecked();
      await expect(mediaPositionHolder).toBeHidden();
      await expect(verticalAlignmentHolder).toBeHidden();
      await expect(gapSizeHolder).toBeHidden();
    });

    // --- Step 4: Configure layout settings and save ---
    await test.step('Configure layout settings', async () => {
      // Select 8/4 split for the save+render test
      const picker = page.locator('.column-width-picker');
      await picker.locator('label:has(input[value="8"])').click();
      await expect(page.locator('[id$="_MediaPosition_Holder"]')).toBeVisible();

      await page.locator('select[name="MediaPosition"]').selectOption('last');
      await page.locator('select[name="VerticalAlignment"]').selectOption('center');
      await page.locator('select[name="GapSize"]').selectOption('3');

      await page.getByRole('button', { name: /Save/ }).first().click();
      await expect(page.locator('.toast__content')).toContainText('Saved', { timeout: 15_000 });
    });

    // --- Step 5: Configure media settings ---
    await test.step('Configure media settings', async () => {
      await page.getByRole('tab', { name: 'Media' }).click();

      await page.locator('select[name="MediaRatio"]').selectOption('16x9');
      await page.locator('input[name="MediaCaption"]').fill('Test media caption');

      await page.getByRole('button', { name: /Save/ }).first().click();
      await expect(page.locator('.toast__content')).toContainText('Saved', { timeout: 15_000 });
    });

    // --- Step 6: Navigate back and publish ---
    await test.step('Navigate back and publish page', async () => {
      await page.getByRole('link', { name: 'E2E Media Elements Page' }).click();
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

      await page.getByRole('button', { name: /Publish/ }).click();
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({ timeout: 15_000 });
    });

    // --- Step 7: Verify frontend rendering ---
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

import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

test.describe('Grid settings tab', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('content editor configures column responsive grid settings via the Grid tab and verifies changes persist', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'element-tree');
    const columnId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\Column']['col1'];

    // --- Step 1: Navigate to column edit form ---
    await page.goto(`/admin/pages/edit/EditForm/${fixture.pageId}/field/GridEditor/item/${columnId}/edit`);
    await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });

    // --- Step 2: Open Grid tab ---
    await page.getByRole('tab', { name: 'Grid' }).click();

    // --- Step 3: Verify viewport controls are rendered ---
    // Bootstrap adapter has 6 viewports (xs, sm, md, lg, xl, xxl)
    const viewportFieldsets = page.locator('fieldset.grid-settings-field__viewport');
    await expect(viewportFieldsets).toHaveCount(6);

    // First viewport (xs) should NOT have an "inherit" checkbox
    const xsFieldset = viewportFieldsets.filter({ has: page.locator('[data-viewport="xs"]') });
    await expect(xsFieldset.locator('input[name="GridSettings[xs][inherit]"]')).toHaveCount(0);

    // Second viewport (sm) SHOULD have an "inherit" checkbox
    await expect(page.locator('input[name="GridSettings[sm][inherit]"]')).toBeVisible();

    // --- Step 4: Verify existing values loaded from fixture ---
    // col1 fixture has: {"md":{"width":8,"offset":0,"visible":true},"lg":{"width":6,"offset":0,"visible":true}}
    // xs: defaults (width=12, offset=0, visible=true, inherit=false)
    await expect(page.locator('select[name="GridSettings[xs][width]"]')).toHaveValue('12');
    await expect(page.locator('select[name="GridSettings[xs][offset]"]')).toHaveValue('0');
    await expect(page.locator('input[name="GridSettings[xs][visible]"]')).toBeChecked();

    // sm: inherits from xs
    await expect(page.locator('input[name="GridSettings[sm][inherit]"]')).toBeChecked();

    // md: explicit override (width=8)
    await expect(page.locator('input[name="GridSettings[md][inherit]"]')).not.toBeChecked();
    await expect(page.locator('select[name="GridSettings[md][width]"]')).toHaveValue('8');

    // lg: explicit override (width=6)
    await expect(page.locator('input[name="GridSettings[lg][inherit]"]')).not.toBeChecked();
    await expect(page.locator('select[name="GridSettings[lg][width]"]')).toHaveValue('6');

    // xl: inherits from lg
    await expect(page.locator('input[name="GridSettings[xl][inherit]"]')).toBeChecked();

    // --- Step 5: Change width on first viewport (xs) to 6 ---
    await page.locator('select[name="GridSettings[xs][width]"]').selectOption('6');

    // --- Step 6: Toggle visibility off on lg ---
    // Uncheck inherit first (if checked), then uncheck visible
    const lgInherit = page.locator('input[name="GridSettings[lg][inherit]"]');
    if (await lgInherit.isChecked()) {
      await lgInherit.uncheck();
    }
    await page.locator('input[name="GridSettings[lg][visible]"]').uncheck();

    // --- Step 7: Save ---
    await page.getByRole('button', { name: /Save/ }).first().click();
    await expect(page.locator('.toast__content')).toHaveText('Saved Column "Left Column" successfully.', { timeout: 15_000 });

    // --- Step 8: Navigate back to page editor via breadcrumb ---
    await page.getByRole('link', { name: 'E2E Grid Test Page' }).click();
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    // --- Step 9: Verify column badge reflects the xs width change ---
    // Default viewport is md, which inherits xs width=6 (since md override was width=8,
    // but we changed xs to 6 — md still has its own override of 8)
    const leftColumn = page.getByTestId('column-block').first();
    const leftBadge = leftColumn.getByTestId('column-badge');
    // md viewport is default, col1 has md override with width=8
    await expect(leftBadge).toHaveText('8/12');

    // --- Step 10: Switch viewport to lg and verify hidden state ---
    const lgButton = page.getByRole('button', { name: 'Large', exact: true });
    await lgButton.click();
    await expect(leftBadge).toHaveText('hidden');
    await expect(leftColumn).toHaveCSS('opacity', '0.4');

    // Offset badge should be disabled for hidden columns
    const leftOffsetBadge = leftColumn.getByTestId('column-offset-badge');
    await expect(leftOffsetBadge).toBeDisabled();

    // --- Step 11: Re-open column edit form and verify persistence ---
    await leftColumn.getByTestId('element-card').first().click();
    // Navigate to column edit form instead — go directly
    await page.goto(`/admin/pages/edit/EditForm/${fixture.pageId}/field/GridEditor/item/${columnId}/edit`);
    await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });
    await page.getByRole('tab', { name: 'Grid' }).click();

    // Verify persisted values
    await expect(page.locator('select[name="GridSettings[xs][width]"]')).toHaveValue('6');
    await expect(page.locator('input[name="GridSettings[lg][visible]"]')).not.toBeChecked();
    await expect(page.locator('input[name="GridSettings[lg][inherit]"]')).not.toBeChecked();
  });
});

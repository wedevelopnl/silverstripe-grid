import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

test.describe('Grid settings tab', { tag: '@bootstrap-only' }, () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  // Asserts viewport count (6), Bootstrap labels ("Medium"/"Large"), and
  // GridSettings[md][...] field names — all adapter-specific. Port to an
  // adapter-agnostic variant before dropping the @bootstrap-only tag.
  test('content editor configures column responsive grid settings via the Grid tab and verifies changes persist', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'element-tree');
    const columnId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\Column']['col1'];

    // --- Step 1: Navigate to column edit form ---
    await page.goto(`/admin/pages/edit/EditForm/${fixture.pageId}/field/GridEditor/item/${columnId}/edit`);
    await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });

    // --- Step 2: Open Grid tab ---
    await page.getByRole('tab', { name: 'Grid' }).click();

    // --- Step 3: Verify viewport table is rendered ---
    // Bootstrap adapter has 6 viewports (xs, sm, md, lg, xl, xxl)
    const settingsTable = page.locator('.grid-settings-field__overrides');
    await expect(settingsTable).toBeVisible();
    const rows = settingsTable.locator('tbody tr');
    await expect(rows).toHaveCount(6);

    // --- Step 4: Verify default viewport (md) has "default" badge and no override toggle ---
    // col1 fixture: default={width:8,offset:0,visible:true}, overrides={xs:{width:12,...},lg:{width:6,...}}
    // md is the default viewport — rendered with is-default class, no override checkbox
    const mdRow = rows.nth(2); // xs=0, sm=1, md=2
    await expect(mdRow.locator('.badge')).toHaveText('default');
    await expect(mdRow.locator('.grid-settings-field__override-toggle')).toHaveCount(0);
    await expect(mdRow.locator('select[name="GridSettings[md][width]"]')).toHaveValue('8');

    // --- Step 5: Verify non-default viewports have override toggles ---
    // xs: effective (12,0,true) differs from md default (8,0,true) → override checked
    const xsOverride = page.locator('input[name="GridSettings[xs][override]"]');
    await expect(xsOverride).toBeChecked();
    await expect(page.locator('select[name="GridSettings[xs][width]"]')).toHaveValue('12');

    // lg: explicit override (width=6) differs from md → override checked
    const lgOverride = page.locator('input[name="GridSettings[lg][override]"]');
    await expect(lgOverride).toBeChecked();
    await expect(page.locator('select[name="GridSettings[lg][width]"]')).toHaveValue('6');

    // --- Step 6: Uncheck xs override so it uses md default values ---
    await xsOverride.uncheck();
    // Controls should now be disabled
    await expect(page.locator('select[name="GridSettings[xs][width]"]')).toBeDisabled();

    // --- Step 7: Toggle visibility off on lg ---
    await page.locator('input[name="GridSettings[lg][visible]"]').uncheck();

    // --- Step 8: Save ---
    await page.getByRole('button', { name: /Save/ }).first().click();
    await expect(page.locator('.toast__content')).toHaveText('Saved Column "Left Column" successfully.', { timeout: 15_000 });

    // --- Step 9: Navigate back to page editor via breadcrumb ---
    await page.getByRole('link', { name: 'E2E Grid Test Page' }).click();
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    // --- Step 10: Verify column badge shows md default width ---
    const leftColumn = page.getByTestId('column-block').first();
    const leftBadge = leftColumn.getByTestId('column-badge');
    // md viewport is default, col1 has md width=8
    await expect(leftBadge).toHaveText('8/12');

    // --- Step 11: Switch viewport to lg and verify hidden state ---
    const lgButton = page.getByRole('button', { name: 'Large', exact: true });
    await lgButton.click();
    await expect(leftBadge).toHaveText('hidden');
    await expect(leftColumn).toHaveCSS('opacity', '0.4');

    // --- Step 12: Re-open column edit form and verify persistence ---
    await page.goto(`/admin/pages/edit/EditForm/${fixture.pageId}/field/GridEditor/item/${columnId}/edit`);
    await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });
    await page.getByRole('tab', { name: 'Grid' }).click();

    // xs override should be unchecked (we unchecked it in step 6)
    await expect(page.locator('input[name="GridSettings[xs][override]"]')).not.toBeChecked();
    // lg visible should be unchecked (we unchecked it in step 7)
    await expect(page.locator('input[name="GridSettings[lg][visible]"]')).not.toBeChecked();
    await expect(page.locator('input[name="GridSettings[lg][override]"]')).toBeChecked();
  });
});

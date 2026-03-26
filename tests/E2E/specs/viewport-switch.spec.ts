import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

test.describe('Viewport switcher', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('editor resets viewport overrides via the viewport switcher', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'element-tree');

    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(
      page.getByTestId('grid-editor-loading'),
    ).toBeHidden({ timeout: 15_000 });

    const leftColumn = page.getByTestId('column-block').first();
    const rightColumn = page.getByTestId('column-block').nth(1);
    const leftBadge = leftColumn.getByTestId('column-badge');
    const rightBadge = rightColumn.getByTestId('column-badge');
    const resetButton = page.getByTestId('reset-overrides-button');

    // --- Step 1: On default viewport (md), "Reset all" should appear ---
    // Both columns have xs and lg overrides
    await expect(resetButton).toBeVisible();
    await expect(resetButton).toHaveText('Reset all');

    // --- Step 2: Switch to xs viewport → "Reset viewport" appears ---
    const xsButton = page.getByRole('button', { name: 'Extra Small', exact: true });
    await xsButton.click();
    await expect(resetButton).toHaveText('Reset viewport');

    // Verify xs overrides are active: col1=12/12, col2=hidden
    await expect(leftBadge).toHaveText('12/12');
    await expect(rightBadge).toHaveText('hidden');

    // --- Step 3: Click reset → confirm dialog → confirm ---
    await resetButton.click();
    const confirmDialog = page.getByTestId('confirm-dialog');
    await expect(confirmDialog).toBeVisible();
    await confirmDialog.getByRole('button', { name: 'Reset' }).click();

    // --- Step 4: xs overrides cleared — columns now inherit md defaults ---
    // col1 default: width=8, col2 default: width=4
    await expect(leftBadge).toHaveText('8/12');
    await expect(rightBadge).toHaveText('4/12');

    // --- Step 5: Switch to md → "Reset all" still visible (lg overrides remain) ---
    const mediumButton = page.getByRole('button', { name: 'Medium', exact: true });
    await mediumButton.click();
    await expect(resetButton).toBeVisible();
    await expect(resetButton).toHaveText('Reset all');

    // --- Step 6: Click "Reset all" → confirm → all overrides cleared ---
    await resetButton.click();
    await expect(confirmDialog).toBeVisible();
    await confirmDialog.getByRole('button', { name: 'Reset' }).click();

    // --- Step 7: Switch to lg → columns show md defaults (overrides gone) ---
    const lgButton = page.getByRole('button', { name: 'Large', exact: true });
    await lgButton.click();
    await expect(leftBadge).toHaveText('8/12');
    await expect(rightBadge).toHaveText('4/12');

    // --- Step 8: Switch back to md → reset button gone (no overrides left) ---
    await mediumButton.click();
    await expect(resetButton).toBeHidden();
  });

  test('editor adjusts responsive layout across viewports, publishes, and frontend renders correct grid classes', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'element-tree');

    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(
      page.getByTestId('grid-editor-loading'),
    ).toBeHidden({ timeout: 15_000 });

    const viewportGroup = page.getByRole('group', { name: 'Viewport size' });
    const viewportButtons = page.getByTestId('viewport-button');
    const leftColumn = page.getByTestId('column-block').first();
    const rightColumn = page.getByTestId('column-block').nth(1);
    const leftBadge = leftColumn.getByTestId('column-badge');
    const rightBadge = rightColumn.getByTestId('column-badge');
    const leftOffsetBadge = leftColumn.getByTestId('column-offset-badge');

    // --- Review initial md layout ---
    await expect(viewportGroup).toBeVisible();
    await expect(viewportButtons).toHaveCount(6);

    const mediumButton = page.getByRole('button', { name: 'Medium', exact: true });
    await expect(mediumButton).toHaveAttribute('aria-pressed', 'true');

    for (const label of ['Extra Small', 'Small', 'Large', 'Extra Large', 'Extra Extra Large']) {
      await expect(
        page.getByRole('button', { name: label, exact: true }),
      ).toHaveAttribute('aria-pressed', 'false');
    }

    await expect(leftBadge).toHaveText('8/12');
    await expect(rightBadge).toHaveText('4/12');
    await expect(leftOffsetBadge).toHaveText('none');
    await expect(leftColumn).not.toHaveCSS('opacity', '0.4');
    await expect(rightColumn).not.toHaveCSS('opacity', '0.4');

    // --- Adjust md layout: narrow left column to 6, add offset +2 ---
    await leftBadge.click();
    const leftWidthListbox = leftColumn.getByTestId('column-badge-listbox');
    await leftWidthListbox.getByRole('option', { name: '6/12' }).click();
    await expect(leftBadge).toHaveText('6/12');

    await leftOffsetBadge.click();
    const leftOffsetListbox = leftColumn.getByTestId('column-offset-badge-listbox');
    await leftOffsetListbox.getByRole('option', { name: '+2' }).click();
    await expect(leftOffsetBadge).toHaveText('+2');

    // --- Switch to xs: check mobile layout is unchanged ---
    const xsButton = page.getByRole('button', { name: 'Extra Small', exact: true });
    await xsButton.click();

    await expect(xsButton).toHaveAttribute('aria-pressed', 'true');
    await expect(mediumButton).toHaveAttribute('aria-pressed', 'false');

    // xs layout from fixture: left=12/12, right=hidden
    await expect(leftBadge).toHaveText('12/12');
    await expect(leftColumn).not.toHaveCSS('opacity', '0.4');
    await expect(rightBadge).toHaveText('hidden');
    await expect(rightColumn).toHaveCSS('opacity', '0.4');

    // Offset picker disabled on hidden column
    const rightOffsetBadge = rightColumn.getByTestId('column-offset-badge');
    await expect(rightOffsetBadge).toBeDisabled();

    // --- Unhide right column on xs: editor decides mobile should show both ---
    await rightBadge.click();
    const rightWidthListbox = rightColumn.getByTestId('column-badge-listbox');
    await rightWidthListbox.getByRole('option', { name: '12/12' }).click();
    await expect(rightBadge).toHaveText('12/12');
    await expect(rightColumn).not.toHaveCSS('opacity', '0.4');

    // --- Switch to lg to verify it wasn't affected ---
    const lgButton = page.getByRole('button', { name: 'Large', exact: true });
    await lgButton.click();

    await expect(lgButton).toHaveAttribute('aria-pressed', 'true');
    await expect(xsButton).toHaveAttribute('aria-pressed', 'false');

    await expect(leftBadge).toHaveText('6/12');
    await expect(rightBadge).toHaveText('6/12');
    await expect(leftColumn).not.toHaveCSS('opacity', '0.4');
    await expect(rightColumn).not.toHaveCSS('opacity', '0.4');

    // --- Switch back to md: verify our earlier edits are still there ---
    await mediumButton.click();
    await expect(leftBadge).toHaveText('6/12');
    await expect(leftOffsetBadge).toHaveText('+2');
    await expect(rightBadge).toHaveText('4/12');

    // --- Publish and verify the frontend renders the correct grid classes ---
    await page.getByRole('button', { name: /Publish/ }).click();
    await expect(
      page.getByRole('button', { name: /Published/ }),
    ).toBeVisible({ timeout: 10_000 });

    const livePath = fixture.pageUrl.split('?')[0];
    await page.goto(livePath);
    await expect(page.locator('h1')).toContainText('E2E Grid Test Page');

    // The frontend renders Column elements as <div class="element column {ColumnClasses}">
    // ColumnClasses is the full responsive class chain from the Bootstrap adapter.
    const frontendColumns = page.locator('div.element.column');
    await expect(frontendColumns).toHaveCount(2);

    // Left column after edits (isolated strategy, default=md={6,2,true}):
    //   xs: {12, 0, true} (override)   → col-12 (first viewport, always emits)
    //   sm: {6, 2, true} (default)     → col-sm-6 offset-sm-2 (width 12→6, offset 0→2)
    //   md: {6, 2, true} (default)     → (no change from sm)
    //   lg: {6, 0, true} (override)    → offset-lg-0 (width same, offset 2→0)
    //   xl: {6, 2, true} (default)     → offset-xl-2 (width same, offset 0→2)
    //   xxl: {6, 2, true} (default)    → (no change from xl)
    const leftFrontend = frontendColumns.first();
    const leftClasses = (await leftFrontend.getAttribute('class'))!.split(/\s+/).sort();
    expect(leftClasses).toEqual([
      'col-12', 'col-sm-6',
      'column', 'element', 'offset-lg-0', 'offset-sm-2', 'offset-xl-2',
    ].sort());

    // Right column after edits (isolated strategy, default=md={4,0,true}):
    //   xs: {12, 0, true} (override, was hidden, now visible) → col-12 (first viewport)
    //   sm: {4, 0, true} (default)                            → col-sm-4 (width 12→4)
    //   md: {4, 0, true} (default)                            → (no change from sm)
    //   lg: {6, 0, true} (override)                           → col-lg-6 (width 4→6)
    //   xl: {4, 0, true} (default)                            → col-xl-4 (width 6→4)
    //   xxl: {4, 0, true} (default)                           → (no change from xl)
    const rightFrontend = frontendColumns.nth(1);
    const rightClasses = (await rightFrontend.getAttribute('class'))!.split(/\s+/).sort();
    expect(rightClasses).toEqual([
      'col-12', 'col-lg-6', 'col-sm-4', 'col-xl-4',
      'column', 'element',
    ].sort());

  });
});

import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

test.describe('Viewport switcher', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
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

    // Left column after edits:
    //   xs: width 12 (unchanged)  → col-12
    //   sm: width 12 (unchanged)  → col-sm-12
    //   md: width 6, offset 2     → col-md-6 offset-md-2  (was col-md-8, no offset)
    //   lg: width 6 (unchanged)   → col-lg-6
    //   xl: width 6 (unchanged)   → col-xl-6
    //   xxl: width 6 (unchanged)  → col-xxl-6
    const leftFrontend = frontendColumns.first();
    const leftClasses = (await leftFrontend.getAttribute('class'))!.split(/\s+/).sort();
    expect(leftClasses).toEqual([
      'col-12', 'col-lg-6', 'col-md-6', 'col-sm-12', 'col-xl-6', 'col-xxl-6',
      'column', 'element', 'offset-md-2',
    ].sort());

    // Right column after edits:
    //   xs: width 12, visible     → col-12         (was hidden: d-none d-sm-block)
    //   sm: width 12 (unchanged)  → col-sm-12      (was hidden: d-sm-none d-md-block)
    //   md: width 4 (unchanged)   → col-md-4
    //   lg: width 6 (unchanged)   → col-lg-6
    //   xl: width 6 (unchanged)   → col-xl-6
    //   xxl: width 6 (unchanged)  → col-xxl-6
    //
    // Note: xs and sm were both hidden in the fixture. We only unhid xs,
    // so sm remains hidden (d-sm-none d-md-block).
    const rightFrontend = frontendColumns.nth(1);
    const rightClasses = (await rightFrontend.getAttribute('class'))!.split(/\s+/).sort();
    expect(rightClasses).toEqual([
      'col-12', 'col-md-4', 'col-lg-6', 'col-xl-6', 'col-xxl-6',
      'column', 'd-md-block', 'd-sm-none', 'element',
    ].sort());

  });
});

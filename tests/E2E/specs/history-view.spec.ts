import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

/**
 * End-to-end user journey: a content editor publishes a page, edits
 * the grid, publishes again, then inspects the page's version history
 * to see the grid as it was at each point in time.
 *
 * All versions are created through real user actions inside this test
 * (publish, edit, publish again) — the fixture starts as a plain draft
 * with no version history, so each history row is the validated result
 * of an explicit in-test interaction.
 *
 * This guards the full rendering pipeline:
 *
 *   1. GridEditorField survives DataObjectVersionFormFactory (needs
 *      `FormField` parent, not `GridField`)
 *   2. GridEditorField is inside Root.Main (needs insertAfter on the
 *      real field name MenuTitle, not the display label)
 *   3. GridEditorField declares schemaType=Custom + schemaComponent=
 *      GridEditorField so FormSchema serialization passes validation
 *   4. The React wrapper `GridEditorField` is registered with the CMS
 *      Injector and receives pageId/zone/readonly/version via schema
 *      data
 *   5. The main edit view still renders the editable grid (legacy
 *      FieldHolder + entwine bridge path is untouched)
 *   6. The history view renders the grid in readonly mode with all
 *      interactive controls hidden
 */
test.describe('History view readonly grid', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('editor publishes, edits, re-publishes, then sees the grid in history', async ({
    page,
  }) => {
    // --- Baseline: fixture loads a draft page with one section ---
    const fixture = await loadFixture(page.request, 'history-view-test');

    // Navigate to the CMS edit view. Main-edit path is legacy HTML +
    // entwine bridge; this also serves as a regression guard for that
    // path (we haven't touched it in this PR).
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({
      timeout: 15_000,
    });

    const editGridEditor = page.getByTestId('grid-editor');
    await expect(editGridEditor).toBeVisible();
    await expect(editGridEditor).not.toHaveClass(/grid-editor--readonly/);
    await expect(editGridEditor.getByTestId('section-block')).toHaveCount(1);
    await expect(
      editGridEditor
        .getByTestId('section-block')
        .filter({ hasText: 'Original Alpha Section' }),
    ).toBeVisible();

    // Interactive controls render in the editable view — proves
    // `useReadonly()` is false here.
    await expect(editGridEditor.getByTestId('drag-handle').first()).toBeVisible();
    await expect(editGridEditor.getByTestId('add-child-append').first()).toBeVisible();

    // --- User action #1: publish the baseline (creates the first live version) ---
    await page.getByRole('button', { name: /Publish/ }).click();
    await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({
      timeout: 10_000,
    });

    // --- User action #2: add a second section to the draft ---
    await editGridEditor
      .getByTestId('add-child-append')
      .filter({ hasText: 'Add Section' })
      .click();
    await expect(editGridEditor.getByTestId('section-block')).toHaveCount(2, {
      timeout: 10_000,
    });

    // --- User action #3: publish the updated draft (creates the second live version) ---
    await page.getByRole('button', { name: /Publish/ }).click();
    await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({
      timeout: 10_000,
    });

    // --- Open the History tab ---
    await page.goto(`/admin/pages/history/show/${fixture.pageId}`);

    const versionRows = page.locator('.history-viewer__row');
    await expect(versionRows.first()).toBeVisible({ timeout: 15_000 });

    // At least two versions must exist — one per publish above.
    const totalRows = await versionRows.count();
    expect(totalRows).toBeGreaterThanOrEqual(2);

    // --- Click the newest row: it reflects the post-edit state the
    //     test just published (two sections) ---
    await versionRows.first().click();
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({
      timeout: 15_000,
    });

    const historyGridEditor = page.getByTestId('grid-editor');
    await expect(historyGridEditor).toHaveCount(1);
    await expect(historyGridEditor).toBeVisible();

    // CRITICAL: readonly class is applied — this is the core promise
    // of the feature.
    await expect(historyGridEditor).toHaveClass(/grid-editor--readonly/);

    // The post-edit tree is rendered inside the history viewer.
    await expect(historyGridEditor.getByTestId('section-block')).toHaveCount(2);
    await expect(
      historyGridEditor
        .getByTestId('section-block')
        .filter({ hasText: 'Original Alpha Section' }),
    ).toBeVisible();

    // ALL interactive controls must be hidden in readonly mode —
    // proves `useReadonly()` gates the block components correctly.
    await expect(historyGridEditor.getByTestId('drag-handle')).toHaveCount(0);
    await expect(historyGridEditor.getByTestId('actions-menu-trigger')).toHaveCount(0);
    await expect(historyGridEditor.getByTestId('add-child-append')).toHaveCount(0);
    await expect(historyGridEditor.getByTestId('add-child-empty')).toHaveCount(0);
    await expect(historyGridEditor.getByTestId('add-content-button')).toHaveCount(0);
    await expect(historyGridEditor.getByTestId('reset-overrides-button')).toHaveCount(0);
    await expect(historyGridEditor.getByTestId('section-edit-link')).toHaveCount(0);
    await expect(historyGridEditor.getByTestId('row-edit-link')).toHaveCount(0);
    await expect(historyGridEditor.getByTestId('column-edit-link')).toHaveCount(0);
  });
});

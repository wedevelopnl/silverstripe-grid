import { expect, test } from '@playwright/test'
import {
  activateViewport,
  firstNonDefaultViewport,
  readAdapterConfig,
  widthLabel,
} from '../helpers/adapter'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

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
    await resetFixtures(request)
  })

  test('editor publishes, edits, re-publishes, then sees the grid in history', async ({ page }) => {
    // --- Baseline: fixture loads a draft page with one section ---
    const fixture = await loadFixture(page.request, 'history-view-test')

    await test.step('edit the grid: view, publish, add a section, re-publish', async () => {
      // Navigate to the CMS edit view. Main-edit path is legacy HTML +
      // entwine bridge; this also serves as a regression guard for that
      // path (we haven't touched it in this PR).
      await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({
        timeout: 15_000,
      })

      const editGridEditor = page.getByTestId('grid-editor')
      await expect(editGridEditor).toBeVisible()
      await expect(editGridEditor.getByTestId('section-block')).toHaveCount(1)
      await expect(
        editGridEditor.getByTestId('section-block').filter({ hasText: 'Original Alpha Section' }),
      ).toBeVisible()

      // Interactive controls render in the editable view — proves
      // `useReadonly()` is false here.
      await expect(editGridEditor.getByTestId('drag-handle').first()).toBeVisible()
      await expect(editGridEditor.getByTestId('add-child-append').first()).toBeVisible()

      // --- User action #1: publish the baseline (creates the first live version) ---
      // The CMS publish action's label toggles between "Publish" and "Published"
      // depending on the page's live/draft state, and a grid-only edit (written
      // through the grid's own API) does not flip it back — so across this
      // publish → edit → re-publish cycle the exact label is not knowable at
      // author time. Match it loosely by design.
      await page.getByRole('button', { name: /Publish/ }).click()
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({
        timeout: 10_000,
      })

      // --- User action #2: add a second section to the draft ---
      await editGridEditor
        .getByTestId('add-child-append')
        .filter({ hasText: 'Add Section' })
        .click()
      await expect(editGridEditor.getByTestId('section-block')).toHaveCount(2, {
        timeout: 10_000,
      })

      // --- User action #3: override a column width on a non-default viewport ---
      // Without a real override the readonly assertion further down is vacuous:
      // the picker would offer no reset scope to withhold in the first place.
      const adapter = await readAdapterConfig(page)
      await activateViewport(page, firstNonDefaultViewport(adapter))

      const badge = editGridEditor.getByTestId('column-badge').first()
      const halfWidth = widthLabel(Math.floor(adapter.columnCount / 2))
      await badge.click()
      await editGridEditor
        .getByTestId('column-badge-listbox')
        .getByRole('option', { name: halfWidth, exact: true })
        .click()
      await expect(badge).toHaveText(halfWidth)

      // Positive control: in the editable view the override DOES surface a reset
      // scope. That is the thing readonly mode has to withhold.
      await editGridEditor.getByTestId('viewport-picker-trigger').click()
      await expect(
        editGridEditor.getByTestId('viewport-picker-dropdown').getByRole('menuitem').first(),
      ).toBeVisible()
      await page.keyboard.press('Escape')

      // --- User action #4: publish the updated draft (creates the second live version) ---
      // Loose match — see the note on action #1 above.
      await page.getByRole('button', { name: /Publish/ }).click()
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({
        timeout: 10_000,
      })
    })

    await test.step('inspect the grid in version history (readonly)', async () => {
      // --- Open the History tab ---
      await page.goto(`/admin/pages/history/show/${fixture.pageId}`)

      // SilverStripe's versioned-admin renders the timeline as an ARIA table
      // (role=table > role=row > role=cell). The version rows are its data rows
      // (every row except the header carrying the column headers).
      const versionRows = page
        .getByRole('table')
        .getByRole('row')
        .filter({ hasNot: page.getByRole('columnheader') })
      await expect(versionRows.first()).toBeVisible({ timeout: 15_000 })

      // At least two versions must exist — one per publish above.
      expect(await versionRows.count()).toBeGreaterThanOrEqual(2)

      // --- Click the newest row: it reflects the post-edit state the
      //     test just published (two sections) ---
      await versionRows.first().click()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({
        timeout: 15_000,
      })

      const historyGridEditor = page.getByTestId('grid-editor')
      await expect(historyGridEditor).toHaveCount(1)
      await expect(historyGridEditor).toBeVisible()

      // The post-edit tree is rendered inside the history viewer.
      await expect(historyGridEditor.getByTestId('section-block')).toHaveCount(2)
      await expect(
        historyGridEditor
          .getByTestId('section-block')
          .filter({ hasText: 'Original Alpha Section' }),
      ).toBeVisible()

      // The viewport picker is mounted in readonly mode so admins can inspect
      // the grid at each responsive breakpoint while browsing history — but it
      // offers only viewports, never a reset scope. The version on screen does
      // carry an override (created above), so a scope exists to be withheld:
      // drop the readonly gate and these menuitems appear.
      await expect(historyGridEditor.getByTestId('viewport-switcher')).toBeVisible()
      await historyGridEditor.getByTestId('viewport-picker-trigger').click()
      const historyPicker = historyGridEditor.getByTestId('viewport-picker-dropdown')
      await expect(historyPicker.getByRole('menuitemradio').first()).toBeVisible()
      await expect(historyPicker.getByRole('menuitem')).toHaveCount(0)
      await page.keyboard.press('Escape')

      // ALL interactive controls must be hidden in readonly mode —
      // proves `useReadonly()` gates the block components correctly.
      await expect(historyGridEditor.getByTestId('drag-handle')).toHaveCount(0)
      await expect(historyGridEditor.getByTestId('actions-menu-trigger')).toHaveCount(0)
      await expect(historyGridEditor.getByTestId('add-child-append')).toHaveCount(0)
      await expect(historyGridEditor.getByTestId('add-child-empty')).toHaveCount(0)
      await expect(historyGridEditor.getByTestId('add-content-button')).toHaveCount(0)
      await expect(historyGridEditor.getByTestId('section-edit-link')).toHaveCount(0)
      await expect(historyGridEditor.getByTestId('row-edit-link')).toHaveCount(0)
      await expect(historyGridEditor.getByTestId('column-edit-link')).toHaveCount(0)
    })
  })
})

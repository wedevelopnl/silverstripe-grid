import { expect, test } from '@playwright/test'
import { loadFixture, resetFixtures } from '../helpers/fixtures'
import { activateViewport, firstNonDefaultViewport, readAdapterConfig } from '../helpers/adapter'

test.describe('Grid settings tab', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('editor sets a per-viewport override through the Grid tab, confirms persistence, then clears it', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'element-tree')
    const columnId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\Column']['col1']

    await page.goto(
      `/admin/pages/edit/EditForm/${fixture.pageId}/field/GridEditor/item/${columnId}/edit`,
    )
    await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 })

    const adapter = await readAdapterConfig(page)
    const overrideKey = firstNonDefaultViewport(adapter)
    const editFormUrl = `/admin/pages/edit/EditForm/${fixture.pageId}/field/GridEditor/item/${columnId}/edit`

    // The settings table is the only <table> on the Grid tab; its rows carry
    // the implicit ARIA table/row roles. Form controls are located by their
    // submit `name` (a stable semantic hook, not a styling class).
    const settingsTable = page.getByRole('table')
    // Data rows = all rows minus the header row.
    const dataRows = settingsTable
      .getByRole('row')
      .filter({ hasNot: page.getByRole('columnheader') })
    const defaultBadge = page.getByText('default', { exact: true })
    const defaultRow = settingsTable.getByRole('row').filter({ has: defaultBadge })
    const defaultWidthSelect = page.locator(
      `select[name="GridSettings[${adapter.defaultViewport}][width]"]`,
    )
    const overrideToggle = page.locator(`input[name="GridSettings[${overrideKey}][override]"]`)
    const overrideWidthSelect = page.locator(`select[name="GridSettings[${overrideKey}][width]"]`)
    const overrideVisible = page.locator(`input[name="GridSettings[${overrideKey}][visible]"]`)

    await test.step('Grid tab renders one row per adapter viewport with correct baseline state', async () => {
      await page.getByRole('tab', { name: 'Grid' }).click()

      await expect(settingsTable).toBeVisible()
      // One data row per adapter viewport.
      await expect(dataRows).toHaveCount(adapter.viewports.length)

      // Default row: "default" badge, no override toggle (always overridden).
      await expect(defaultRow).toHaveCount(1)
      await expect(defaultBadge).toBeVisible()
      await expect(
        defaultRow.locator(`input[name="GridSettings[${adapter.defaultViewport}][override]"]`),
      ).toHaveCount(0)
      // Baseline fixture: default width = full grid, visible.
      await expect(defaultWidthSelect).toHaveValue(String(adapter.columnCount))

      // Non-default rows start un-overridden: toggle unchecked, controls disabled.
      await expect(overrideToggle).not.toBeChecked()
      await expect(overrideWidthSelect).toBeDisabled()
      await expect(overrideVisible).toBeDisabled()
    })

    await test.step('enabling the override activates its controls', async () => {
      await overrideToggle.check()
      await expect(overrideWidthSelect).toBeEnabled()
      await expect(overrideVisible).toBeEnabled()
    })

    // Pick a width that differs from the default so the override is observable.
    const overrideWidth = Math.floor(adapter.columnCount / 2)

    await test.step('editor sets override width and visibility, saves successfully', async () => {
      await overrideWidthSelect.selectOption(String(overrideWidth))
      await overrideVisible.uncheck()

      await page.getByRole('button', { name: /Save/ }).first().click()
      // The save confirmation is rendered by the SilverStripe admin's own
      // toast component (third-party markup with no test hook of ours), so we
      // assert on the user-visible "Saved" message it displays.
      await expect(page.getByText(/Saved/).first()).toBeVisible({
        timeout: 15_000,
      })
    })

    await test.step('editor badge reflects override after returning to the page editor', async () => {
      await page.getByRole('link', { name: 'E2E Grid Test Page' }).click()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      const leftColumn = page.getByTestId('column-block').first()
      const badge = leftColumn.getByTestId('column-badge')

      // Default viewport is active on first load — badge shows default width.
      await expect(badge).toHaveText(`${adapter.columnCount}/${adapter.columnCount}`)

      // Switch to the override viewport — badge shows hidden state.
      await activateViewport(page, overrideKey)
      await expect(badge).toHaveText('hidden')
    })

    await test.step('override persists across a reload of the column edit form', async () => {
      await page.goto(editFormUrl)
      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 })
      await page.getByRole('tab', { name: 'Grid' }).click()

      await expect(overrideToggle).toBeChecked()
      await expect(overrideWidthSelect).toHaveValue(String(overrideWidth))
      await expect(overrideVisible).not.toBeChecked()
    })

    await test.step('clearing the override leaves the row disabled after save', async () => {
      await overrideToggle.uncheck()
      await page.getByRole('button', { name: /Save/ }).first().click()
      // The save confirmation is rendered by the SilverStripe admin's own
      // toast component (third-party markup with no test hook of ours), so we
      // assert on the user-visible "Saved" message it displays.
      await expect(page.getByText(/Saved/).first()).toBeVisible({
        timeout: 15_000,
      })

      // SilverStripe re-renders the form HTML from server state after save,
      // so the in-form row reflects the persisted cleared override.
      await expect(overrideToggle).not.toBeChecked()
      await expect(overrideWidthSelect).toBeDisabled()
    })
  })
})

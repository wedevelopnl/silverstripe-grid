import { expect, test } from '@playwright/test'
import { activateViewport, firstNonDefaultViewport, readAdapterConfig } from '../helpers/adapter'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

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

    const settingsTable = page.locator('.grid-settings-field__overrides')
    const rows = settingsTable.locator('tbody tr')
    const defaultRow = settingsTable.locator('tbody tr.is-default')
    const defaultWidthSelect = page.locator(
      `select[name="GridSettings[${adapter.defaultViewport}][width]"]`,
    )
    const overrideToggle = page.locator(`input[name="GridSettings[${overrideKey}][override]"]`)
    const overrideWidthSelect = page.locator(`select[name="GridSettings[${overrideKey}][width]"]`)
    const overrideVisible = page.locator(`input[name="GridSettings[${overrideKey}][visible]"]`)

    await test.step('Grid tab renders one row per adapter viewport with correct baseline state', async () => {
      await page.getByRole('tab', { name: 'Grid' }).click()

      await expect(settingsTable).toBeVisible()
      await expect(rows).toHaveCount(adapter.viewports.length)

      // Default row: "default" badge, no override toggle (always overridden).
      await expect(defaultRow).toHaveCount(1)
      await expect(defaultRow.locator('.badge')).toHaveText('default')
      await expect(defaultRow.locator('.grid-settings-field__override-toggle')).toHaveCount(0)
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
      await expect(page.locator('.toast__content')).toContainText('Saved', {
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
      await expect(page.locator('.toast__content')).toContainText('Saved', {
        timeout: 15_000,
      })

      // SilverStripe re-renders the form HTML from server state after save,
      // so the in-form row reflects the persisted cleared override.
      await expect(overrideToggle).not.toBeChecked()
      await expect(overrideWidthSelect).toBeDisabled()
    })
  })
})

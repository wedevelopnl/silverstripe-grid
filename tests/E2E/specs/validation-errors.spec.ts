import { expect, test } from '@playwright/test'
import { loadAndNavigate, loadFixture, resetFixtures } from '../helpers/fixtures'
import { activateDragByTitle } from '../helpers/drag'
import { readAdapterConfig } from '../helpers/adapter'

/**
 * Covers the user-visible side of validation errors:
 *
 *  1. Reorder API validation error → error toast appears and the
 *     optimistic tree reverts to its previous state (rollback).
 *  2. GridSettings field validator → saving a width + offset combination
 *     that exceeds the grid's column count surfaces a user-visible error.
 *
 * Scenario 1 deviates from the original plan ("drop a Row onto the root
 * page zone"): the grid editor's collision detection never exposes the
 * root page zone as a drop target for Rows, so that violation is not
 * reachable through the real UI. Instead, we perform a realistic
 * cross-section row drag and intercept the PATCH /api/reorder response
 * with a simulated hierarchy violation. This exercises the exact code
 * path a user would hit when the backend rejects a reorder — toast
 * display and TanStack Query snapshot restore.
 */
test.describe('Validation errors', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test.describe('Reorder error toast and rollback', () => {
    test.skip(
      ({ browserName }) => browserName !== 'chromium',
      'DnD pointer simulation is Chromium-specific',
    )

    test.use({ viewport: { width: 1280, height: 1400 } })

    test('shows error toast and restores previous tree when reorder is rejected', async ({
      page,
    }) => {
      await loadAndNavigate(page, 'validation-errors')

      const sectionAlpha = page.getByTestId('section-block').filter({ hasText: 'Section Alpha' })
      const sectionBeta = page.getByTestId('section-block').filter({ hasText: 'Section Beta' })

      // Sanity: initial tree state — one row per section.
      await expect(sectionAlpha.getByTestId('row-title')).toHaveText(['Row Alpha-1'])
      await expect(sectionBeta.getByTestId('row-title')).toHaveText(['Row Beta-1'])

      // Intercept the reorder mutation and simulate a hierarchy violation.
      // The client extracts `message` from the JSON body and passes it to
      // showToast via ApiError.
      const violationMessage = 'Row cannot be placed at page level.'
      await page.route('**/admin/grid/api/reorder', async (route) => {
        await route.fulfill({
          status: 400,
          contentType: 'application/json',
          body: JSON.stringify({ message: violationMessage }),
        })
      })

      // Perform a realistic cross-section drag: move Row Beta-1 into
      // Section Alpha. Under normal circumstances this is allowed; the
      // mocked response forces the error path.
      await activateDragByTitle(page, 'Row Beta-1', { overlayTestId: 'drag-overlay-row' })

      const alphaRow = sectionAlpha.getByTestId('row-block').first()
      const alphaBox = await alphaRow.boundingBox()
      expect(alphaBox).not.toBeNull()
      await page.mouse.move(alphaBox!.x + alphaBox!.width / 2, alphaBox!.y + alphaBox!.height / 2, {
        steps: 30,
      })

      // The cross-container pending move has applied once Section Alpha shows
      // both rows (its own + the dragged Beta row). Asserting on that visible
      // state replaces a fixed hover-settle sleep.
      await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(2)

      // Release — the mocked 400 response triggers the mutation's onError
      // handler: snapshot restore + showToast(error.message).
      await page.mouse.up()

      // Assert: user sees the error toast with the backend-provided message.
      // The toast is rendered by the SilverStripe admin's own Redux toast
      // component (third-party markup), so we target the message text it
      // displays rather than a class-based selector we do not own.
      await expect(page.getByText(violationMessage)).toBeVisible({
        timeout: 10_000,
      })

      // Assert: the tree rolled back — both sections contain exactly
      // their original rows.
      await expect(sectionAlpha.getByTestId('row-title')).toHaveText(['Row Alpha-1'])
      await expect(sectionBeta.getByTestId('row-title')).toHaveText(['Row Beta-1'])

      await page.unroute('**/admin/grid/api/reorder')
    })
  })

  test.describe('GridSettings field validation', () => {
    test('shows an error when width + offset exceeds the column count', async ({ page }) => {
      const fixture = await loadFixture(page.request, 'validation-errors')
      const columnId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\Column']['col_alpha_1']

      await page.goto(
        `/admin/pages/edit/EditForm/${fixture.pageId}/field/GridEditor/item/${columnId}/edit`,
      )
      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 })

      // Read adapter config so field names and column count come from the
      // running adapter rather than being hardcoded to a specific preset.
      const adapter = await readAdapterConfig(page)
      const defaultKey = adapter.defaultViewport

      await page.getByRole('tab', { name: 'Grid' }).click()

      // The default-viewport width/offset controls are form <select>s located
      // by their submit `name` (a stable semantic hook, not a styling class).
      const defaultWidthSelect = page.locator(`select[name="GridSettings[${defaultKey}][width]"]`)
      const defaultOffsetSelect = page.locator(`select[name="GridSettings[${defaultKey}][offset]"]`)

      // The Grid tab has rendered once its width control is on screen.
      await expect(defaultWidthSelect).toBeVisible()

      // Pick a width + offset combination that provably exceeds the grid
      // regardless of column count: half + 2/3 columns > total. The default
      // viewport row has no override toggle, so its controls are always
      // enabled and selectOption works directly.
      const invalidWidth = Math.floor(adapter.columnCount / 2)
      const invalidOffset = Math.ceil((adapter.columnCount * 2) / 3)
      await defaultWidthSelect.selectOption(String(invalidWidth))
      await defaultOffsetSelect.selectOption(String(invalidOffset))

      // Save — the backend field validator rejects the write and SilverStripe
      // surfaces the user-visible error message.
      await page.getByRole('button', { name: /Save/ }).first().click()

      const errorText = new RegExp(
        `Width ${invalidWidth} plus offset ${invalidOffset} .* exceeds ${adapter.columnCount} columns`,
      )
      await expect(page.getByText(errorText)).toBeVisible({ timeout: 10_000 })
    })
  })
})

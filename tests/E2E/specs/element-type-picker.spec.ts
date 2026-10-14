import { expect, test } from '@playwright/test'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

/**
 * A short window stands in for a project with many element types: either way
 * the picker's content outgrows the dialog's max-height, and whatever sits
 * below the fold must still be reachable by scrolling.
 */
const SHORT_WINDOW = { width: 1280, height: 300 }

test.describe('Element type picker', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('editor scrolls a picker taller than the window to reach its last entry', async ({
    page,
  }) => {
    await page.setViewportSize(SHORT_WINDOW)
    const fixture = await loadFixture(page.request, 'content-elements')
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    await page.getByTestId('add-content-button').first().click()
    const picker = page.getByTestId('element-type-picker')
    await expect(picker).toBeVisible()

    const lastEntry = picker.getByTestId('element-type-tile-shared')
    await expect(lastEntry).not.toBeInViewport({ ratio: 1 })

    await picker.hover()
    await page.mouse.wheel(0, 1000)

    await expect(lastEntry).toBeInViewport({ ratio: 1 })
  })
})

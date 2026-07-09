import { expect, test } from '@playwright/test'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

test.describe('Page lifecycle', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('editor can open page, see grid editor, and publish to frontend', async ({ page }) => {
    // Use page.request to share browser context cookies and user agent,
    // preventing SilverStripe's strict_user_agent_check from invalidating the session
    const fixture = await loadFixture(page.request, 'element-tree')

    await test.step('open the page and see the grid editor', async () => {
      await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
      await expect(page.getByRole('textbox', { name: 'Page name' })).toHaveValue(
        'E2E Grid Test Page',
      )
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      await expect(page.getByTestId('section-block').first()).toBeVisible()
      await expect(
        page.getByTestId('section-block').filter({ hasText: 'Main Section' }),
      ).toBeVisible()
    })

    await test.step('publish the page', async () => {
      await page.getByRole('button', { name: /Publish/ }).click()
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({ timeout: 10_000 })
    })

    await test.step('verify the published page on the frontend', async () => {
      const livePath = fixture.pageUrl.split('?')[0]
      await page.goto(livePath)

      await expect(
        page.getByRole('heading', { level: 1, name: /E2E Grid Test Page/ }),
      ).toBeVisible()
      await expect(page.getByRole('heading', { level: 2 })).toHaveText('Main Section')
    })
  })
})

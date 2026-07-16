import { expect, test } from '@playwright/test'
import { loadAndNavigate, resetFixtures } from '../helpers/fixtures'
import { enablePreviewMode, waitForPreviewRefresh } from '../helpers/preview'

test.describe('Duplicate section in same zone', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('duplicate section in same zone creates a full copy with subtree', async ({ page }) => {
    const fixture = await loadAndNavigate(page, 'duplicate-test')
    await enablePreviewMode(page)

    await test.step('Duplicate "Source Section" via the block toolbar', async () => {
      const previewRefresh = waitForPreviewRefresh(page)

      const section = page.getByTestId('section-block').filter({ hasText: 'Source Section' })
      await section.getByTestId('section-header').getByTestId('element-action-duplicate').click()

      // Wait for the duplicate to appear
      await expect(page.getByTestId('section-block')).toHaveCount(2, { timeout: 10_000 })

      // Preview pane must refresh to reflect the new section
      await previewRefresh
    })

    await test.step('Verify the copy appears after the original with correct title', async () => {
      const sections = page.getByTestId('section-block')
      const titles = sections.getByTestId('section-title')
      await expect(titles).toHaveText(['Source Section', 'Source Section copy'])
    })

    await test.step('Verify duplicated section has full subtree (row with 2 columns)', async () => {
      const copy = page.getByTestId('section-block').filter({ hasText: 'Source Section copy' })
      await expect(copy.getByTestId('row-block')).toHaveCount(1)
      await expect(copy.getByTestId('column-block')).toHaveCount(2)

      // Content elements should be duplicated too
      await expect(copy.getByTestId('element-card')).toHaveCount(2)
    })

    await test.step('Reload and verify persistence', async () => {
      await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      await expect(page.getByTestId('section-block')).toHaveCount(2)
      const titles = page.getByTestId('section-block').getByTestId('section-title')
      await expect(titles).toHaveText(['Source Section', 'Source Section copy'])
    })
  })
})

test.describe('Duplicate section to another page and zone', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('duplicate section to different page and zone via dialog', async ({ page }) => {
    const fixture = await loadAndNavigate(page, 'duplicate-test')
    const targetPageId = fixture.fixtureMap['WeDevelop\\Grid\\Dev\\MultiZonePage'].e2e_target_page

    await test.step('Open "Duplicate to…" dialog from Source Section', async () => {
      const section = page.getByTestId('section-block').filter({ hasText: 'Source Section' })
      await section.getByTestId('section-header').getByTestId('actions-menu-trigger').click()
      await page.getByRole('menuitem', { name: 'Duplicate to\u2026', exact: true }).click()

      const dialog = page.getByTestId('duplicate-to-dialog')
      await expect(dialog).toBeVisible()
      await expect(page.getByTestId('duplicate-to-step-page')).toBeVisible()
    })

    await test.step('Search and select target page', async () => {
      const dialog = page.getByTestId('duplicate-to-dialog')

      await dialog.getByTestId('duplicate-to-search').fill('E2E Duplicate Target')
      // Wait for debounced search results
      await expect(
        dialog
          .getByTestId('duplicate-to-page-item')
          .filter({ hasText: 'E2E Duplicate Target Page' }),
      ).toBeVisible({ timeout: 5_000 })

      await dialog
        .getByTestId('duplicate-to-page-item')
        .filter({ hasText: 'E2E Duplicate Target Page' })
        .click()
      await dialog.getByTestId('duplicate-to-next').click()
    })

    await test.step('Select sidebar zone', async () => {
      const dialog = page.getByTestId('duplicate-to-dialog')
      // MultiZonePage has 2 zones, so zone step should be visible
      await expect(dialog.getByTestId('duplicate-to-step-zone')).toBeVisible({ timeout: 5_000 })

      await dialog.getByTestId('duplicate-to-zone-item').filter({ hasText: 'sidebar' }).click()
      await dialog.getByTestId('duplicate-to-next').click()
    })

    await test.step('Confirm duplication (sections skip container step)', async () => {
      const dialog = page.getByTestId('duplicate-to-dialog')
      // For sections, the confirm step shows directly (no container selection)
      await expect(dialog.getByTestId('duplicate-to-step-confirm')).toBeVisible({ timeout: 5_000 })

      await dialog.getByTestId('duplicate-to-confirm').click()

      // Dialog should close on success
      await expect(dialog).toBeHidden({ timeout: 10_000 })
    })

    await test.step('Navigate to target page and verify copy in sidebar zone', async () => {
      await page.goto(`/admin/pages/edit/show/${targetPageId}`)
      await expect(page.getByTestId('grid-editor-loading')).toHaveCount(0, { timeout: 15_000 })

      // Identify the sidebar zone editor — a page can host multiple grid
      // editors (one per zone), so combine the testid match with the
      // zone attribute via Playwright's `and()` instead of a raw compound
      // CSS selector.
      const sidebarZone = page.getByTestId('grid-editor').and(page.locator('[data-zone="sidebar"]'))
      await expect(sidebarZone).toBeVisible()

      // Sidebar should now have 2 sections: original + copy
      const sidebarSections = sidebarZone.getByTestId('section-block')
      await expect(sidebarSections).toHaveCount(2)

      // The copy should exist with duplicated title
      const copy = sidebarZone
        .getByTestId('section-block')
        .filter({ hasText: 'Source Section copy' })
      await expect(copy).toBeVisible()

      // Verify the copy has the full subtree
      await expect(copy.getByTestId('row-block')).toHaveCount(1)
      await expect(copy.getByTestId('column-block')).toHaveCount(2)
      await expect(copy.getByTestId('element-card')).toHaveCount(2)
    })
  })
})

test.describe('Duplicate content element to another column', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('duplicate content element to different column on same page via dialog', async ({
    page,
  }) => {
    const fixture = await loadAndNavigate(page, 'duplicate-test')

    await test.step('Open "Duplicate to…" dialog from Element Alpha', async () => {
      const colA = page.getByTestId('column-block').filter({ hasText: 'Col A' })
      const elementAlpha = colA.getByTestId('element-card').filter({ hasText: 'Element Alpha' })

      await elementAlpha.getByTestId('actions-menu-trigger').click()
      await page.getByRole('menuitem', { name: 'Duplicate to\u2026', exact: true }).click()

      const dialog = page.getByTestId('duplicate-to-dialog')
      await expect(dialog).toBeVisible()
    })

    await test.step('Select current page and advance', async () => {
      const dialog = page.getByTestId('duplicate-to-dialog')
      // Current page should be pre-selected — click Next
      await expect(dialog.getByTestId('duplicate-to-step-page')).toBeVisible()
      await dialog.getByTestId('duplicate-to-next').click()
    })

    await test.step('Single zone auto-skips to container step', async () => {
      const dialog = page.getByTestId('duplicate-to-dialog')
      // Regular Page has only "main" zone — zone step should auto-skip
      await expect(dialog.getByTestId('duplicate-to-step-container')).toBeVisible({
        timeout: 5_000,
      })
    })

    await test.step('Select Col B as target container and confirm', async () => {
      const dialog = page.getByTestId('duplicate-to-dialog')

      await dialog.getByTestId('duplicate-to-container-item').filter({ hasText: 'Col B' }).click()
      await dialog.getByTestId('duplicate-to-confirm').click()

      // Dialog should close on success
      await expect(dialog).toBeHidden({ timeout: 10_000 })
    })

    await test.step('Verify Col B now contains both Element Beta and Element Alpha copy', async () => {
      // Wait for tree refetch
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      const colB = page.getByTestId('column-block').filter({ hasText: 'Col B' })
      const elements = colB.getByTestId('element-card')
      await expect(elements).toHaveCount(2, { timeout: 10_000 })

      // Both elements should be present
      await expect(
        colB.getByTestId('element-card').filter({ hasText: 'Element Beta' }),
      ).toBeVisible()
      await expect(
        colB.getByTestId('element-card').filter({ hasText: 'Element Alpha copy' }),
      ).toBeVisible()
    })

    await test.step('Reload and verify persistence', async () => {
      await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      const colB = page.getByTestId('column-block').filter({ hasText: 'Col B' })
      await expect(colB.getByTestId('element-card')).toHaveCount(2)
      await expect(
        colB.getByTestId('element-card').filter({ hasText: 'Element Beta' }),
      ).toBeVisible()
      await expect(
        colB.getByTestId('element-card').filter({ hasText: 'Element Alpha copy' }),
      ).toBeVisible()
    })
  })
})

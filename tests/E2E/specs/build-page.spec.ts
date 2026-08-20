import { expect, test } from '@playwright/test'
import { resetFixtures } from '../helpers/fixtures'

test.describe('Build page from scratch', () => {
  // The page title "E2E Build Test" generates URL segment "e2e-build-test",
  // which resetFixtures identifies and deletes by the "e2e-" prefix.
  //
  // Reset before every attempt, not only after the spec: the frontend URL below
  // is hardcoded, so an attempt that fails after saving leaves that segment
  // taken. SilverStripe would hand the retry's page "e2e-build-test-2", and the
  // retry would then assert against the previous attempt's unpublished draft —
  // failing on "Page not found" instead of retrying the flake cleanly.
  test.beforeEach(async ({ request }) => {
    await resetFixtures(request)
  })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('editor creates a new page, builds a grid structure, and publishes to frontend', async ({
    page,
  }) => {
    await test.step('create and save a new page', async () => {
      // Step 1: Navigate to Pages admin and wait for the site tree to load
      await page.goto('/admin/pages')
      await expect(page.getByRole('link', { name: 'Add new Page', exact: true })).toBeVisible({
        timeout: 15_000,
      })
      await page.getByRole('link', { name: 'Add new Page', exact: true }).click()

      // The wizard appears: Step 1 (location) defaults to "Top level",
      // Step 2 (type) defaults to "Page". Click "Create" to proceed.
      await expect(page.getByRole('button', { name: 'Create', exact: true })).toBeVisible({
        timeout: 10_000,
      })
      await page.getByRole('button', { name: 'Create', exact: true }).click()

      // Wait for the page editor to load, then enter a title
      await expect(page).toHaveURL(/\/admin\/pages\/edit\/show\/\d+/, { timeout: 10_000 })
      await expect(page.getByRole('textbox', { name: 'Page name', exact: true })).toBeVisible({
        timeout: 10_000,
      })
      await page.getByRole('textbox', { name: 'Page name', exact: true }).fill('E2E Build Test')

      // Save the page to persist the title
      await page.getByRole('button', { name: /Save/ }).first().click()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
    })

    await test.step('build the grid structure', async () => {
      // Step 2: Empty page — no sections, just the "Add Section" empty state
      await expect(page.getByTestId('section-block')).toHaveCount(0)
      await expect(
        page.getByTestId('add-child-empty').filter({ hasText: 'Add Section' }),
      ).toBeVisible()

      // Step 3: Add first section — verify it arrives with auto-scaffolded children
      await page.getByTestId('add-child-button').filter({ hasText: 'Add Section' }).click()

      const firstSection = page.getByTestId('section-block').first()
      await expect(firstSection).toBeVisible({ timeout: 10_000 })
      await expect(firstSection.getByTestId('row-block')).toHaveCount(1)
      await expect(firstSection.getByTestId('column-block')).toHaveCount(1)

      // The section-level empty state is replaced by an append button
      await expect(
        page.getByTestId('add-child-empty').filter({ hasText: 'Add Section' }),
      ).toHaveCount(0)
      await expect(
        page.getByTestId('add-child-append').filter({ hasText: 'Add Section' }),
      ).toBeVisible()

      // Step 4: Add a second section — verify it also has its own scaffolded row + column
      await page.getByTestId('add-child-append').filter({ hasText: 'Add Section' }).click()
      await expect(page.getByTestId('section-block')).toHaveCount(2, { timeout: 10_000 })

      const secondSection = page.getByTestId('section-block').nth(1)
      await expect(secondSection.getByTestId('row-block')).toHaveCount(1)
      await expect(secondSection.getByTestId('column-block')).toHaveCount(1)

      // Step 5: Add a second row to the first section — verify it has a scaffolded column
      await firstSection.getByTestId('add-child-append').filter({ hasText: 'Add Row' }).click()
      await expect(firstSection.getByTestId('row-block')).toHaveCount(2, { timeout: 10_000 })

      const newRow = firstSection.getByTestId('row-block').nth(1)
      await expect(newRow.getByTestId('column-block')).toHaveCount(1)

      // Step 6: Add a second column to the first row via the row's trailing "+" square
      const firstRow = firstSection.getByTestId('row-block').first()
      await firstRow.getByTestId('column-insert-end').click()
      await expect(firstRow.getByTestId('column-block')).toHaveCount(2, { timeout: 10_000 })

      // Step 7: Prepend a section via the leading slot — the previously-first
      // section must shift down to index 1, which is what distinguishes this
      // from the append button.
      const previouslyFirstTitle = await firstSection.getByTestId('section-title').innerText()
      await page.getByTestId('add-child-before-first').filter({ hasText: 'Add Section' }).click()
      await expect(page.getByTestId('section-block')).toHaveCount(3, { timeout: 10_000 })
      await expect(
        page.getByTestId('section-block').nth(1).getByTestId('section-title'),
      ).toHaveText(previouslyFirstTitle)
    })

    await test.step('publish and verify on the frontend', async () => {
      // Step 8: Publish the page
      await page.getByRole('button', { name: /Publish/ }).click()
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({
        timeout: 10_000,
      })

      // Step 9: Verify the published page renders on the frontend
      await page.goto('/e2e-build-test')
      await expect(
        page.getByRole('heading', { level: 1, name: 'E2E Build Test', exact: true }),
      ).toBeVisible()
    })
  })
})

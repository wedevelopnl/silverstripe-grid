import { expect, test } from '@playwright/test'
import { loadFixture, loadAndNavigate, resetFixtures } from '../helpers/fixtures'
import { selectChosenValue } from '../helpers/forms'

/** Minimal shape of the TinyMCE global the CMS injects, for typed `window` access. */
interface TinyMceWindow {
  tinymce?: {
    activeEditor?: {
      initialized?: boolean
      setContent(html: string): void
      fire(event: string): void
    }
  }
}

test.describe('Content elements — add, edit, publish, render', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('content editor adds elements, configures titles, edits content, publishes, and verifies frontend rendering', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'content-elements')
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    // Cross-step locators declared at body scope so all steps can reference them.
    const elementCards = page.getByTestId('element-card')
    const addButtons = page.getByTestId('add-content-button')
    const picker = page.getByRole('dialog')

    await test.step('Verify populated column shows existing elements', async () => {
      await expect(elementCards).toHaveCount(2)
      await expect(page.getByText('Existing Text Block')).toBeVisible()
      await expect(page.getByText('Existing Image Block')).toBeVisible()
    })

    await test.step('Add element to empty column', async () => {
      // Both columns should have an add button (one empty, one populated)
      await expect(addButtons).toHaveCount(2)

      // Click the first add button (empty column)
      await addButtons.first().click()

      // Modal should open — use getByRole('dialog') since only one dialog is open at a time
      await expect(picker).toBeVisible()

      // At least one type tile should be available
      const tiles = picker.getByTestId('element-type-tile')
      await expect(tiles.first()).toBeVisible()

      // Select the first type
      await tiles.first().click()

      // Modal should close
      await expect(picker).toBeHidden()

      // Wait for tree to reload — empty column now has an element
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      await expect(elementCards).toHaveCount(3)
    })

    await test.step('Dismiss modal without creating', async () => {
      await addButtons.first().click()
      await expect(picker).toBeVisible()

      // Press Escape to close
      await page.keyboard.press('Escape')
      await expect(picker).toBeHidden()

      // No new element created
      await expect(elementCards).toHaveCount(3)
    })

    await test.step('Open the element edit form via the block toolbar', async () => {
      const firstCard = elementCards.first()
      await firstCard.getByTestId('element-action-edit').click()

      // Should navigate to the element edit page via the page editor
      await expect(page).toHaveURL(
        /\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/,
      )
    })

    await test.step('Fill in the edit form including title settings', async () => {
      // Wait for the form to be ready
      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 })
      await page.getByRole('textbox', { name: 'Title' }).fill('My Edited Element')

      // Configure title display: set heading level to h4 and enable visibility
      await selectChosenValue(page, 'TitleTag', 'h4')
      await page.locator('input[name="ShowTitle"]').check()

      // Set HTML content — try TinyMCE API first, fall back to textarea
      const hasTinyMce = await page
        .waitForFunction(
          () => (window as unknown as TinyMceWindow).tinymce?.activeEditor?.initialized,
          null,
          { timeout: 5_000 },
        )
        .then(() => true)
        .catch(() => false)

      if (hasTinyMce) {
        await page.evaluate(() => {
          const editor = (window as unknown as TinyMceWindow).tinymce?.activeEditor
          editor?.setContent('<p>Hello from the grid</p>')
          editor?.fire('change')
        })
      } else {
        const htmlField = page.locator('textarea[name="HTML"]')
        await htmlField.fill('<p>Hello from the grid</p>')
      }

      // Save the element
      await page.getByRole('button', { name: /Save/ }).first().click()

      // Wait for save to complete — the SilverStripe admin's own toast (third-party
      // markup) confirms success; assert on its visible "Saved" message.
      await expect(page.getByText(/Saved/).first()).toBeVisible({ timeout: 15_000 })
    })

    await test.step('Navigate back to page editor via breadcrumb', async () => {
      await page.getByRole('link', { name: 'E2E Content Elements Page' }).click()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
    })

    await test.step('Publish the page', async () => {
      await page.getByRole('button', { name: /Publish/ }).click()
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({ timeout: 15_000 })
    })

    await test.step('Verify frontend rendering', async () => {
      // Strip ?stage=Stage from the fixture URL to get the live URL
      const liveUrl = fixture.pageUrl.split('?')[0]
      await page.goto(liveUrl)

      // Verify the edited element renders its content
      await expect(page.getByText('Hello from the grid')).toBeVisible()

      // Verify pre-populated elements render their body content
      await expect(page.getByText('Text block body content')).toBeVisible()
      await expect(page.getByText('Image block body content')).toBeVisible()

      // Verify title configuration: section title renders as h3 (set in fixture)
      await expect(page.getByRole('heading', { level: 3, name: 'Content Section' })).toBeVisible()

      // Verify title configuration: edited element title renders as h4 (set in CMS form)
      await expect(page.getByRole('heading', { level: 4, name: 'My Edited Element' })).toBeVisible()
    })
  })
})

test.describe('Content elements — edit container titles via title links', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('content editor navigates to container edit forms via title links and updates titles', async ({
    page,
  }) => {
    await loadAndNavigate(page, 'content-elements')

    await test.step('Edit section title via title link', async () => {
      await page.getByTestId('section-edit-link').click()
      await expect(page).toHaveURL(
        /\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/,
      )

      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 })
      await page.getByRole('textbox', { name: 'Title' }).fill('Updated Section Title')
      await page.getByRole('button', { name: /Save/ }).first().click()
      await expect(
        page.getByText('Saved Section "Updated Section Title" successfully.'),
      ).toBeVisible({ timeout: 15_000 })

      await page.getByRole('link', { name: 'E2E Content Elements Page' }).click()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      await expect(page.getByTestId('section-title')).toContainText('Updated Section Title')
    })

    await test.step('Edit row title via title link', async () => {
      await page.getByTestId('row-edit-link').first().click()
      await expect(page).toHaveURL(
        /\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/,
      )

      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 })
      await page.getByRole('textbox', { name: 'Title' }).fill('Updated Row Title')
      await page.getByRole('button', { name: /Save/ }).first().click()
      await expect(page.getByText('Saved Row "Updated Row Title" successfully.')).toBeVisible({
        timeout: 15_000,
      })

      await page.getByRole('link', { name: 'E2E Content Elements Page' }).click()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      await expect(page.getByTestId('row-title').first()).toContainText('Updated Row Title')
    })

    await test.step('Edit column title via title link', async () => {
      await page.getByTestId('column-edit-link').first().click()
      await expect(page).toHaveURL(
        /\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/,
      )

      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 })
      await page.getByRole('textbox', { name: 'Title' }).fill('Updated Column Title')
      await page.getByRole('button', { name: /Save/ }).first().click()
      await expect(page.getByText('Saved Column "Updated Column Title" successfully.')).toBeVisible(
        { timeout: 15_000 },
      )

      await page.getByRole('link', { name: 'E2E Content Elements Page' }).click()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
      await expect(page.getByTestId('column-title').first()).toContainText('Updated Column Title')
    })
  })
})

import { expect, test } from '@playwright/test'
import { loadFixture, resetFixtures } from '../helpers/fixtures'
import { selectChosenValue } from '../helpers/forms'

test.describe('Media elements', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('content editor configures media layout and verifies frontend rendering', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'media-elements')
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    await test.step('Verify fixture loaded with image attached', async () => {
      const elementCards = page.getByTestId('element-card')
      await expect(elementCards).toHaveCount(2)
      const cardTitles = page.getByTestId('element-card-title')
      await expect(cardTitles).toHaveText(['Media Element', 'Text Element'])

      // Open the media element edit form
      await elementCards.first().click()
      await expect(page).toHaveURL(
        /\/admin\/pages\/edit\/EditForm\/\d+\/field\/GridEditor\/item\/\d+\/edit/,
      )
      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 })

      // Navigate to Media tab and verify image is attached
      await page.getByRole('tab', { name: 'Media' }).click()

      // The attached file appears in SilverStripe's own UploadField widget,
      // whose markup (.uploadfield-item__title) is third-party and carries no
      // test hook of ours — scope to it to confirm a file is present.
      const uploadField = page.locator('.uploadfield-item__title')
      await expect(uploadField).toBeVisible({ timeout: 10_000 })
    })

    await test.step('Verify MediaType toggles VideoCustomThumbnail visibility', async () => {
      // We're already on the Media tab from Step 1
      const videoThumbnailHolder = page.locator('[id$="_VideoCustomThumbnail_Holder"]')

      // Initial state: fixture sets MediaType=image, so VideoCustomThumbnail is hidden
      await expect(videoThumbnailHolder).toBeHidden()

      // Switch to video — VideoCustomThumbnail becomes visible
      await selectChosenValue(page, 'MediaType', 'video')
      await expect(videoThumbnailHolder).toBeVisible()

      // Switch back to image — VideoCustomThumbnail is hidden again
      await selectChosenValue(page, 'MediaType', 'image')
      await expect(videoThumbnailHolder).toBeHidden()
    })

    await test.step('Verify column width picker UX behavior', async () => {
      await page.getByRole('tab', { name: 'Layout' }).click()

      // The picker renders one radio per width option. Its accessible name is
      // composed of the option's descriptive label plus the split ratio (e.g.
      // "8/4 (content/media) 8/4", "Full width (no side-by-side) ..."), so we
      // match the ratio as a substring via regex rather than an exact string.
      // The radios are visually hidden (opacity:0) and toggled by clicking the
      // visible label text, so we drive the selection by clicking that text.
      const fullWidthOption = page.getByRole('radio', { name: /Full width/ })
      const splitOption8 = page.getByRole('radio', { name: /8\/4/ })
      const splitOption6 = page.getByRole('radio', { name: /6\/6/ })
      const fullWidthLabel = page.getByText(/Full width/)
      const splitLabel8 = page.getByText('8/4', { exact: true })
      const splitLabel6 = page.getByText('6/6', { exact: true })

      await expect(fullWidthLabel).toBeVisible()

      // Conditional layout fields are toggled by display-logic. Their holders
      // carry no test hook of ours; the SilverStripe form prefixes the holder
      // div id with the form name, so we match the field name by id suffix.
      const mediaPositionHolder = page.locator('[id$="_MediaPosition_Holder"]')
      const verticalAlignmentHolder = page.locator('[id$="_VerticalAlignment_Holder"]')
      const gapSizeHolder = page.locator('[id$="_GapSize_Holder"]')

      // Initial state: full width is selected, dependent fields are hidden
      await expect(fullWidthOption).toBeChecked()
      await expect(mediaPositionHolder).toBeHidden()
      await expect(verticalAlignmentHolder).toBeHidden()
      await expect(gapSizeHolder).toBeHidden()

      // Select a column split — dependent fields appear, selected state moves
      await splitLabel8.click()
      await expect(splitOption8).toBeChecked()
      await expect(fullWidthOption).not.toBeChecked()
      await expect(mediaPositionHolder).toBeVisible()
      await expect(verticalAlignmentHolder).toBeVisible()
      await expect(gapSizeHolder).toBeVisible()

      // Switch to a different split — selected state follows
      await splitLabel6.click()
      await expect(splitOption6).toBeChecked()
      await expect(splitOption8).not.toBeChecked()
      // Dependent fields remain visible for any non-zero split
      await expect(mediaPositionHolder).toBeVisible()

      // Switch back to full width — dependent fields hide again
      await fullWidthLabel.click()
      await expect(fullWidthOption).toBeChecked()
      await expect(splitOption6).not.toBeChecked()
      await expect(mediaPositionHolder).toBeHidden()
      await expect(verticalAlignmentHolder).toBeHidden()
      await expect(gapSizeHolder).toBeHidden()
    })

    await test.step('Configure layout settings', async () => {
      // Select 8/4 split for the save+render test (click the visible label;
      // the underlying radio is opacity:0).
      await page.getByText('8/4', { exact: true }).click()
      await expect(page.locator('[id$="_MediaPosition_Holder"]')).toBeVisible()

      await selectChosenValue(page, 'MediaPosition', 'last')
      await selectChosenValue(page, 'VerticalAlignment', 'center')
      await selectChosenValue(page, 'GapSize', '3')

      await page.getByRole('button', { name: /Save/ }).first().click()
      // The save confirmation is the SilverStripe admin's own toast (third-party
      // markup), so assert on the visible "Saved" message rather than its class.
      await expect(page.getByText(/Saved/).first()).toBeVisible({ timeout: 15_000 })
    })

    await test.step('Configure media settings', async () => {
      await page.getByRole('tab', { name: 'Media' }).click()

      await selectChosenValue(page, 'MediaRatio', '16x9')
      await page.getByLabel('Caption', { exact: true }).fill('Test media caption')

      await page.getByRole('button', { name: /Save/ }).first().click()
      // The save confirmation is the SilverStripe admin's own toast (third-party
      // markup), so assert on the visible "Saved" message rather than its class.
      await expect(page.getByText(/Saved/).first()).toBeVisible({ timeout: 15_000 })
    })

    await test.step('Navigate back and publish page', async () => {
      await page.getByRole('link', { name: 'E2E Media Elements Page' }).click()
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      await page.getByRole('button', { name: /Publish/ }).click()
      await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({ timeout: 15_000 })
    })

    await test.step('Verify frontend rendering with media layout', async () => {
      const liveUrl = fixture.pageUrl.split('?')[0]
      await page.goto(liveUrl)

      // The image is what the visitor sees — locate it by its accessible role
      // and the alt text derived from the caption.
      const img = page.getByRole('img', { name: 'Test media caption' })
      await expect(img).toBeVisible()

      // Image src is populated (resized/served by SilverStripe)
      const src = await img.getAttribute('src')
      expect(src).toBeTruthy()

      // The image sits inside a <figure> (implicit ARIA role) with its caption.
      const mediaFigure = page.getByRole('figure').filter({ has: img })
      await expect(mediaFigure).toContainText('Test media caption')

      // Side-by-side layout: the content-element has a row wrapper with two child divs.
      // Identified structurally (direct-child div of .content-element containing the
      // media figure) rather than by class name — adapters emit different row classes
      // (Bootstrap `row`, Tailwind `grid grid-cols-*`, etc.), so there is no stable
      // role/label to target; the structural shape IS the behaviour under test.
      const contentElement = page.locator('.content-element').filter({ has: mediaFigure })
      const rowWrapper = contentElement.locator('> div').filter({ has: mediaFigure })
      await expect(rowWrapper).toBeVisible()
      // Row wrapper has exactly 2 direct child divs (media column + content column)
      await expect(rowWrapper.locator('> div')).toHaveCount(2)

      // Content column renders the element body text
      await expect(contentElement).toContainText('Media element body content')

      // The plain text element also renders
      await expect(page.getByText('Text element body content')).toBeVisible()
    })
  })
})

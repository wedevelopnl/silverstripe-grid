import { expect, test } from '@playwright/test'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

test.describe('Form element', () => {
  // A visitor, not the admin the config logs in.
  test.use({ storageState: { cookies: [], origins: [] } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('visitor completes the form and reads the on-complete message on the same page', async ({
    page,
  }) => {
    await loadFixture(page.request, 'userform-element')
    // Not fixture.pageUrl: the loader builds it on DRAFT (?stage=Stage), which
    // sends an anonymous visitor to the login form.
    await page.goto('/e2e-contact')

    const nameField = page.getByRole('textbox', { name: 'Your name', exact: true })
    const sendButton = page.getByRole('button', { name: 'Send message', exact: true })

    await test.step('submitting without the required name is refused with its message', async () => {
      await sendButton.click()

      await expect(page.getByText('Please tell us your name', { exact: true })).toBeVisible()
      await expect(nameField).toBeVisible()
    })

    await test.step('submitting with a name shows the on-complete message in place of the form', async () => {
      await nameField.fill('Jane Doe')
      await sendButton.click()

      await expect(
        page.getByText('Thanks, we will get back to you.', { exact: true }),
      ).toBeVisible()
      await expect(nameField).toBeHidden()
      await expect(sendButton).toBeHidden()
      await expect(
        page.getByText('We answer every message on weekdays.', { exact: true }),
      ).toBeVisible()
    })
  })
})

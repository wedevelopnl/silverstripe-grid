import { expect, type Locator, type Page } from '@playwright/test'

/**
 * Opens an admin-toolbar menu's dialog from its toolbar button and returns the
 * open dialog. The toolbar root has no box of its own (its children are
 * fixed), so the button is looked up inside it rather than asserted visible.
 */
export async function openMenu(page: Page, dialogId: string): Promise<Locator> {
  await page
    .locator('[data-admin-toolbar]')
    .locator(`button[data-toggle-dialog="${dialogId}"]`)
    .first()
    .click()
  const dialog = page.locator(`dialog#${dialogId}`)
  await expect(dialog).toBeVisible()

  return dialog
}

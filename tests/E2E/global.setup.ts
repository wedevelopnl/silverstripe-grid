import { test as setup } from '@playwright/test'
import { authenticateAdmin } from '@wedevelop/e2e'

setup('authenticate as admin', async ({ page }) => {
  // Set per setup project by setupProject() in playwright.config.ts.
  const authFile: string = setup.info().project.metadata.authFile
  await authenticateAdmin(page, { storageStatePath: authFile })
})

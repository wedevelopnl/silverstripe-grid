import { test as setup } from '@playwright/test'
import { authenticateAdmin } from '../../vendor/wedevelopnl/silverstripe-e2e/client/playwright'

const AUTH_FILE = 'tests/E2E/.auth/admin.json'

setup('authenticate as admin', async ({ page }) => {
  await authenticateAdmin(page, { storageStatePath: AUTH_FILE })
})

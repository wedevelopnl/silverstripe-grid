import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

/**
 * End-to-end user journey: a content editor opens an element's edit
 * form and navigates to the History tab to see the version timeline.
 *
 * Uses the complex-page fixture which creates elements with multiple
 * versions via post-actions (publish_recursive → modify), so the
 * history viewer has entries to display.
 */
test.describe('Element history — version timeline on element detail form', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('editor opens element edit form and sees version history', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'complex-page');
    const leafId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\ContentElement']['modified_leaf'];

    await test.step('Navigate to element edit form', async () => {
      await page.goto(
        `/admin/pages/edit/EditForm/${fixture.pageId}/field/GridEditor/item/${leafId}/edit`,
      );
      await page.getByRole('textbox', { name: 'Title' }).waitFor({ timeout: 15_000 });
    });

    await test.step('Open the History tab', async () => {
      await page.getByRole('tab', { name: 'History' }).click();
      await expect(page.locator('.history-viewer__container')).toBeVisible({ timeout: 15_000 });
    });

    await test.step('Verify version rows exist', async () => {
      const versionRows = page.locator('.history-viewer__row');
      await expect(versionRows.first()).toBeVisible({ timeout: 15_000 });

      // modified_leaf was published (v1) then modified (v2) — at least 2 versions.
      const count = await versionRows.count();
      expect(count).toBeGreaterThanOrEqual(2);
    });
  });
});

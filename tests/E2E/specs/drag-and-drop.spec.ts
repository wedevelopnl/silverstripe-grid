import { expect, test } from '@playwright/test'
import { dragHandle, performDrag, startDrag, waitForMutationSettlement } from '../helpers/drag'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

/**
 * Get a column block by the title it was given in the fixture.
 * Columns don't render their title as visible text, so we match
 * via the drag handle's aria-label inside the column.
 */
function columnByTitle(
  parent: import('@playwright/test').Locator,
  page: import('@playwright/test').Page,
  title: string,
) {
  return parent.getByTestId('column-block').filter({
    has: page.locator(`[aria-label="Move ${title}"]`),
  })
}

/** Get all element cards within a container locator, in DOM order. */
function elementCards(container: import('@playwright/test').Locator) {
  return container.getByTestId('element-card')
}

/** Get all element card title locators within a container. */
function elementTitleLocators(container: import('@playwright/test').Locator) {
  return container.getByTestId('element-card-title')
}

test.describe('Drag and drop', () => {
  // The DnD fixture renders a deep hierarchy (~900px tall) that exceeds the
  // default Desktop Chrome viewport (720px). A taller viewport ensures all
  // drag handles are reachable by page.mouse without mid-drag scrolling.
  test.use({ viewport: { width: 1280, height: 2800 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('rearranges content at every hierarchy level and persists across reload', async ({
    page,
  }) => {
    // Load fixture and navigate to CMS page editor
    const fixture = await loadFixture(page.request, 'drag-and-drop')
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    // Locate structural containers
    const sectionAlpha = page.getByTestId('section-block').filter({ hasText: 'Section Alpha' })
    const sectionBeta = page.getByTestId('section-block').filter({ hasText: 'Section Beta' })
    const rowA1 = sectionAlpha.getByTestId('row-block').filter({ hasText: 'Row Alpha-1' })
    const colA1A = columnByTitle(rowA1, page, 'Column A1-A')
    const colA1B = columnByTitle(rowA1, page, 'Column A1-B')

    // Verify initial structure
    await expect(elementTitleLocators(colA1A)).toHaveText(['Block 1', 'Block 2', 'Block 3'])
    await expect(elementCards(colA1B)).toHaveCount(2)
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(2)
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(2)

    // --- ELEMENT REORDER within Col A1-A (Block 1 → Block 3) ---
    const elementReorderDrag = await startDrag(
      page,
      dragHandle(page, 'Block 1'),
      dragHandle(page, 'Block 3'),
    )

    // Drag overlay should appear with element type modifier and correct title
    const elementOverlay = page.getByTestId('drag-overlay-element')
    await expect(elementOverlay).toBeVisible()
    await expect(elementOverlay.getByTestId('drag-overlay-element-title')).toHaveText('Block 1')

    // Register settlement listeners before releasing (mutation fires on drop)
    const settle1 = waitForMutationSettlement(page)
    await elementReorderDrag.release()
    await settle1()

    // Block 1 should no longer be first in Col A1-A
    await expect(
      colA1A.getByTestId('element-card').first().getByTestId('element-card-title'),
    ).not.toHaveText('Block 1')
    // All 3 blocks still present
    await expect(elementCards(colA1A)).toHaveCount(3)

    // --- CROSS-COLUMN ELEMENT MOVE (Col A1-A → Col A1-B: Block 2 → Block 4) ---
    const settle2 = waitForMutationSettlement(page)
    await performDrag(page, dragHandle(page, 'Block 2'), dragHandle(page, 'Block 4'))
    await settle2()

    // Col A1-A loses one element, Col A1-B gains one
    await expect(elementCards(colA1A)).toHaveCount(2)
    await expect(elementCards(colA1B)).toHaveCount(3)

    // --- COLUMN REORDER within Row Alpha-1 ---
    const columns = rowA1.getByTestId('column-block')
    const firstColHandle = columns.first().getByTestId('column-header').getByTestId('drag-handle')
    await expect(firstColHandle).toHaveAttribute('aria-label', 'Move Column A1-A')

    const settle3 = waitForMutationSettlement(page)
    await performDrag(page, dragHandle(page, 'Column A1-A'), dragHandle(page, 'Column A1-B'))
    await settle3()

    // After swap, A1-B should be first
    await expect(firstColHandle).toHaveAttribute('aria-label', 'Move Column A1-B')

    // --- ROW REORDER within Section Alpha ---
    const rows = sectionAlpha.getByTestId('row-block')

    // Pause mid-drag to check row overlay visibility
    const rowDrag = await startDrag(
      page,
      dragHandle(page, 'Row Alpha-1'),
      dragHandle(page, 'Row Alpha-2'),
    )

    const rowOverlay = page.getByTestId('drag-overlay-row')
    await expect(rowOverlay).toBeVisible()

    const settle4 = waitForMutationSettlement(page)
    await rowDrag.release()
    await settle4()

    // Alpha-2 should now be first
    await expect(rows.first().getByTestId('row-title')).toHaveText('Row Alpha-2')

    // --- CROSS-SECTION ROW MOVE (Section Alpha → Section Beta) ---
    // Alpha-2 is now first in Section Alpha; move it to Section Beta
    const settle5 = waitForMutationSettlement(page)
    await performDrag(page, dragHandle(page, 'Row Alpha-2'), dragHandle(page, 'Row Beta-1'))
    await settle5()

    // Alpha loses a row, Beta gains one
    await expect(sectionAlpha.getByTestId('row-block')).toHaveCount(1)
    await expect(sectionBeta.getByTestId('row-block')).toHaveCount(3)

    // --- SECTION REORDER ---
    // Grab Beta mid-drag and assert the DragOverlay becomes visible before
    // releasing — this is the regression gate for the polymorphic-ID
    // collision bug. Prior to the fix, the section ghost never appeared
    // when page.id matched the first section's id, and the drop silently
    // snapped back. Asserting the overlay here closes that gap.
    const sectionHandleBeta = dragHandle(page, 'Section Beta')
    const sectionHandleAlpha = dragHandle(page, 'Section Alpha')
    const betaDrag = await startDrag(page, sectionHandleBeta, sectionHandleAlpha)
    await expect(page.getByTestId('drag-overlay-section')).toBeVisible()
    await expect(page.getByTestId('drag-overlay-section-title')).toHaveText('Section Beta')
    const settle6 = waitForMutationSettlement(page)
    await betaDrag.release()
    await settle6()

    // Beta should now be first
    const sections = page.getByTestId('section-block')
    await expect(sections.first().getByTestId('section-title')).toHaveText('Section Beta')

    // --- CAPTURE final state for persistence check ---
    const sectionTitles = await sections.getByTestId('section-title').allTextContents()

    // --- HARD RELOAD ---
    await page.reload()
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    // Verify all reordered state persisted
    const sectionsReload = page.getByTestId('section-block')
    await expect(sectionsReload.getByTestId('section-title')).toHaveText(sectionTitles)

    // Verify row counts per section survived reload
    const betaReload = sectionsReload.filter({ hasText: 'Section Beta' })
    const alphaReload = sectionsReload.filter({ hasText: 'Section Alpha' })
    await expect(betaReload.getByTestId('row-block')).toHaveCount(3)
    await expect(alphaReload.getByTestId('row-block')).toHaveCount(1)

    // --- PUBLISH the page ---
    await page.getByRole('button', { name: /Publish/ }).click()
    await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({ timeout: 10_000 })

    // --- VERIFY frontend renders reordered structure ---
    // Strip ?stage=Stage to visit the live (published) frontend
    const livePath = fixture.pageUrl.split('?')[0]
    await page.goto(livePath)

    const frontendSectionTitles = page.getByRole('heading', { level: 2 })
    await expect(frontendSectionTitles).toHaveText(sectionTitles)
  })
})

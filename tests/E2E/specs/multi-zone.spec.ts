import { expect, test } from '@playwright/test'
import { dragHandle, performDrag, waitForMutationSettlement } from '../helpers/drag'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

test.describe('Multi-zone isolation', () => {
  // Two zones stacked vertically need a tall viewport
  test.use({ viewport: { width: 1280, height: 3600 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('zones render independently, reorder within zones, and reject cross-zone drags', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'multi-zone')
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`)

    // Wait for both grid editors to finish loading
    const gridEditors = page.getByTestId('grid-editor')
    await expect(page.getByTestId('grid-editor-loading')).toHaveCount(0, { timeout: 15_000 })
    await expect(gridEditors).toHaveCount(2)

    // Identify zones by data-zone attribute — combine the testid locator
    // with the zone attribute via `and()` instead of a raw compound CSS
    // selector so the locator stays role/testid-shaped.
    const mainZone = gridEditors.and(page.locator('[data-zone="main"]'))
    const sidebarZone = gridEditors.and(page.locator('[data-zone="sidebar"]'))

    // --- Phase 1: Verify both zones render independently ---
    const mainSections = mainZone.getByTestId('section-block')
    const sidebarSections = sidebarZone.getByTestId('section-block')

    await expect(mainSections).toHaveCount(2)
    await expect(sidebarSections).toHaveCount(2)

    await expect(mainSections.getByTestId('section-title')).toHaveText(['Main-Alpha', 'Main-Beta'])
    await expect(sidebarSections.getByTestId('section-title')).toHaveText([
      'Sidebar-Alpha',
      'Sidebar-Beta',
    ])

    // --- Phase 2: Reorder within main zone ---
    const settle1 = waitForMutationSettlement(page)
    await performDrag(page, dragHandle(page, 'Main-Alpha'), dragHandle(page, 'Main-Beta'))
    await settle1()

    // Main-Beta should now be first
    await expect(mainSections.getByTestId('section-title')).toHaveText(['Main-Beta', 'Main-Alpha'])
    // Sidebar unchanged
    await expect(sidebarSections.getByTestId('section-title')).toHaveText([
      'Sidebar-Alpha',
      'Sidebar-Beta',
    ])

    // --- Phase 3: Reorder within sidebar zone ---
    const settle2 = waitForMutationSettlement(page)
    await performDrag(page, dragHandle(page, 'Sidebar-Alpha'), dragHandle(page, 'Sidebar-Beta'))
    await settle2()

    await expect(sidebarSections.getByTestId('section-title')).toHaveText([
      'Sidebar-Beta',
      'Sidebar-Alpha',
    ])
    // Main unchanged
    await expect(mainSections.getByTestId('section-title')).toHaveText(['Main-Beta', 'Main-Alpha'])

    // --- Phase 4: Cross-zone drag cannot move sections between zones ---
    // dnd-kit resolves to the nearest same-zone collision (not cross-zone),
    // so a reorder may fire within the main zone. The key invariant:
    // no section moves between zones — counts stay the same.
    await performDrag(page, dragHandle(page, 'Main-Beta'), dragHandle(page, 'Sidebar-Beta'))

    // Both zones still have exactly 2 sections each
    await expect(mainSections).toHaveCount(2)
    await expect(sidebarSections).toHaveCount(2)
    // Sidebar order is unchanged (never affected by main-zone drag)
    await expect(sidebarSections.getByTestId('section-title')).toHaveText([
      'Sidebar-Beta',
      'Sidebar-Alpha',
    ])

    // Phase 4's cross-zone drag may legally resolve to a same-zone fallback
    // reorder (engine-dependent). Reload for the authoritative order before
    // capturing what the frontend must render — this also settles any
    // in-flight reorder before Publish.
    await page.reload()
    await expect(page.getByTestId('grid-editor-loading')).toHaveCount(0, { timeout: 15_000 })

    const gridEditorsReloaded = page.getByTestId('grid-editor')
    const mainZoneReloaded = gridEditorsReloaded.and(page.locator('[data-zone="main"]'))
    const sidebarZoneReloaded = gridEditorsReloaded.and(page.locator('[data-zone="sidebar"]'))
    const mainSectionsReloaded = mainZoneReloaded.getByTestId('section-block')
    const sidebarSectionsReloaded = sidebarZoneReloaded.getByTestId('section-block')

    await expect(mainSectionsReloaded).toHaveCount(2)
    await expect(sidebarSectionsReloaded).toHaveCount(2)
    await expect(sidebarSectionsReloaded.getByTestId('section-title')).toHaveText([
      'Sidebar-Beta',
      'Sidebar-Alpha',
    ])

    const mainOrder = await mainSectionsReloaded.getByTestId('section-title').allTextContents()

    // --- Phase 5: Publish and verify all sections render on frontend ---
    await page.getByRole('button', { name: /Publish/ }).click()
    await expect(page.getByRole('button', { name: /Published/ })).toBeVisible({ timeout: 10_000 })

    const livePath = fixture.pageUrl.split('?')[0]
    await page.goto(livePath)

    // All 4 section headings should be present on the frontend.
    // Zones interleave on the frontend, so full-array order is not stable —
    // but relative order WITHIN a zone must match the CMS.
    const frontendHeadings = page.getByRole('heading', { level: 2 })
    await expect(frontendHeadings).toHaveCount(4)
    const headingTexts = await frontendHeadings.allTextContents()
    expect(headingTexts).toContain('Main-Alpha')
    expect(headingTexts).toContain('Main-Beta')
    expect(headingTexts).toContain('Sidebar-Alpha')
    expect(headingTexts).toContain('Sidebar-Beta')
    expect(headingTexts.indexOf(mainOrder[0])).toBeLessThan(headingTexts.indexOf(mainOrder[1]))
    expect(headingTexts.indexOf('Sidebar-Beta')).toBeLessThan(headingTexts.indexOf('Sidebar-Alpha'))
  })
})

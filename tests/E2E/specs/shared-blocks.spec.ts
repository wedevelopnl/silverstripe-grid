import { expect, type Locator, type Page, test } from '@playwright/test'
import { triggerElementAction } from '../helpers/actions'
import { dragHandle, performDrag, watchReorderRequests } from '../helpers/drag'
import { loadFixture, resetFixtures } from '../helpers/fixtures'

/**
 * The fixture's two-column row, on its own page. Not on page A: the
 * boundary-drag test there asserts that dragging at the shared frame fires no
 * reorder at all, and extra content on that page moves the pointer path far
 * enough to register a legal drop on the way.
 */
function populatedRow(page: Page): Locator {
  return page
    .getByTestId('section-block')
    .filter({ hasText: 'Local Section C' })
    .getByTestId('row-block')
    .first()
}

/**
 * Open a block's edit form in the Shared blocks admin with the delete actions
 * revealed. Each outcome is its own button; the admin's confirm() guards both.
 */
async function openLibraryBlockActions(page: Page, blockId: number): Promise<void> {
  const model = 'WeDevelop-Grid-Model-SharedBlock'

  await page.goto(`/admin/shared-blocks/${model}/EditForm/field/${model}/item/${blockId}/edit`)

  // The actions live in the CMS action bar's collapsed "More options" tab.
  await page.locator('#tab-ActionMenus_MoreOptions').click()
}

/**
 * Playwright dismisses native dialogs by default, which would cancel the
 * delete the admin is asking about.
 */
function acceptNextConfirm(page: Page): void {
  page.once('dialog', (dialog) => {
    void dialog.accept()
  })
}

/**
 * Wait for the library listing after a delete.
 *
 * Deliberately not a URL assertion: a block's own edit form lives under
 * /admin/shared-blocks/ too, so matching the URL passes before the submit has
 * even landed and lets the test read a page that still places the block. The
 * add control exists only on the listing, and a surviving row proves the table
 * rendered — without which the deleted row's absence would mean nothing.
 */
async function expectLibraryListingWithout(page: Page, deletedTitle: string): Promise<void> {
  await expect(page.locator('[data-shared-block-add]')).toBeVisible({ timeout: 15_000 })
  await expect(page.locator('td.col-Title', { hasText: 'Shared Card' })).toHaveCount(1)
  await expect(page.locator('td.col-Title', { hasText: deletedTitle })).toHaveCount(0)
}

test.describe('Shared blocks', () => {
  test.use({ viewport: { width: 1280, height: 1600 } })

  test.afterAll(async ({ request }) => {
    await resetFixtures(request)
  })

  test('one block, edited once, reflected on every page that places it', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'shared-blocks')
    const pageAId = fixture.fixtureMap.Page.e2e_shared_a
    const pageBId = fixture.fixtureMap.Page.e2e_shared_b

    const frame = page.getByTestId('shared-block-frame')

    await test.step('page A frames the placement and names its reach', async () => {
      await page.goto(`/admin/pages/edit/show/${pageAId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      await expect(frame).toHaveCount(1)
      // Two pages place this one block.
      await expect(page.getByTestId('shared-block-chip')).toContainText('2')
      await expect(page.getByTestId('shared-block-chip')).toContainText('Shared Banner')

      // The block's own content renders inside the frame; the page's own
      // section sits outside it.
      await expect(frame.getByTestId('element-card-title')).toHaveText('Shared Banner Text')
      await expect(frame).not.toContainText('Local Section A')
    })

    await test.step('the block has never been published, and says so', async () => {
      await expect(page.getByTestId('shared-block-status')).toBeVisible()
      await expect(frame).toHaveAttribute('data-status', 'notPublished')
    })

    await test.step('page B shows the same block content', async () => {
      await page.goto(`/admin/pages/edit/show/${pageBId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      await expect(frame).toHaveCount(1)
      await expect(frame.getByTestId('element-card-title')).toHaveText('Shared Banner Text')
    })

    await test.step('publishing the block from page B clears the badge', async () => {
      // The FRAME's own menu — the block body nests a toolbar per element.
      await frame.getByTestId('shared-block-actions').getByTestId('actions-menu-trigger').click()
      await page.getByRole('menuitem', { name: 'Publish shared block', exact: true }).click()
      await page.getByRole('button', { name: 'Publish', exact: true }).click()

      await expect(frame).toHaveAttribute('data-status', 'published', { timeout: 15_000 })
      await expect(page.getByTestId('shared-block-status')).toBeHidden()
    })

    await test.step('and page A agrees — one block, one state', async () => {
      await page.goto(`/admin/pages/edit/show/${pageAId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      await expect(frame).toHaveAttribute('data-status', 'published')
    })
  })

  test('placing a block from the picker, then detaching it back into the page', async ({
    page,
  }) => {
    const fixture = await loadFixture(page.request, 'shared-blocks')
    const pageBId = fixture.fixtureMap.Page.e2e_shared_b
    const blockId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\SharedBlock'].block1

    await page.goto(`/admin/pages/edit/show/${pageBId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const frames = page.getByTestId('shared-block-frame')

    await test.step('the picker offers the block and places it', async () => {
      await expect(frames).toHaveCount(1)

      // The ROOT add affordance, not a section's — scoped by canvas child so a
      // nested one (which offers row-rooted blocks) cannot be picked up.
      await page
        .getByTestId('grid-editor-canvas')
        .locator('> [data-testid="add-child-append"]')
        .getByTestId('add-child-shared-trigger')
        .click()

      await page.getByRole('menuitem', { name: 'Place shared section…', exact: true }).click()

      const picker = page.getByTestId('shared-block-picker')
      await expect(picker).toBeVisible()

      // Selected by id, not by position: SharedBlock records are not pages, so
      // resetFixtures leaves earlier runs' blocks in the library.
      const row = picker.locator(
        `[data-testid="shared-block-picker-row"][data-block-id="${blockId}"]`,
      )
      await expect(row).toBeVisible()
      await expect(row).toContainText('Shared Banner')

      await row.click()

      await expect(frames).toHaveCount(2, { timeout: 15_000 })
    })

    await test.step('detaching turns one placement into page-owned content', async () => {
      const second = frames.nth(1)

      await second.getByTestId('shared-block-actions').getByTestId('actions-menu-trigger').click()
      await page.getByRole('menuitem', { name: 'Detach into this page', exact: true }).click()
      await page.getByRole('button', { name: 'Detach', exact: true }).click()

      // One frame left, and the detached copy is now a plain page-owned
      // section sitting beside it — a canvas child, not framed content. (The
      // surviving placement still renders a section of the same name INSIDE
      // its frame, which is why this scopes to unframed roots.)
      await expect(frames).toHaveCount(1, { timeout: 15_000 })
      await expect(
        page
          .getByTestId('grid-editor-canvas')
          .locator('> [data-testid="section-block"]')
          .filter({ hasText: 'Shared Banner Section' }),
      ).toHaveCount(1)
    })
  })

  test('deleting a block from the library, keeping the content on every page', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'shared-blocks')
    const pageAId = fixture.fixtureMap.Page.e2e_shared_a
    const pageBId = fixture.fixtureMap.Page.e2e_shared_b
    const blockId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\SharedBlock'].block1

    await test.step('the library offers both outcomes and states the reach', async () => {
      await openLibraryBlockActions(page, blockId)

      // Two pages place this block, neither published.
      await expect(
        page.getByRole('button', { name: 'Delete and remove from 2 pages' }),
      ).toBeVisible()
    })

    await test.step('choosing to keep the content deletes the block and returns to the library', async () => {
      acceptNextConfirm(page)
      await page.getByRole('button', { name: 'Delete and keep a copy on each page' }).click()

      await expectLibraryListingWithout(page, 'Shared Banner')
    })

    for (const [label, pageId] of [
      ['A', pageAId],
      ['B', pageBId],
    ] as const) {
      await test.step(`page ${label} keeps the content as its own section`, async () => {
        await page.goto(`/admin/pages/edit/show/${pageId}`)
        await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

        await expect(page.getByTestId('shared-block-frame')).toHaveCount(0)
        await expect(
          page
            .getByTestId('grid-editor-canvas')
            .locator('> [data-testid="section-block"]')
            .filter({ hasText: 'Shared Banner Section' }),
        ).toHaveCount(1)
      })
    }
  })

  test('deleting a block from the library, removing the content everywhere', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'shared-blocks')
    const pageAId = fixture.fixtureMap.Page.e2e_shared_a
    const pageBId = fixture.fixtureMap.Page.e2e_shared_b
    const blockId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\SharedBlock'].block1

    await test.step('choosing to remove the content deletes the block and returns to the library', async () => {
      await openLibraryBlockActions(page, blockId)

      acceptNextConfirm(page)
      await page.getByRole('button', { name: 'Delete and remove from 2 pages' }).click()

      await expectLibraryListingWithout(page, 'Shared Banner')
    })

    await test.step('page A loses the placement and keeps everything it owns itself', async () => {
      await page.goto(`/admin/pages/edit/show/${pageAId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      await expect(page.getByTestId('shared-block-frame')).toHaveCount(0)
      await expect(page.getByTestId('grid-editor-canvas')).not.toContainText(
        'Shared Banner Section',
      )

      // The point of the assertion: the cascade reaches the placements and
      // stops. A page's own content is not the block's to take.
      await expect(
        page
          .getByTestId('grid-editor-canvas')
          .locator('> [data-testid="section-block"]')
          .filter({ hasText: 'Local Section A' }),
      ).toHaveCount(1)
      await expect(page.getByTestId('grid-editor-canvas')).toContainText('Local Text A')
    })

    await test.step('page B, which placed nothing else, is left empty', async () => {
      await page.goto(`/admin/pages/edit/show/${pageBId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      await expect(page.getByTestId('shared-block-frame')).toHaveCount(0)
      await expect(page.getByTestId('section-block')).toHaveCount(0)
    })
  })

  test('content cannot be dragged across the shared boundary', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'shared-blocks')
    const pageAId = fixture.fixtureMap.Page.e2e_shared_a

    await page.goto(`/admin/pages/edit/show/${pageAId}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const frame = page.getByTestId('shared-block-frame')
    const sharedColumn = frame.getByTestId('column-block').first()
    const localElement = dragHandle(page, 'Local Text A')

    await expect(sharedColumn).toBeVisible()
    await expect(localElement).toBeVisible()

    const reorders = watchReorderRequests(page)

    await performDrag(page, localElement, sharedColumn)

    // No reorder is even attempted — collision detection excludes every
    // droppable on the far side of the boundary.
    expect(reorders.count()).toBe(0)
    reorders.stop()

    // ... and the element stayed where it was.
    await expect(
      page
        .getByTestId('column-block')
        .filter({ hasText: 'Local Column A' })
        .getByTestId('element-card-title'),
    ).toHaveText('Local Text A')
    await expect(frame.getByTestId('element-card-title')).toHaveText('Shared Banner Text')
  })

  test('an element inside a block offers no convert action', async ({ page }) => {
    // Mirrors the server's no-nesting rule.
    const fixture = await loadFixture(page.request, 'shared-blocks')

    await page.goto(`/admin/pages/edit/show/${fixture.fixtureMap.Page.e2e_shared_a}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    // Scoped to the section's OWN header — the block body nests further
    // toolbars that would make the locator ambiguous.
    const localSectionHeader = page
      .getByTestId('section-block')
      .filter({ hasText: 'Local Section A' })
      .first()
      .getByTestId('section-header')

    await triggerElementAction(localSectionHeader, 'convert-to-shared', 'Convert to shared block')
    await expect(page.getByRole('dialog')).toContainText('shared library')
    await page.getByRole('button', { name: 'Cancel', exact: true }).click()

    // The block's own section, inside the frame, offers no controls at all —
    // the placement's actions sit on the frame's bar instead.
    const sharedSectionHeader = page
      .getByTestId('shared-block-frame')
      .getByTestId('section-block')
      .first()
      .getByTestId('section-header')

    await expect(sharedSectionHeader.getByTestId('actions-menu-trigger')).toHaveCount(0)
    await expect(page.getByTestId('shared-placement-toolbar')).toBeVisible()
  })

  test('a placed block is read-only on the page, its controls on the frame', async ({ page }) => {
    // Editing shared content happens in the library, where the reach of a
    // change is explicit — the page shows it, but does not edit it inline.
    const fixture = await loadFixture(page.request, 'shared-blocks')

    await page.goto(`/admin/pages/edit/show/${fixture.fixtureMap.Page.e2e_shared_a}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const frame = page.getByTestId('shared-block-frame')
    await expect(frame.getByTestId('element-card-title')).toHaveText('Shared Banner Text')

    // No way to drag, add, or act on anything inside the block.
    await expect(frame.getByTestId('drag-handle')).toHaveCount(0)
    await expect(frame.getByTestId('add-child-button')).toHaveCount(0)
    await expect(frame.getByTestId('add-content-button')).toHaveCount(0)
    await expect(frame.getByTestId('element-toolbar')).toHaveCount(0)

    // The column's size and offset stay readable but locked.
    await expect(frame.getByTestId('column-badge')).toBeDisabled()
    await expect(frame.getByTestId('column-offset-badge')).toBeDisabled()

    // The local section beside the frame keeps its full editing surface.
    const localSection = page
      .getByTestId('section-block')
      .filter({ hasText: 'Local Section A' })
      .first()
    await expect(localSection.getByTestId('drag-handle').first()).toBeVisible()

    // The frame's own bar offers exactly open, edit and remove, and the edit
    // action leads to the BLOCK in the library — not to the section rooting it.
    const placementToolbar = frame.getByTestId('shared-placement-toolbar')
    await expect(placementToolbar.getByTestId('element-action-open')).toBeEnabled()
    await expect(placementToolbar.getByTestId('element-action-edit')).toBeEnabled()
    await expect(placementToolbar.getByTestId('element-action-remove')).toBeEnabled()
    await expect(placementToolbar.locator('button')).toHaveCount(3)

    await placementToolbar.getByTestId('element-action-edit').click()
    const blockId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\SharedBlock'].block1
    await expect(page).toHaveURL(new RegExp(`/item/${blockId}/`))
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })
    await expect(page.getByTestId('grid-editor-canvas')).toContainText('Shared Banner Text')
  })

  test('removing a placement takes the block off this page only', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'shared-blocks')
    const pageAId = fixture.fixtureMap.Page.e2e_shared_a
    const pageBId = fixture.fixtureMap.Page.e2e_shared_b

    await test.step('remove the placement from page A via the frame toolbar', async () => {
      await page.goto(`/admin/pages/edit/show/${pageAId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      await page.getByTestId('element-action-remove').click()

      // The confirm names the block and promises the library keeps it.
      const dialog = page.getByRole('dialog')
      await expect(dialog).toContainText('Shared Banner')
      await dialog.getByRole('button', { name: 'Remove', exact: true }).click()

      await expect(page.getByTestId('shared-block-frame')).toHaveCount(0, { timeout: 15_000 })

      // The page's own content is untouched.
      await expect(page.getByTestId('grid-editor-canvas')).toContainText('Local Text A')
    })

    await test.step('page B still places the block, contents intact', async () => {
      await page.goto(`/admin/pages/edit/show/${pageBId}`)
      await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

      const frame = page.getByTestId('shared-block-frame')
      await expect(frame).toHaveCount(1)
      await expect(frame.getByTestId('element-card-title')).toHaveText('Shared Banner Text')
    })
  })

  test('a shared column can be placed into a row that already has columns', async ({ page }) => {
    // Before this change a populated row offered no shared route at all — the
    // only affordance was the empty-state add button, which such a row never
    // renders.
    const fixture = await loadFixture(page.request, 'shared-blocks')
    const cardBlockId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\SharedBlock'].block2

    await page.goto(`/admin/pages/edit/show/${fixture.fixtureMap.Page.e2e_shared_c}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const row = populatedRow(page)
    await expect(row.getByTestId('column-block')).toHaveCount(2)

    const endHandle = row.getByTestId('column-insert-end')
    await endHandle.hover()
    await endHandle.getByTestId('column-insert-shared-trigger').click()
    await page.getByRole('menuitem', { name: 'Place shared column…', exact: true }).click()

    // Selected by id, not by position: SharedBlock records are not pages, so
    // resetFixtures leaves earlier runs' blocks in the library.
    const picker = page.getByTestId('shared-block-picker')
    await expect(picker).toBeVisible()
    await picker
      .locator(`[data-testid="shared-block-picker-row"][data-block-id="${cardBlockId}"]`)
      .click()

    await expect(row.getByTestId('shared-block-frame')).toHaveCount(1, { timeout: 15_000 })
    await expect(row.getByTestId('column-block')).toHaveCount(3)
  })

  test('a caret menu at a section edge is not clipped by the section', async ({ page }) => {
    // `.ssgrid-section` carries `content-visibility: auto` for long-page
    // performance, which also applies PAINT containment: without lifting it
    // while a flyout is open, the menu is cut off at the section's bottom edge
    // and the item cannot be clicked at all. A real mouse click is the point —
    // a keyboard activation passes even while the menu is clipped.
    const fixture = await loadFixture(page.request, 'shared-blocks')

    await page.goto(`/admin/pages/edit/show/${fixture.fixtureMap.Page.e2e_shared_c}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const section = page.getByTestId('section-block').filter({ hasText: 'Local Section C' })

    await section
      .getByTestId('add-child-append')
      .first()
      .getByTestId('add-child-shared-trigger')
      .click()

    await page.getByRole('menuitem', { name: 'Place shared row…', exact: true }).click()

    await expect(page.getByTestId('shared-block-picker')).toBeVisible()
  })

  test('the library adds a block of the shape the author picks, ready to edit', async ({
    page,
  }) => {
    // The stock ModelAdmin flow makes the author save an empty record before
    // any content can be added, and only ever produces a section-rooted block.
    await page.goto('/admin/shared-blocks')

    await page.getByTestId('add-shared-block-menu-trigger').click()
    await page.getByRole('menuitem', { name: 'Add new shared row', exact: true }).click()

    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    // A row-rooted block: the row is the tree's top node, with the column its
    // own write scaffolded beneath it.
    await expect(page.getByTestId('row-block')).toHaveCount(1)
    await expect(page.getByTestId('section-block')).toHaveCount(0)
    await expect(page.getByTestId('column-block')).toHaveCount(1)
  })

  test('the library seeds a leaf-rooted block straight from the menu', async ({ page }) => {
    await page.goto('/admin/shared-blocks')

    await page.getByTestId('add-shared-block-menu-trigger').click()

    // The element types are menu items the server renders, so an empty group
    // here means the component and the placement rules have drifted apart.
    await page.getByRole('menuitem', { name: 'Content element', exact: true }).click()

    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    await expect(page.getByTestId('element-card-title')).toHaveCount(1)
    await expect(page.getByTestId('section-block')).toHaveCount(0)
  })

  test('the primary half creates a section-rooted block', async ({ page }) => {
    // The caret covers the other shapes; this is the one the toolbar leads
    // with, and the only path that does not open the menu at all.
    await page.goto('/admin/shared-blocks')

    await page.getByTestId('add-shared-block-add').click()

    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    await expect(page.getByTestId('section-block')).toHaveCount(1)
    await expect(page.getByTestId('row-block')).toHaveCount(1)
    await expect(page.getByTestId('column-block')).toHaveCount(1)
  })

  test('the caret menu opens, roves and dismisses by keyboard', async ({ page }) => {
    // The admin ships Bootstrap's dropdown CSS but not its JavaScript, so all
    // of this is client/js/shared-block-add.js. Nothing else reaches that file:
    // it is deliberately outside the editor bundle, so no unit test can import
    // it and this spec is its only coverage.
    await page.goto('/admin/shared-blocks')

    const trigger = page.getByTestId('add-shared-block-menu-trigger')
    const menu = page.getByTestId('add-shared-block-menu-dropdown')
    const items = menu.getByRole('menuitem')

    await expect(menu).toBeHidden()

    await trigger.focus()
    await page.keyboard.press('ArrowDown')

    await expect(menu).toBeVisible()
    await expect(items.first()).toBeFocused()

    // Wraps in both directions, so the last shape is one key from the first.
    await page.keyboard.press('ArrowUp')
    await expect(items.last()).toBeFocused()

    await page.keyboard.press('Home')
    await expect(items.first()).toBeFocused()

    await page.keyboard.press('End')
    await expect(items.last()).toBeFocused()

    // Escape hands focus back to the control that opened the menu, rather than
    // dropping it to the document.
    await page.keyboard.press('Escape')
    await expect(menu).toBeHidden()
    await expect(trigger).toBeFocused()
  })

  test('the caret menu closes on a click outside it', async ({ page }) => {
    await page.goto('/admin/shared-blocks')

    const menu = page.getByTestId('add-shared-block-menu-dropdown')

    await page.getByTestId('add-shared-block-menu-trigger').click()
    await expect(menu).toBeVisible()

    // A bare coordinate well clear of the toolbar: the dismissal is delegated
    // from the document, so it must not depend on hitting any element in
    // particular — least of all one of the admin's own.
    await page.mouse.click(400, 1400)

    await expect(menu).toBeHidden()
  })

  test('a block root carries no archive, no duplicate and no drag handle', async ({ page }) => {
    // Deleting the root would strand every page placing the block, and
    // duplicating it would give the block a second root. The block is removed
    // as a whole, from its own form. The handle goes for a different reason:
    // the root is alone at its level, so a drag from it has no legal target.
    await page.goto('/admin/shared-blocks')

    await page.getByTestId('add-shared-block-menu-trigger').click()
    await page.getByRole('menuitem', { name: 'Add new shared row', exact: true }).click()
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const rootHeader = page.getByTestId('row-block').first().getByTestId('row-header')

    await expect(rootHeader.getByTestId('element-action-archive')).toHaveCount(0)
    await expect(rootHeader.getByTestId('element-action-duplicate')).toHaveCount(0)
    await expect(rootHeader.getByTestId('drag-handle')).toHaveCount(0)

    // Its subtree stays fully editable — only the root itself is protected.
    const column = page.getByTestId('column-block').first()
    await expect(column.getByTestId('drag-handle')).toBeVisible()

    await column.getByTestId('column-header').getByTestId('actions-menu-trigger').click()

    await expect(page.getByRole('menuitem', { name: 'Archive', exact: true })).toBeVisible()
    await expect(page.getByRole('menuitem', { name: 'Duplicate', exact: true })).toBeVisible()
  })

  test('the blooming gutter handle still accepts a column drop', async ({ page }) => {
    // The handle grows 24 -> 44px on hover, inside the gutter the collision
    // system measures. Guards the risk accepted in the design spec.
    const fixture = await loadFixture(page.request, 'shared-blocks')

    await page.goto(`/admin/pages/edit/show/${fixture.fixtureMap.Page.e2e_shared_c}`)
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 })

    const row = populatedRow(page)
    await expect(row.getByTestId('column-block')).toHaveCount(2)

    const gutter = row.getByTestId('column-insert-between').first()
    await gutter.hover()
    await gutter.getByTestId('column-insert-between-add').click()

    await expect(row.getByTestId('column-block')).toHaveCount(3, { timeout: 15_000 })
  })
})

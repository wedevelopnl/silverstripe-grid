import { type Locator, expect } from '@playwright/test'

/**
 * Trigger one of an element's actions, whichever presentation its header shows.
 *
 * `ElementActions` renders the same action set two ways: an icon row of
 * `element-action-<key>` buttons, or — when the header is too narrow for that
 * row — everything inside the overflow menu. Element cards flip between the two
 * at `NARROW_HEADER_WIDTH` (see `EditableElementCard`), and which side of that
 * they land on depends on the panel width the CMS happens to give the column,
 * so a spec cannot assume either. Section and row headers keep the icon row and
 * take the first branch.
 *
 * `label` is the action's menu-item text, needed only for the folded branch.
 */
export async function triggerElementAction(
  scope: Locator,
  key: string,
  label: string,
): Promise<void> {
  const toolbarButton = scope.getByTestId(`element-action-${key}`)
  const overflowTrigger = scope.getByTestId('actions-menu-trigger')

  // Exactly one branch is reachable, but both mount asynchronously — wait for
  // whichever this header rendered before probing which one it was.
  await expect(toolbarButton.or(overflowTrigger).first()).toBeVisible()

  if (await toolbarButton.isVisible()) {
    await toolbarButton.click()
    return
  }

  await overflowTrigger.click()
  await scope.page().getByRole('menuitem', { name: label, exact: true }).click()
}

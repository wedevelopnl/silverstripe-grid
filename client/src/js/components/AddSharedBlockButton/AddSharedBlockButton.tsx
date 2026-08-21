import { useState } from 'react'
import type { CreateSharedBlockParams } from '@/api/endpoints'
import ElementTypePicker from '@/components/ElementTypePicker/ElementTypePicker'
import { useRovingPopup } from '@/hooks/useRovingPopup'
import { useCreateSharedBlock } from '@/hooks/useSharedBlockMutations'
import { t } from '@/i18n'
import type { AllowedTypeInfo } from '@/types/elements'

interface AddSharedBlockButtonProps {
  /**
   * Element types a Column accepts — the classes that may root a leaf-rooted
   * block, server-rendered onto the button by GridFieldAddSharedBlockButton.
   */
  readonly leafTypes: Record<string, AllowedTypeInfo>
}

/**
 * The block library's add control: a split button in the listing header whose
 * primary action creates a section-rooted block and whose caret offers the
 * other three shapes a block may root.
 *
 * Creating rather than opening a form is the point. The grid editor is keyed by
 * the block's id, so an unsaved record cannot host one; and a block's root
 * shape decides where it may be placed, so it is a choice made when the block
 * is created, not one buried in the editor afterwards.
 *
 * Chrome is the admin's, not the grid's. This sits in a ModelAdmin toolbar
 * beside Import CSV, outside any grid editor, so every class here is one the
 * CMS already ships — `.btn-group` + `.dropdown-toggle-split` + `.dropdown-menu`
 * from the admin's Bootstrap 5, down to the green divider `.btn-group
 * .btn-primary` draws between the halves. `data-bs-popper="static"` is what
 * unlocks the menu's CSS placement without Popper; it is the same attribute the
 * admin's own reactstrap dropdowns emit. Nothing here carries an `ssgrid-`
 * class, and nothing here should: the grid's popover tokens are tuned for the
 * editor canvas and read as foreign in admin chrome.
 *
 * Only the behaviour is shared with the editor, via {@link useRovingPopup} —
 * open/close, outside-mousedown dismissal, Escape-with-focus-return and the
 * roving `aria-activedescendant` pattern.
 */
export default function AddSharedBlockButton({ leafTypes }: AddSharedBlockButtonProps) {
  const { mutate, isPending } = useCreateSharedBlock()
  const [isPickerOpen, setPickerOpen] = useState(false)

  function create(params: CreateSharedBlockParams) {
    // aria-disabled keeps the control focusable, so the click still lands —
    // this guard is what stops a second block being created.
    if (isPending) return

    mutate(params, {
      onSuccess: (block) => {
        // A full navigation, not a CMS route change: the listing and the new
        // block's form are separate ModelAdmin requests, and the editor has to
        // boot against a record that did not exist when this page loaded.
        window.location.assign(block.editLink)
      },
    })
  }

  const items = [
    {
      key: 'row',
      label: t('WeDevelopGrid.AddSharedBlockButton.ADD_ROW', 'Add new shared row'),
      onSelect: () => create({ containerType: 'row' }),
    },
    {
      key: 'column',
      label: t('WeDevelopGrid.AddSharedBlockButton.ADD_COLUMN', 'Add new shared column'),
      onSelect: () => create({ containerType: 'column' }),
    },
    {
      key: 'element',
      label: t('WeDevelopGrid.AddSharedBlockButton.ADD_ELEMENT', 'Add new shared content element…'),
      onSelect: () => setPickerOpen(true),
    },
  ]

  const popup = useRovingPopup({ itemCount: items.length })
  const menuId = 'add-shared-block-menu'

  function select(index: number) {
    items[index]?.onSelect()
    popup.close()
  }

  function handleMenuKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    if (popup.handleNavigationKeyDown(e)) return

    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      select(popup.activeIndex)
    }
  }

  return (
    <>
      <div className="btn-group" ref={popup.wrapperRef} data-testid="add-shared-block">
        <button
          type="button"
          className="btn btn-primary"
          data-testid="add-shared-block-add"
          aria-disabled={isPending}
          onClick={() => create({ containerType: 'section' })}
        >
          <span className="font-icon-plus-circled btn__icon" aria-hidden="true" />
          <span className="btn__title">
            {isPending
              ? t('WeDevelopGrid.AddSharedBlockButton.ADDING_BLOCK', 'Adding block…')
              : t('WeDevelopGrid.AddSharedBlockButton.ADD_BLOCK', 'Add new shared section')}
          </span>
        </button>
        <button
          ref={popup.triggerRef}
          type="button"
          className="btn btn-primary dropdown-toggle dropdown-toggle-split"
          data-testid="add-shared-block-menu-trigger"
          aria-haspopup="menu"
          aria-expanded={popup.isOpen}
          aria-controls={popup.isOpen ? menuId : undefined}
          onClick={popup.toggle}
        >
          {/* The caret is `.dropdown-toggle`'s own ::after triangle, so this
              half renders no glyph of its own and needs a text alternative. */}
          <span className="visually-hidden">
            {t('WeDevelopGrid.AddSharedBlockButton.MORE_SHAPES', 'More block shapes')}
          </span>
        </button>
        {popup.isOpen && (
          <div
            id={menuId}
            ref={popup.popupRef}
            // Start-aligned (Bootstrap's default): the control leads the
            // toolbar, so the menu grows right into empty space. Right-aligning
            // it would run the wider rows left, under the CMS sidebar.
            className="dropdown-menu show"
            data-bs-popper="static"
            role="menu"
            tabIndex={-1}
            aria-activedescendant={popup.getItemId(popup.activeIndex)}
            data-testid="add-shared-block-menu-dropdown"
            onKeyDown={handleMenuKeyDown}
          >
            {items.map((item, index) => (
              <button
                key={item.key}
                id={popup.getItemId(index)}
                type="button"
                // `.active` is the admin's own highlight for the item Enter
                // would take. Real focus stays on the menu (the
                // aria-activedescendant pattern), so `:focus` cannot carry it.
                className={`dropdown-item${index === popup.activeIndex ? ' active' : ''}`}
                role="menuitem"
                // Not tab stops: the menu container is the single stop, and
                // arrow keys move the active descendant within it.
                tabIndex={-1}
                data-route={item.key}
                onClick={() => select(index)}
              >
                {item.label}
              </button>
            ))}
          </div>
        )}
      </div>
      <ElementTypePicker
        allowedTypes={leafTypes}
        isOpen={isPickerOpen}
        onClose={() => setPickerOpen(false)}
        onSelect={(className) => create({ className })}
      />
    </>
  )
}

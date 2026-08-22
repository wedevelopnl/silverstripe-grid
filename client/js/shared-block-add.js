/**
 * Caret-menu behaviour for the shared block library's add control.
 *
 * Deliberately outside the Vite bundle and written against the DOM directly.
 * The control is a GridField component on a ModelAdmin listing, not part of the
 * grid editor, and keeping its script standalone is what lets the CMS chrome be
 * repaired without rebuilding the editor. Its markup, its labels and this file
 * are the whole surface.
 *
 * The admin ships Bootstrap 5's CSS but not its dropdown JavaScript (its own
 * dropdowns are reactstrap), so `.show`, focus and `aria-expanded` are managed
 * here. Item activation is not: the items are `GridField_FormAction` buttons the
 * admin's own GridField handler submits, and this file never touches their click.
 *
 * Listeners are delegated from the document so the control needs no
 * initialisation — the listing arrives through Pjax, and a menu rendered after
 * this script ran works the same as one already on the page.
 */
;(() => {
  const GROUP = '[data-shared-block-add]'
  const TRIGGER = '[data-shared-block-add-trigger]'
  const MENU = '[data-shared-block-add-menu]'
  const ITEM = '.dropdown-item'

  const menuOf = (group) => group.querySelector(MENU)
  const triggerOf = (group) => group.querySelector(TRIGGER)
  const itemsOf = (group) => Array.from(menuOf(group).querySelectorAll(ITEM))
  const isOpen = (group) => menuOf(group).classList.contains('show')

  function setOpen(group, open) {
    menuOf(group).classList.toggle('show', open)
    triggerOf(group).setAttribute('aria-expanded', String(open))
  }

  /** @param {Element|null} except Left open; everything else closes. */
  function closeAll(except) {
    for (const group of document.querySelectorAll(GROUP)) {
      if (group !== except && isOpen(group)) {
        setOpen(group, false)
      }
    }
  }

  function focusItem(group, index) {
    const items = itemsOf(group)
    if (items.length === 0) return

    // Wraps in both directions, so ArrowUp from the first item reaches the last.
    items[((index % items.length) + items.length) % items.length].focus()
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest(TRIGGER)

    if (trigger === null) {
      // Covers dismissal and activation alike: a click on an item is a click
      // outside the trigger, and the menu should close behind it either way.
      closeAll(null)
      return
    }

    const group = trigger.closest(GROUP)
    const opening = !isOpen(group)

    closeAll(group)
    setOpen(group, opening)

    if (opening) {
      focusItem(group, 0)
    }
  })

  document.addEventListener('keydown', (event) => {
    const group = event.target.closest(GROUP)

    if (group === null) return

    if (event.key === 'Escape' && isOpen(group)) {
      event.preventDefault()
      setOpen(group, false)
      triggerOf(group).focus()
      return
    }

    // ArrowDown on the closed trigger is the keyboard's way in.
    if (!isOpen(group)) {
      if (event.key === 'ArrowDown' && event.target.closest(TRIGGER) !== null) {
        event.preventDefault()
        setOpen(group, true)
        focusItem(group, 0)
      }
      return
    }

    const items = itemsOf(group)
    const current = items.indexOf(event.target.closest(ITEM))

    if (current === -1) return

    const next = {
      ArrowDown: current + 1,
      ArrowUp: current - 1,
      Home: 0,
      End: items.length - 1,
    }[event.key]

    if (next === undefined) return

    event.preventDefault()
    focusItem(group, next)
  })
})()

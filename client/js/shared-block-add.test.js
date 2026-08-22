import { beforeEach, describe, expect, it } from 'vitest'

// Imported once, as the CMS loads it: the listeners are delegated from the
// document, so a listing swapped in later works with the same registration.
// Re-importing per test would stack handlers, and two toggles per click cancel
// each other out.
import './shared-block-add.js'

/**
 * Mirrors GridFieldAddSharedBlockButton.ss. The template is the contract this
 * script reads, so the two have to be changed together — the E2E specs are what
 * catch them drifting apart.
 */
function renderControl(itemLabels = ['Row', 'Column', 'Content element']) {
  const items = itemLabels
    .map(
      (label) =>
        `<button type="button" class="dropdown-item" role="menuitem" tabindex="-1">${label}</button>`,
    )
    .join('')

  const group = document.createElement('div')
  group.className = 'btn-group'
  group.setAttribute('data-shared-block-add', '')
  group.innerHTML = `
    <button type="button" class="btn btn-primary font-icon-plus-circled" data-testid="add-shared-block-add">
      <span class="btn__title">Add new shared section</span>
    </button>
    <button
      type="button"
      class="btn btn-primary dropdown-toggle dropdown-toggle-split"
      aria-haspopup="menu"
      aria-expanded="false"
      data-shared-block-add-trigger
    ><span class="visually-hidden">More block shapes</span></button>
    <div class="dropdown-menu" data-bs-popper="static" role="menu" tabindex="-1" data-shared-block-add-menu>
      ${items}
    </div>
  `
  document.body.appendChild(group)

  return {
    group,
    primary: group.querySelector('[data-testid="add-shared-block-add"]'),
    trigger: group.querySelector('[data-shared-block-add-trigger]'),
    menu: group.querySelector('[data-shared-block-add-menu]'),
    items: Array.from(group.querySelectorAll('.dropdown-item')),
  }
}

const isOpen = (menu) => menu.classList.contains('show')

function press(element, key) {
  element.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
}

describe('shared block add control', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  it('opens on the trigger, focusing the first shape', () => {
    const { trigger, menu, items } = renderControl()

    trigger.click()

    expect(isOpen(menu)).toBe(true)
    expect(trigger.getAttribute('aria-expanded')).toBe('true')
    expect(document.activeElement).toBe(items[0])
  })

  it('closes when the trigger is pressed again', () => {
    const { trigger, menu } = renderControl()

    trigger.click()
    trigger.click()

    expect(isOpen(menu)).toBe(false)
    expect(trigger.getAttribute('aria-expanded')).toBe('false')
  })

  it('closes behind a chosen shape, whose own click the admin handles', () => {
    const { trigger, menu, items } = renderControl()

    trigger.click()
    items[1].click()

    expect(isOpen(menu)).toBe(false)
  })

  it('closes on a click anywhere outside', () => {
    const { trigger, menu } = renderControl()

    trigger.click()
    document.body.click()

    expect(isOpen(menu)).toBe(false)
  })

  it('returns focus to the trigger on Escape', () => {
    const { trigger, menu, items } = renderControl()

    trigger.click()
    press(items[0], 'Escape')

    expect(isOpen(menu)).toBe(false)
    expect(document.activeElement).toBe(trigger)
  })

  it('opens on ArrowDown from the closed trigger', () => {
    const { trigger, menu, items } = renderControl()

    press(trigger, 'ArrowDown')

    expect(isOpen(menu)).toBe(true)
    expect(document.activeElement).toBe(items[0])
  })

  it('leaves the menu shut when ArrowDown lands on the primary half', () => {
    // The primary half creates a section outright; only the caret opens the
    // menu, so the arrow key must not reach past it.
    const { primary, menu } = renderControl()

    press(primary, 'ArrowDown')

    expect(isOpen(menu)).toBe(false)
  })

  it('wraps ArrowDown from the last shape to the first', () => {
    const { trigger, items } = renderControl()

    trigger.click()
    press(items[items.length - 1], 'ArrowDown')

    expect(document.activeElement).toBe(items[0])
  })

  it('wraps ArrowUp from the first shape to the last', () => {
    const { trigger, items } = renderControl()

    trigger.click()
    press(items[0], 'ArrowUp')

    expect(document.activeElement).toBe(items[items.length - 1])
  })

  it('jumps to the ends with Home and End', () => {
    const { trigger, items } = renderControl()

    trigger.click()
    press(items[0], 'End')
    expect(document.activeElement).toBe(items[items.length - 1])

    press(items[items.length - 1], 'Home')
    expect(document.activeElement).toBe(items[0])
  })

  it('ignores a key it does not handle', () => {
    const { trigger, items } = renderControl()

    trigger.click()
    press(items[0], 'ArrowRight')

    expect(document.activeElement).toBe(items[0])
  })

  it('opens one control at a time', () => {
    // Two listings on a page is not a state the CMS produces today, but the
    // handler closes every other group precisely so it never has to be.
    const first = renderControl()
    const second = renderControl()

    first.trigger.click()
    second.trigger.click()

    expect(isOpen(first.menu)).toBe(false)
    expect(isOpen(second.menu)).toBe(true)
  })
})

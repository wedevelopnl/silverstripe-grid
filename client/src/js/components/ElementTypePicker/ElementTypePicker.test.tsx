import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { AllowedTypeInfo } from '@/types/elements'

import ElementTypePicker from './ElementTypePicker'

// Deliberately out of label order: the picker sorts, the server does not.
const allowedTypes: Record<string, AllowedTypeInfo> = {
  'App\\TextBlock': {
    label: 'Text Block',
    icon: 'font-icon-block-content',
    description: 'A rich text block',
  },
  'App\\ImageBlock': {
    label: 'Image Block',
    icon: 'font-icon-block-media',
    description: '',
  },
  'App\\QuoteBlock': {
    label: 'Blockquote',
    icon: 'font-icon-block-content',
    description: 'A short quote in a box',
  },
}

const defaultProps = {
  allowedTypes,
  isOpen: true,
  onClose: vi.fn(),
  onSelect: vi.fn(),
}

function optionLabels(): string[] {
  return screen.getAllByTestId('element-type-option').map((option) => option.textContent ?? '')
}

describe('ElementTypePicker', () => {
  it('lists every allowed type sorted by label', () => {
    render(<ElementTypePicker {...defaultProps} />)

    expect(optionLabels()).toEqual([
      'BlockquoteA short quote in a box',
      'Image Block',
      'Text BlockA rich text block',
    ])
  })

  // Asserted through the id reference rather than toHaveAccessibleName: jsdom
  // leaves <dialog> without an `open` attribute (showModal is stubbed), which
  // dom-accessibility-api treats as hidden and names as empty.
  it('names itself from its own heading', () => {
    render(<ElementTypePicker {...defaultProps} />)

    const labelId = screen.getByTestId('element-type-picker').getAttribute('aria-labelledby')

    expect(labelId).not.toBeNull()
    expect(document.getElementById(labelId ?? '')).toHaveTextContent('Add content element')
  })

  it('focuses the search field when it opens', () => {
    const { rerender } = render(<ElementTypePicker {...defaultProps} isOpen={false} />)

    rerender(<ElementTypePicker {...defaultProps} isOpen={true} />)

    expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalled()
    expect(screen.getByTestId('element-type-picker-search')).toHaveFocus()
  })

  it('states the number of types in the search placeholder', () => {
    render(<ElementTypePicker {...defaultProps} />)

    expect(screen.getByTestId('element-type-picker-search')).toHaveAttribute(
      'placeholder',
      'Search 3 element types',
    )
  })

  it('selects a type and closes when its option is clicked', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()
    const onClose = vi.fn()

    render(<ElementTypePicker {...defaultProps} onSelect={onSelect} onClose={onClose} />)

    await user.click(screen.getByText('Text Block'))

    expect(onSelect).toHaveBeenCalledWith('App\\TextBlock')
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('filters on label and description, case-insensitively', async () => {
    const user = userEvent.setup()

    render(<ElementTypePicker {...defaultProps} />)

    await user.type(screen.getByTestId('element-type-picker-search'), 'RICH')

    expect(optionLabels()).toEqual(['Text BlockA rich text block'])

    await user.clear(screen.getByTestId('element-type-picker-search'))
    await user.type(screen.getByTestId('element-type-picker-search'), 'block')

    expect(optionLabels()).toHaveLength(3)
  })

  it('says what was searched for when nothing matches', async () => {
    const user = userEvent.setup()

    render(<ElementTypePicker {...defaultProps} />)

    await user.type(screen.getByTestId('element-type-picker-search'), 'carousel ')

    expect(screen.getByTestId('element-type-picker-no-match')).toHaveTextContent(
      'No element type matches "carousel"',
    )
    expect(screen.queryAllByTestId('element-type-option')).toHaveLength(0)
  })

  it('adds the type under the keyboard cursor on Enter', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()

    render(<ElementTypePicker {...defaultProps} onSelect={onSelect} />)

    await user.type(screen.getByTestId('element-type-picker-search'), 'block{ArrowDown}{Enter}')

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('App\\ImageBlock')
  })

  it('moves the keyboard cursor up on ArrowUp after ArrowDown stopped at the last match', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()

    render(<ElementTypePicker {...defaultProps} onSelect={onSelect} />)

    await user.type(
      screen.getByTestId('element-type-picker-search'),
      '{ArrowDown}{ArrowDown}{ArrowDown}{ArrowUp}{Enter}',
    )

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('App\\ImageBlock')
  })

  it('keeps the keyboard cursor on the first match on ArrowUp', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()

    render(<ElementTypePicker {...defaultProps} onSelect={onSelect} />)

    await user.type(screen.getByTestId('element-type-picker-search'), '{ArrowUp}{Enter}')

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('App\\QuoteBlock')
  })

  it('moves the keyboard cursor back to the first match when the search changes', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()

    render(<ElementTypePicker {...defaultProps} onSelect={onSelect} />)

    await user.type(screen.getByTestId('element-type-picker-search'), '{ArrowDown}{ArrowDown}t')

    const search = screen.getByTestId('element-type-picker-search')
    const [firstOption] = screen.getAllByTestId('element-type-option')
    expect(search).toHaveAttribute('aria-activedescendant', firstOption?.id)

    await user.keyboard('{Enter}')

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('App\\QuoteBlock')
  })

  it('does nothing on Enter when nothing matches', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()
    const onClose = vi.fn()

    render(<ElementTypePicker {...defaultProps} onSelect={onSelect} onClose={onClose} />)

    await user.type(screen.getByTestId('element-type-picker-search'), 'carousel{Enter}')

    expect(onSelect).not.toHaveBeenCalled()
    expect(onClose).not.toHaveBeenCalled()
  })

  it('calls onClose when close button is clicked', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()

    render(<ElementTypePicker {...defaultProps} onClose={onClose} />)

    await user.click(screen.getByTestId('element-type-picker-close'))

    expect(onClose).toHaveBeenCalledOnce()
  })

  it('shows the empty state and no search field when no types are allowed', () => {
    render(<ElementTypePicker {...defaultProps} allowedTypes={{}} />)

    expect(screen.getByText('No content element types available')).toBeInTheDocument()
    expect(screen.queryByTestId('element-type-picker-search')).not.toBeInTheDocument()
    expect(screen.queryAllByTestId('element-type-option')).toHaveLength(0)
  })

  it('omits the description element for a type without one', () => {
    render(<ElementTypePicker {...defaultProps} />)

    const descriptions = screen.getAllByTestId('element-type-description')
    expect(descriptions.map((d) => d.textContent)).toEqual([
      'A short quote in a box',
      'A rich text block',
    ])
  })

  it('shows each type with its own icon class', () => {
    render(<ElementTypePicker {...defaultProps} />)

    const icons = screen.getAllByTestId('element-type-icon')

    expect(icons.map((icon) => icon.className)).toEqual([
      'ssgrid-type-icon-chip font-icon-block-content',
      'ssgrid-type-icon-chip font-icon-block-media',
      'ssgrid-type-icon-chip font-icon-block-content',
    ])
  })

  it('hands off to the shared block chooser from the footer', async () => {
    const user = userEvent.setup()
    const onSelectSharedBlock = vi.fn()
    const onClose = vi.fn()

    render(
      <ElementTypePicker
        {...defaultProps}
        onClose={onClose}
        onSelectSharedBlock={onSelectSharedBlock}
      />,
    )

    await user.click(screen.getByTestId('element-type-picker-shared'))

    expect(onSelectSharedBlock).toHaveBeenCalledOnce()
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('offers no shared block action without a handler', () => {
    render(<ElementTypePicker {...defaultProps} />)

    expect(screen.queryByTestId('element-type-picker-shared')).not.toBeInTheDocument()
  })
})

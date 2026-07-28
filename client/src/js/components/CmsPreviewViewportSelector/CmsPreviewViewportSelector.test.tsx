import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as activeViewportStore from '@/state/activeViewport'
import { resetActiveViewportStore, setActiveViewport } from '@/state/activeViewport'
import CmsPreviewViewportSelector from './CmsPreviewViewportSelector'

vi.mock('@/utils/gridAdapter', () => ({
  getDefaultViewport: () => 'md',
  getViewports: () => [
    { key: 'sm', label: 'Small', minWidth: 576 },
    { key: 'md', label: 'Medium', minWidth: 768 },
    { key: 'lg', label: 'Large', minWidth: 992 },
  ],
}))

describe('CmsPreviewViewportSelector', () => {
  afterEach(() => {
    resetActiveViewportStore()
  })

  it('renders one button per adapter viewport', () => {
    render(<CmsPreviewViewportSelector />)

    expect(screen.getByRole('button', { name: 'Small' })).toBeDefined()
    expect(screen.getByRole('button', { name: 'Medium' })).toBeDefined()
    expect(screen.getByRole('button', { name: 'Large' })).toBeDefined()
  })

  it('marks the active viewport with aria-pressed', () => {
    setActiveViewport('md')
    render(<CmsPreviewViewportSelector />)

    expect(screen.getByRole('button', { name: 'Medium' }).getAttribute('aria-pressed')).toBe('true')
    expect(screen.getByRole('button', { name: 'Small' }).getAttribute('aria-pressed')).toBe('false')
  })

  it('updates the active viewport on click', () => {
    render(<CmsPreviewViewportSelector />)
    fireEvent.click(screen.getByRole('button', { name: 'Large' }))

    expect(screen.getByRole('button', { name: 'Large' }).getAttribute('aria-pressed')).toBe('true')
  })

  it('does not call the setter when the already-active button is clicked', () => {
    setActiveViewport('md')
    // Spy AFTER seeding so the initial store write isn't counted. The store
    // no-ops on an unchanged key, so only spying on the setter distinguishes
    // the `!isActive` guard from a mutant that always calls it.
    const setSpy = vi.spyOn(activeViewportStore, 'setActiveViewport')

    render(<CmsPreviewViewportSelector />)

    fireEvent.click(screen.getByRole('button', { name: 'Medium' }))
    expect(setSpy).not.toHaveBeenCalled()

    // Sanity: an inactive button still calls the setter.
    fireEvent.click(screen.getByRole('button', { name: 'Large' }))
    expect(setSpy).toHaveBeenCalledWith('lg')

    setSpy.mockRestore()
  })
})

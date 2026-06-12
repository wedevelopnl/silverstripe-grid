import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
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
})

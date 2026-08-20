import { type RenderResult, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'
import type { ElementStatus } from '@/types/status'

export interface ChromeContractOverrides {
  status?: ElementStatus
  hasUnpublishedDescendant?: boolean
  isCollapsed?: boolean
  dropTarget?: boolean
  titleHref?: string
  leading?: ReactNode
  trailing?: ReactNode
}

/**
 * Behaves-like suite for the contract the three chrome components
 * (SectionChrome / RowChrome / ColumnChrome) implement in parallel: the
 * status/collapse/drop-target data attributes, the titleHref branch, the
 * StatusBadge/UnpublishedIndicator matrix, and the leading/trailing slots.
 * Component-specific behavior (bodyId wiring, columnCount, hidden, extra
 * slots) stays in each chrome's own test file.
 */
export function describeChromeContract(options: {
  /** Test-id prefix: 'section' | 'row' | 'column'. */
  prefix: string
  /** The base props' title text, for the titleHref assertions. */
  title: string
  renderChrome: (overrides?: ChromeContractOverrides) => RenderResult
}): void {
  const { prefix, title, renderChrome } = options

  describe('chrome contract', () => {
    it('applies data-status from the status prop', () => {
      renderChrome({ status: 'draft' })

      expect(screen.getByTestId(`${prefix}-block`)).toHaveAttribute('data-status', 'draft')
    })

    it('sets data-collapsed when isCollapsed is true', () => {
      renderChrome({ isCollapsed: true })

      expect(screen.getByTestId(`${prefix}-block`)).toHaveAttribute('data-collapsed', '')
    })

    it('does not set data-collapsed when isCollapsed is false', () => {
      renderChrome({ isCollapsed: false })

      expect(screen.getByTestId(`${prefix}-block`)).not.toHaveAttribute('data-collapsed')
    })

    it('sets data-drop-target when dropTarget is true', () => {
      renderChrome({ dropTarget: true })

      expect(screen.getByTestId(`${prefix}-block`)).toHaveAttribute('data-drop-target', '')
    })

    it('does not set data-drop-target when dropTarget is falsy', () => {
      renderChrome()

      expect(screen.getByTestId(`${prefix}-block`)).not.toHaveAttribute('data-drop-target')
    })

    it('renders the title as a link when titleHref is provided', () => {
      renderChrome({ titleHref: '/admin/pages/edit/show/5' })

      const link = screen.getByTestId(`${prefix}-edit-link`)
      expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5')
      expect(link).toHaveTextContent(title)
    })

    it('renders the title as plain text when titleHref is omitted', () => {
      renderChrome()

      expect(screen.queryByTestId(`${prefix}-edit-link`)).not.toBeInTheDocument()
      expect(screen.getByTestId(`${prefix}-title`)).toHaveTextContent(title)
    })

    it('badges the element and adds no descendant note when only the element is modified', () => {
      renderChrome({ status: 'modified' })

      expect(screen.getByTestId(`${prefix}-status-badge`)).toBeInTheDocument()
      expect(screen.queryByTestId(`${prefix}-unpublished-indicator`)).not.toBeInTheDocument()
    })

    it('announces the descendant, with no badge, when only a descendant is modified', () => {
      renderChrome({ status: 'published', hasUnpublishedDescendant: true })

      expect(screen.getByTestId(`${prefix}-unpublished-indicator`)).toBeInTheDocument()
      expect(screen.queryByTestId(`${prefix}-status-badge`)).not.toBeInTheDocument()
    })

    it('badges the element and announces the descendant when both are modified', () => {
      renderChrome({ status: 'modified', hasUnpublishedDescendant: true })

      expect(screen.getByTestId(`${prefix}-status-badge`)).toBeInTheDocument()
      expect(screen.getByTestId(`${prefix}-unpublished-indicator`)).toBeInTheDocument()
    })

    it('sets data-descendant-unpublished only when a descendant is unpublished', () => {
      const { unmount } = renderChrome()
      expect(screen.getByTestId(`${prefix}-block`)).not.toHaveAttribute(
        'data-descendant-unpublished',
      )
      unmount()

      renderChrome({ hasUnpublishedDescendant: true })
      expect(screen.getByTestId(`${prefix}-block`)).toHaveAttribute('data-descendant-unpublished')
    })

    it('badges a never-published element as draft', () => {
      renderChrome({ status: 'draft' })

      expect(screen.getByTestId(`${prefix}-status-badge`)).toHaveTextContent('Draft')
    })

    it('renders neither mark when nothing is unpublished', () => {
      renderChrome({ status: 'published' })

      expect(screen.queryByTestId(`${prefix}-status-badge`)).not.toBeInTheDocument()
      expect(screen.queryByTestId(`${prefix}-unpublished-indicator`)).not.toBeInTheDocument()
    })

    it('renders the leading and trailing slots when provided', () => {
      renderChrome({
        leading: <span data-testid="leading-slot" />,
        trailing: <span data-testid="trailing-slot" />,
      })

      expect(screen.getByTestId('leading-slot')).toBeInTheDocument()
      expect(screen.getByTestId('trailing-slot')).toBeInTheDocument()
    })

    it('omits the leading and trailing slots when absent', () => {
      renderChrome()

      expect(screen.queryByTestId('leading-slot')).not.toBeInTheDocument()
      expect(screen.queryByTestId('trailing-slot')).not.toBeInTheDocument()
    })
  })
}

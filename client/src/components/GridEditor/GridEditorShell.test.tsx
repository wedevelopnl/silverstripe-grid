import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { createSectionNode, resetIdCounter } from '@/testing/factories'
import { renderWithProviders } from '@/testing/renderWithProviders'
import GridEditorShell from './GridEditorShell'

describe('GridEditorShell', () => {
  it('renders only the loading notice in loading status', () => {
    renderWithProviders(
      <GridEditorShell pageId={1} zone="main" readonly={false} status="loading" error={null} sections={[]}>
        <div data-testid="canvas-child" />
      </GridEditorShell>,
    )
    expect(screen.getByTestId('grid-editor-loading')).toBeInTheDocument()
    expect(screen.queryByTestId('grid-editor-canvas')).not.toBeInTheDocument()
    expect(screen.queryByTestId('canvas-child')).not.toBeInTheDocument()
  })

  it('renders children inside the canvas in ready status', () => {
    renderWithProviders(
      <GridEditorShell pageId={1} zone="main" readonly={false} status="ready" error={null} sections={[]}>
        <div data-testid="canvas-child" />
      </GridEditorShell>,
    )
    expect(screen.getByTestId('grid-editor-canvas')).toContainElement(screen.getByTestId('canvas-child'))
    expect(screen.getByTestId('viewport-switcher')).toBeInTheDocument()
  })

  it('flags the canvas modified when any section is modified', () => {
    resetIdCounter()
    const section = createSectionNode({
      id: 10,
      parent: { type: 'page', id: 1 },
      title: 'Hero',
      status: 'modified',
    })
    renderWithProviders(
      <GridEditorShell pageId={1} zone="main" readonly={false} status="ready" error={null} sections={[section]}>
        <div />
      </GridEditorShell>,
    )
    expect(screen.getByTestId('grid-editor-canvas')).toHaveAttribute('data-status', 'modified')
  })

  it('sets data-page-id, data-zone and data-readonly on the root', () => {
    renderWithProviders(
      <GridEditorShell pageId={7} zone="sidebar" readonly status="loading" error={null} sections={[]}>
        <div />
      </GridEditorShell>,
    )
    const root = screen.getByTestId('grid-editor')
    expect(root).toHaveAttribute('data-page-id', '7')
    expect(root).toHaveAttribute('data-zone', 'sidebar')
    expect(root).toHaveAttribute('data-readonly', '')
  })
})

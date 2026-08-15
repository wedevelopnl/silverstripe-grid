import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ApiError } from '@/api/errors'
import { createSectionNode, createTreeApiResponse, resetIdCounter } from '@/testing/factories'
import { renderWithProviders } from '@/testing/renderWithProviders'
import type { TreeApiResponse } from '@/types/elements'
import GridEditorShell, { type GridEditorStatus, resolveGridEditorStatus } from './GridEditorShell'

describe('GridEditorShell', () => {
  it('renders only the loading notice in loading status', () => {
    renderWithProviders(
      <GridEditorShell
        pageId={1}
        zone="main"
        readonly={false}
        status="loading"
        error={null}
        sections={[]}
      >
        <div data-testid="canvas-child" />
      </GridEditorShell>,
    )
    expect(screen.getByTestId('grid-editor-loading')).toBeInTheDocument()
    expect(screen.queryByTestId('grid-editor-canvas')).not.toBeInTheDocument()
    expect(screen.queryByTestId('canvas-child')).not.toBeInTheDocument()
  })

  it('renders children inside the canvas in ready status', () => {
    renderWithProviders(
      <GridEditorShell
        pageId={1}
        zone="main"
        readonly={false}
        status="ready"
        error={null}
        sections={[]}
      >
        <div data-testid="canvas-child" />
      </GridEditorShell>,
    )
    expect(screen.getByTestId('grid-editor-canvas')).toContainElement(
      screen.getByTestId('canvas-child'),
    )
    expect(screen.getByTestId('viewport-switcher')).toBeInTheDocument()
    expect(screen.getByTestId('grid-editor-canvas')).not.toHaveAttribute(
      'data-descendant-unpublished',
    )
  })

  it('flags the canvas when any section is unpublished', () => {
    resetIdCounter()
    const section = createSectionNode({
      id: 10,
      parent: { type: 'page', id: 1 },
      title: 'Hero',
      status: 'modified',
    })
    renderWithProviders(
      <GridEditorShell
        pageId={1}
        zone="main"
        readonly={false}
        status="ready"
        error={null}
        sections={[section]}
      >
        <div />
      </GridEditorShell>,
    )
    expect(screen.getByTestId('grid-editor-canvas')).toHaveAttribute('data-descendant-unpublished')
  })

  it('renders the error notice with the interpolated message when status is error', () => {
    renderWithProviders(
      <GridEditorShell
        pageId={1}
        zone="main"
        readonly={false}
        status="error"
        error={new ApiError(500, 'boom')}
        sections={[]}
      >
        <div />
      </GridEditorShell>,
    )
    const notice = screen.getByTestId('grid-editor-error')
    expect(notice).toBeInTheDocument()
    expect(notice).toHaveTextContent('boom')
  })

  it('does not render the error notice when status is ready even if an error is present', () => {
    // Separates the `status === 'error' && error !== null` conjunction from a
    // `||` mutant and from a `true && error !== null` mutant: with a non-error
    // status but a set error, the banner must stay hidden.
    renderWithProviders(
      <GridEditorShell
        pageId={1}
        zone="main"
        readonly={false}
        status="ready"
        error={new ApiError(500, 'boom')}
        sections={[]}
      >
        <div />
      </GridEditorShell>,
    )
    expect(screen.queryByTestId('grid-editor-error')).not.toBeInTheDocument()
  })

  it('does not render the error notice in loading status with no error', () => {
    // Kills the `|| error` mutant whose right operand (the JSX element) is always
    // truthy: with status 'loading' and no error the banner must stay hidden.
    renderWithProviders(
      <GridEditorShell
        pageId={1}
        zone="main"
        readonly={false}
        status="loading"
        error={null}
        sections={[]}
      >
        <div />
      </GridEditorShell>,
    )
    expect(screen.queryByTestId('grid-editor-error')).not.toBeInTheDocument()
  })

  it('sets data-page-id, data-zone and data-readonly on the root', () => {
    renderWithProviders(
      <GridEditorShell
        pageId={7}
        zone="sidebar"
        readonly
        status="loading"
        error={null}
        sections={[]}
      >
        <div />
      </GridEditorShell>,
    )
    const root = screen.getByTestId('grid-editor')
    expect(root).toHaveAttribute('data-page-id', '7')
    expect(root).toHaveAttribute('data-zone', 'sidebar')
    expect(root).toHaveAttribute('data-readonly', '')
  })
})

describe('resolveGridEditorStatus', () => {
  const tree: TreeApiResponse = createTreeApiResponse({ pageId: 1, sections: [] })
  const apiError = new ApiError(500, 'boom')

  it.each<{
    name: string
    data: TreeApiResponse | undefined
    error: ApiError | null
    expected: GridEditorStatus
  }>([
    { name: 'data present and no error', data: tree, error: null, expected: 'ready' },
    // Precedence: a loaded tree wins even when a (background-refetch) error is set.
    {
      name: 'data present and error set (data wins)',
      data: tree,
      error: apiError,
      expected: 'ready',
    },
    { name: 'no data and error set', data: undefined, error: apiError, expected: 'error' },
    { name: 'no data and no error', data: undefined, error: null, expected: 'loading' },
  ])('returns "$expected" when $name', ({ data, error, expected }) => {
    expect(resolveGridEditorStatus(data, error)).toBe(expected)
  })
})

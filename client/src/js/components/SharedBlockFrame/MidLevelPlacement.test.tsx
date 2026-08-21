import { useSortable } from '@dnd-kit/sortable'
import { screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EditableColumnBlock from '@/components/ColumnBlock/EditableColumnBlock'
import EditableRowBlock from '@/components/RowBlock/EditableRowBlock'
import EditableSectionBlock from '@/components/SectionBlock/EditableSectionBlock'
import { useDragContext } from '@/hooks/useDragAndDrop'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSharedBlockReferenceNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'
import { resetDndMocks } from '@/testing/mockDndKit'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'
import type { NodeKey } from '@/types/identity'
import SharedBlockFrame from './SharedBlockFrame'

/** Every `items` array handed to a SortableContext during the current render. */
const { sortableItems } = vi.hoisted(() => ({ sortableItems: [] as NodeKey[][] }))

vi.mock('@dnd-kit/sortable', async () => {
  const { mockSortableModule } = await import('@/testing/mockDndKit')

  return {
    ...mockSortableModule(),
    SortableContext: ({ items, children }: { items: NodeKey[]; children: React.ReactNode }) => {
      sortableItems.push(items)
      return <>{children}</>
    },
  }
})

vi.mock('@dnd-kit/core', async (importOriginal) =>
  (await import('@/testing/mockDndKit')).mockDndCoreModule(await importOriginal()),
)

vi.mock('@/hooks/useDragAndDrop', async () =>
  (await import('@/testing/mockDndKit')).mockUseDragContextModule(),
)

beforeEach(() => {
  resetIdCounter()
  mockFetchSuccess({})
  sortableItems.length = 0
})

afterEach(() => {
  resetDndMocks({ useSortable, useDragContext })
})

/** All sortable ids registered anywhere in the rendered tree. */
function registeredSortableIds(): NodeKey[] {
  return sortableItems.flat()
}

describe('a placement never registers as a sortable', () => {
  // SharedBlockFrame is deliberately not a sortable, and the sortable rendered
  // inside it carries the block ROOT's nodeKey. A placement key left in `items`
  // is an id with no rect: dnd-kit's getSortedRects gets a hole, getItemGap
  // finds no neighbour on either side, and the siblings after it shift by the
  // wrong distance mid-drag.

  it('is left out of a section’s row sortables', () => {
    const rowBefore = createRowNode({ id: 11 })
    const placement = createSharedBlockReferenceNode({ id: 12, root: createRowNode({ id: 13 }) })
    const rowAfter = createRowNode({ id: 14 })
    const section = createSectionNode({ children: [rowBefore, placement, rowAfter] })

    renderWithProviders(<EditableSectionBlock section={section} />)

    const ids = registeredSortableIds()
    expect(ids).toContain(rowBefore.nodeKey)
    expect(ids).toContain(rowAfter.nodeKey)
    expect(ids).not.toContain(placement.nodeKey)
  })

  it('is left out of a row’s column sortables', () => {
    const column = createColumnNode({ id: 21 })
    const placement = createSharedBlockReferenceNode({
      id: 22,
      root: createColumnNode({ id: 23 }),
    })
    const row = createRowNode({ children: [column, placement] })

    renderWithProviders(<EditableRowBlock row={row} />)

    const ids = registeredSortableIds()
    expect(ids).toContain(column.nodeKey)
    expect(ids).not.toContain(placement.nodeKey)
  })

  it('is left out of a column’s element sortables', () => {
    const element = createSimpleElement({ id: 31 })
    const placement = createSharedBlockReferenceNode({
      id: 32,
      root: createSimpleElement({ id: 33 }),
    })
    const column = createColumnNode({ children: [element, placement], childCount: 0 })

    renderWithProviders(<EditableColumnBlock column={column} />)

    const ids = registeredSortableIds()
    expect(ids).toContain(element.nodeKey)
    expect(ids).not.toContain(placement.nodeKey)
  })
})

describe('a column-rooted placement in a row', () => {
  function rowWithPlacedColumn(width: number, offset = 0) {
    const placement = createSharedBlockReferenceNode({
      id: 42,
      root: createColumnNode({
        id: 43,
        gridSettings: { default: { width, offset, visible: true }, overrides: {} },
      }),
    })

    return {
      placement,
      row: createRowNode({ children: [createColumnNode({ id: 41 }), placement] }),
    }
  }

  it('carries the column width on the frame, which is the track item', () => {
    // The row's sizing rules are direct-child selectors on .ssgrid-row-columns.
    // With the frame in between, the .ssgrid-column two levels down is no
    // longer the grid item, so the frame has to carry the vars itself.
    const { row } = rowWithPlacedColumn(6)

    renderWithProviders(<EditableRowBlock row={row} />)

    const frame = screen.getByTestId('shared-block-frame')
    expect(frame.style.getPropertyValue('--col-width')).toBe('50%')
  })

  it('carries the column offset too', () => {
    const { row } = rowWithPlacedColumn(6, 3)

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getByTestId('shared-block-frame').style.getPropertyValue('--col-offset')).toBe(
      '25%',
    )
  })

  it('leaves the frame unsized for a shape that is not a column', () => {
    const placement = createSharedBlockReferenceNode({ id: 52, root: createRowNode({ id: 53 }) })
    const section = createSectionNode({ children: [placement] })

    renderWithProviders(<EditableSectionBlock section={section} />)

    expect(screen.getByTestId('shared-block-frame').style.getPropertyValue('--col-width')).toBe('')
  })

  it('renders no column-insert control inside the read-only frame', () => {
    // The frame's own bar carries the placement's actions; nothing inside it
    // may add or insert. The gutter control is offered per column, so a
    // placement at index >= 1 used to get one from its parent row.
    const { row } = rowWithPlacedColumn(6)

    renderWithProviders(<EditableRowBlock row={row} />)

    const inFrame = within(screen.getByTestId('shared-block-frame'))
    expect(inFrame.queryAllByTestId('column-insert-between')).toHaveLength(0)
  })
})

describe('a read-only frame', () => {
  it('requests no element tree of its own', () => {
    // Only `move` reads it, and readonly renders no actions at all. The
    // history viewer's own tree is version-scoped, so an unversioned fetch
    // here is waste that also seeds the draft cache from a read-only screen.
    renderWithProviders(
      <SharedBlockFrame node={createSharedBlockReferenceNode()} siblings={[]} readonly>
        <p>block content</p>
      </SharedBlockFrame>,
    )

    const treeCalls = getFetchCalls().filter(([url]) => String(url).includes('readTree'))
    expect(treeCalls).toHaveLength(0)
  })
})

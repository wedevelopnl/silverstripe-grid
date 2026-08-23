import { act, renderHook, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSharedBlockReferenceNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { createProviderWrapper } from '@/testing/renderWithProviders'
import type { RenderOptions } from '@/testing/renderWithProviders'
import { useConvertToSharedAction } from './useConvertToSharedAction'

beforeEach(() => {
  resetIdCounter()
  mockFetchSuccess({})
})

function renderConvertAction(
  node: Parameters<typeof useConvertToSharedAction>[0],
  options: RenderOptions = {},
) {
  const { wrapper } = createProviderWrapper(options)
  return renderHook(() => useConvertToSharedAction(node), { wrapper })
}

function convertActionFor(
  node: Parameters<typeof useConvertToSharedAction>[0],
  options: RenderOptions = {},
) {
  return renderConvertAction(node, options).result.current
}

describe('useConvertToSharedAction', () => {
  it('offers the action on ordinary page content', () => {
    expect(convertActionFor(createSectionNode()).action).not.toBeNull()
  })

  it('withholds it from a shared block placement', () => {
    // The placement is already shared; converting it again is meaningless.
    expect(convertActionFor(createSharedBlockReferenceNode()).action).toBeNull()
  })

  it('withholds it from content inside a shared block', () => {
    // Mirrors the server's no-nesting rule, so the action never appears where
    // it would be rejected.
    const placement = createSharedBlockReferenceNode({ root: createSectionNode() })
    const insideBlock = placement.children[0]

    expect(insideBlock).toBeDefined()
    expect(convertActionFor(insideBlock!).action).toBeNull()
  })

  it('withholds it from content that itself holds a placement', () => {
    // Converting would carry the nested placement into the new block, which
    // then fails SHARED_NESTING on its first publish and cannot be repaired
    // from the editor. The server refuses it; this keeps it off the menu.
    const section = createSectionNode({
      children: [createSharedBlockReferenceNode({ root: createRowNode() })],
    })

    expect(convertActionFor(section).action).toBeNull()
  })

  it('withholds it when the placement sits deeper in the subtree', () => {
    const column = createColumnNode({
      children: [createSharedBlockReferenceNode({ root: createSimpleElement() })],
    })
    const section = createSectionNode({
      children: [createRowNode({ children: [column] })],
    })

    expect(convertActionFor(section).action).toBeNull()
  })

  it('still offers it on a subtree with no placement anywhere below', () => {
    const section = createSectionNode({
      children: [createRowNode({ children: [createColumnNode()] })],
    })

    expect(convertActionFor(section).action).not.toBeNull()
  })

  it('withholds it when the author may not create records', () => {
    expect(convertActionFor(createSimpleElement({ canCreate: false })).action).toBeNull()
  })

  it('withholds it everywhere in the library editor', () => {
    // The library editor's tree is rooted at the BLOCK, so it holds no
    // placement node and nothing in it carries `sharedBlockKey` — the per-node
    // check above sees page-local content and cannot catch this on its own.
    expect(
      convertActionFor(createSectionNode(), { root: { kind: 'sharedBlock', blockId: 1 } }).action,
    ).toBeNull()
  })

  it('converts the element the action was offered on, with its title', async () => {
    const node = createSectionNode({ title: 'Promo banner' })
    const { result } = renderConvertAction(node)

    act(() => {
      result.current.dialog?.onConfirm()
    })

    await waitFor(() => {
      expect(
        getFetchCalls().some(([u]) => String(u).includes('/admin/grid-shared-blocks/api/convert')),
      ).toBe(true)
    })

    const [url, init] = getFetchCalls().filter(([u]) =>
      String(u).includes('/admin/grid-shared-blocks/api/convert'),
    )[0]
    expect(url).toBe('/admin/grid-shared-blocks/api/convert')
    expect(JSON.parse(String(init?.body))).toEqual({
      element: node.self,
      title: 'Promo banner',
    })
  })

  it('closes the dialog once the conversion is confirmed', () => {
    const { result } = renderConvertAction(createSectionNode())

    act(() => result.current.action?.onAction?.())
    expect(result.current.dialog?.isOpen).toBe(true)

    act(() => {
      result.current.dialog?.onConfirm()
    })
    expect(result.current.dialog?.isOpen).toBe(false)
  })
})

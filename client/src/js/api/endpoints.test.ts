import { describe, expect, it } from 'vitest'
import { resetIdCounter } from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import {
  isColumnNode,
  isContainerNode,
  isSectionNode,
  isSharedBlockReferenceNode,
} from '@/types/elements'
import {
  archiveElement,
  convertToSharedBlock,
  createContentElement,
  createElement,
  duplicateElement,
  detachSharedBlock,
  duplicateToElement,
  fetchAcceptableContainers,
  fetchTree,
  fetchPages,
  fetchSharedBlocks,
  fetchZones,
  normaliseTreeResponse,
  placeSharedBlock,
  publishElement,
  reorderElement,
  resetGridSettingsOverrides,
  setSharedBlockPublished,
  unpublishElement,
  updateGridSettings,
} from './endpoints'

/** Wire root map with no allowed child types — the minimal valid shape. */
const EMPTY_ALLOWED = { section: {}, row: {}, column: {} }

/** Minimal valid readTree body for the URL-construction tests. */
const EMPTY_TREE_BODY = {
  rootParent: { type: 'page', id: 42 },
  allowedTypes: EMPTY_ALLOWED,
  nodes: [],
}

/**
 * Base wire-shape section node for normaliseTreeResponse tests — spread and
 * override per test (the pattern schemas.test.ts uses with `baseLeaf`).
 */
const baseWireSection = {
  self: { type: 'section', id: 10 },
  parent: { type: 'page', id: 1 },
  title: 'Section A',
  blockSchema: {
    typeName: 'Section',
    type: 'section',
    title: 'Section A',
    label: 'Section',
    icon: 'font-icon-block',
  },
  obsoleteClassName: null,
  version: 1,
  canDelete: true,
  canPublish: true,
  canUnpublish: false,
  canCreate: true,
  editLink: null,
  status: 'published',
  containerType: 'section',
  children: [],
}

beforeEach(() => {
  resetIdCounter()
  mockFetchSuccess({})
})

describe('fetchTree', () => {
  it('constructs correct URL with encoded zone and normalises the response', async () => {
    mockFetchSuccess(EMPTY_TREE_BODY)
    const result = await fetchTree({ kind: 'page', pageId: 42, zone: 'main area' })
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/readTree/42/main%20area')
    expect(result.rootParent).toEqual({ type: 'page', id: 42 })
  })

  it('appends /version/N path segment when version is provided', async () => {
    mockFetchSuccess(EMPTY_TREE_BODY)
    await fetchTree({ kind: 'page', pageId: 42, zone: 'main', version: 5 })
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/readTree/42/main/version/5')
  })

  it('omits /version path segment when version is undefined', async () => {
    mockFetchSuccess(EMPTY_TREE_BODY)
    await fetchTree({ kind: 'page', pageId: 42, zone: 'main' })
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/readTree/42/main')
  })

  it('reads the block-rooted route from the shared block controller', async () => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: EMPTY_ALLOWED,
      nodes: [],
    })

    const tree = await fetchTree({ kind: 'sharedBlock', blockId: 9 })

    expect(getFetchCalls()[0][0]).toBe('/admin/grid-shared-blocks/api/readTree/9')
    expect(tree.rootParent).toEqual({ type: 'sharedBlock', id: 9 })
  })
})

describe('normaliseTreeResponse', () => {
  it('attaches derived nodeKey and parentKey to every node', () => {
    const raw = {
      rootParent: { type: 'page', id: 1 },
      allowedTypes: EMPTY_ALLOWED,
      nodes: [{ ...baseWireSection }],
    }

    const normalised = normaliseTreeResponse(raw)
    expect(normalised.rootParent).toEqual({ type: 'page', id: 1 })
    expect(normalised.nodes[0].nodeKey).toBe('section-10')
    expect(normalised.nodes[0].parentKey).toBe('page-1')
  })

  it('attaches the root allowedTypes map to container nodes by reference', () => {
    const sectionTypes = { Foo: { label: 'Foo', icon: 'i', description: 'd' } }
    const node = (id: number) => ({
      ...baseWireSection,
      self: { type: 'section', id },
      title: `S${String(id)}`,
    })
    const raw = {
      rootParent: { type: 'page', id: 1 },
      allowedTypes: { ...EMPTY_ALLOWED, section: sectionTypes },
      nodes: [node(10), node(11)],
    }

    const normalised = normaliseTreeResponse(raw)
    const [first, second] = normalised.nodes
    expect(isSectionNode(first) && first.allowedTypes).toEqual(sectionTypes)
    // One shared map, not a per-node copy — the whole point of the root field.
    expect(
      isSectionNode(first) && isSectionNode(second) && first.allowedTypes === second.allowedTypes,
    ).toBe(true)
  })

  it('throws on malformed payload', () => {
    // Schema validation surfaces as ValiError (subclass of Error). Callers in
    // hooks/components surface this as a generic load error to the user.
    expect(() => normaliseTreeResponse(null)).toThrow()
    expect(() => normaliseTreeResponse({ rootParent: { type: 'page', id: 1 } })).toThrow()
  })

  it('rejects a node carrying an unknown wire field that bypasses the schema', () => {
    // A future server field must be caught by the wire schema, not silently
    // spread through attachDerivedFields. valibot is strict on the discriminated
    // container variants — an unexpected shape (container fields without a
    // valid containerType) must throw rather than produce a malformed node.
    const raw = {
      rootParent: { type: 'page', id: 1 },
      allowedTypes: EMPTY_ALLOWED,
      nodes: [
        // container-only fields present, but containerType is a bogus value:
        { ...baseWireSection, containerType: 'nonsense' },
      ],
    }
    expect(() => normaliseTreeResponse(raw)).toThrow()
  })

  it('attaches derived fields to a column node without losing gridSettings', () => {
    const raw = {
      rootParent: { type: 'page', id: 1 },
      allowedTypes: EMPTY_ALLOWED,
      nodes: [
        {
          ...baseWireSection,
          self: { type: 'column', id: 30 },
          parent: { type: 'row', id: 20 },
          title: 'Col',
          status: 'draft',
          containerType: 'column',
          gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: {} },
        },
      ],
    }
    const result = normaliseTreeResponse(raw)
    const node = result.nodes[0]
    expect(node.nodeKey).toBe('column-30')
    expect(isColumnNode(node) && node.gridSettings.default.width).toBe(12)
  })
})

describe('createElement', () => {
  it('sends POST to /api/create with NodeRef parent', async () => {
    await createElement({ containerType: 'row', parent: { type: 'section', id: 10 } })
    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/create')
    expect(init?.method).toBe('POST')
    expect(JSON.parse(init?.body as string)).toEqual({
      containerType: 'row',
      parent: { type: 'section', id: 10 },
    })
  })
})

describe('publishElement', () => {
  it('sends PATCH to /api/setPublished with element NodeRef and published:true', async () => {
    await publishElement({ type: 'section', id: 5 })
    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/setPublished')
    expect(init?.method).toBe('PATCH')
    expect(JSON.parse(init?.body as string)).toEqual({
      element: { type: 'section', id: 5 },
      published: true,
    })
  })
})

describe('unpublishElement', () => {
  it('sends PATCH to /api/setPublished with element NodeRef and published:false', async () => {
    await unpublishElement({ type: 'section', id: 5 })
    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/setPublished')
    expect(JSON.parse(init?.body as string)).toEqual({
      element: { type: 'section', id: 5 },
      published: false,
    })
  })
})

describe('archiveElement', () => {
  it('sends DELETE to /api/delete with type and id on the query string', async () => {
    await archiveElement({ type: 'element', id: 5 })
    const [url, init] = getFetchCalls()[0]
    const urlString = String(url)
    expect(urlString).toContain('/admin/grid/api/delete?')
    expect(urlString).toContain('type=element')
    expect(urlString).toContain('id=5')
    expect(init?.method).toBe('DELETE')
    expect(init?.body).toBeUndefined()
  })
})

describe('duplicateElement', () => {
  it('sends POST to /api/duplicate with element NodeRef', async () => {
    await duplicateElement({ type: 'section', id: 5 })
    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/duplicate')
    expect(JSON.parse(init?.body as string)).toEqual({ element: { type: 'section', id: 5 } })
  })
})

describe('reorderElement', () => {
  it('sends PATCH to /api/reorder with scoped NodeRef fields', async () => {
    await reorderElement({
      element: { type: 'row', id: 1 },
      parent: { type: 'section', id: 2 },
      after: { type: 'row', id: 3 },
    })
    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/reorder')
    expect(JSON.parse(init?.body as string)).toEqual({
      element: { type: 'row', id: 1 },
      parent: { type: 'section', id: 2 },
      after: { type: 'row', id: 3 },
    })
  })

  it('sends null after when inserting at the head of the target container', async () => {
    await reorderElement({
      element: { type: 'section', id: 5 },
      parent: { type: 'page', id: 1 },
      after: null,
    })
    const [, init] = getFetchCalls()[0]
    expect(JSON.parse(init?.body as string).after).toBeNull()
  })
})

describe('createContentElement', () => {
  it('sends POST to /api/create with parent NodeRef', async () => {
    await createContentElement({
      className: 'TextBlock',
      parent: { type: 'column', id: 10 },
    })
    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/create')
    expect(JSON.parse(init?.body as string)).toEqual({
      className: 'TextBlock',
      parent: { type: 'column', id: 10 },
    })
  })
})

describe('updateGridSettings', () => {
  it('sends PATCH to /api/updateGridSettings with element NodeRef', async () => {
    await updateGridSettings({
      element: { type: 'column', id: 1 },
      viewport: 'md',
      width: 6,
      offset: 0,
      visible: true,
    })
    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/updateGridSettings')
    expect(JSON.parse(init?.body as string)).toEqual({
      element: { type: 'column', id: 1 },
      viewport: 'md',
      width: 6,
      offset: 0,
      visible: true,
    })
  })
})

describe('resetGridSettingsOverrides', () => {
  it('sends DELETE to /api/resetGridSettingsOverrides with params in query string', async () => {
    await resetGridSettingsOverrides({ pageId: 1, zone: 'main' })
    const [url, init] = getFetchCalls()[0]
    const urlString = String(url)
    expect(urlString).toContain('/admin/grid/api/resetGridSettingsOverrides?')
    expect(urlString).toContain('pageId=1')
    expect(urlString).toContain('zone=main')
    expect(init?.method).toBe('DELETE')
    expect(init?.body).toBeUndefined()
  })
})

describe('duplicateToElement', () => {
  it('sends POST to /api/duplicateTo with element + targetParent NodeRefs', async () => {
    await duplicateToElement({
      element: { type: 'element', id: 1 },
      targetPageId: 2,
      targetZone: 'main',
      targetParent: { type: 'column', id: 3 },
    })
    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/duplicateTo')
    const body = JSON.parse(init?.body as string)
    expect(body.element).toEqual({ type: 'element', id: 1 })
    expect(body.targetParent).toEqual({ type: 'column', id: 3 })
  })
})

describe('fetchAcceptableContainers', () => {
  it('constructs correct URL with encoded params', async () => {
    mockFetchSuccess([])
    await fetchAcceptableContainers(1, 'main zone', 'Text Block')
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/acceptableContainers/1/main%20zone/Text%20Block')
  })
})

describe('fetchZones', () => {
  it('constructs correct URL', async () => {
    mockFetchSuccess([])
    await fetchZones(42)
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/zones/42')
  })
})

describe('fetchPages', () => {
  it('constructs URL without params when no search', async () => {
    mockFetchSuccess([])
    await fetchPages()
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/pages')
  })

  it('includes encoded search param', async () => {
    mockFetchSuccess([])
    await fetchPages('my page')
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/pages?search=my%20page')
  })
})

describe('shared block placements', () => {
  const sharedBlock = {
    blockId: 7,
    title: 'Banner',
    usageCount: 2,
    status: 'published' as const,
    editLink: '/admin/shared-blocks/item/7/edit',
  }

  const wireLeaf = (id: number, parent: { type: string; id: number }) => ({
    self: { type: 'element', id },
    parent,
    title: `Leaf ${id}`,
    blockSchema: { typeName: 'Foo', label: 'Foo', icon: 'i', type: 'Foo', title: 'Foo' },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: null,
    status: 'draft',
  })

  const wireColumn = (id: number, parent: { type: string; id: number }, children: unknown[]) => ({
    ...wireLeaf(id, parent),
    self: { type: 'column', id },
    containerType: 'column',
    gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: {} },
    children,
  })

  /** A reference at page root wrapping column 20, which holds leaf 30. */
  const referenceTree = {
    rootParent: { type: 'page', id: 1 },
    allowedTypes: EMPTY_ALLOWED,
    nodes: [
      {
        ...wireLeaf(10, { type: 'page', id: 1 }),
        sharedBlock,
        children: [
          wireColumn(20, { type: 'element', id: 10 }, [wireLeaf(30, { type: 'column', id: 20 })]),
        ],
      },
      {
        ...wireLeaf(40, { type: 'page', id: 1 }),
        self: { type: 'section', id: 40 },
        containerType: 'section',
        children: [],
      },
    ],
  }

  it('stamps sharedBlockKey on every descendant but not on the placement itself', () => {
    const [reference, sibling] = normaliseTreeResponse(referenceTree).nodes
    expect(isSharedBlockReferenceNode(reference)).toBe(true)
    if (!isSharedBlockReferenceNode(reference)) return

    expect(reference.nodeKey).toBe('element-10')
    expect(reference.sharedBlockKey).toBeUndefined()

    const root = reference.children[0]
    expect(root?.sharedBlockKey).toBe('element-10')
    expect(isColumnNode(root!) && root.children![0].sharedBlockKey).toBe('element-10')

    expect(sibling.sharedBlockKey).toBeUndefined()
  })

  it('keeps the placement typed as an element node carrying its block meta', () => {
    const [reference] = normaliseTreeResponse(referenceTree).nodes

    expect(isContainerNode(reference)).toBe(false)
    expect(isSharedBlockReferenceNode(reference)).toBe(true)
    if (!isSharedBlockReferenceNode(reference)) return

    expect(reference.sharedBlock).toEqual(sharedBlock)
  })

  it('normalises an empty block to an empty children list', () => {
    const tree = normaliseTreeResponse({
      ...referenceTree,
      nodes: [{ ...wireLeaf(10, { type: 'page', id: 1 }), sharedBlock, children: [] }],
    })

    const [reference] = tree.nodes
    expect(isSharedBlockReferenceNode(reference) && reference.children).toEqual([])
  })

  it('parents the block root to the placement, not to the page', () => {
    const [reference] = normaliseTreeResponse(referenceTree).nodes

    expect(isSharedBlockReferenceNode(reference) && reference.children[0]?.parentKey).toBe(
      'element-10',
    )
  })
})

describe('shared block endpoints', () => {
  it('fetchSharedBlocks requests the filtered list and validates it', async () => {
    mockFetchSuccess([
      { id: 3, title: 'Banner', rootType: 'section', usageCount: 2, status: 'modified' },
    ])

    const blocks = await fetchSharedBlocks('column')

    expect(getFetchCalls()[0][0]).toBe('/admin/grid-shared-blocks/api/list?parentType=column')
    expect(blocks).toEqual([
      { id: 3, title: 'Banner', rootType: 'section', usageCount: 2, status: 'modified' },
    ])
  })

  it('fetchSharedBlocks accepts an entry for a still-empty block', async () => {
    mockFetchSuccess([
      { id: 3, title: 'New', rootType: null, usageCount: 0, status: 'notPublished' },
    ])

    await expect(fetchSharedBlocks('page')).resolves.toHaveLength(1)
  })

  it('placeSharedBlock posts the exact body', async () => {
    await placeSharedBlock({
      blockId: 3,
      parent: { type: 'page', id: 1 },
      zone: 'main',
      insertAfterElementID: 8,
    })

    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid-shared-blocks/api/place')
    expect(init?.method).toBe('POST')
    expect(JSON.parse(String(init?.body))).toEqual({
      blockId: 3,
      parent: { type: 'page', id: 1 },
      zone: 'main',
      insertAfterElementID: 8,
    })
  })

  it('convertToSharedBlock returns the new block id', async () => {
    mockFetchSuccess({ blockId: 12 })

    await expect(
      convertToSharedBlock({ element: { type: 'section', id: 4 }, title: 'Hero' }),
    ).resolves.toEqual({
      blockId: 12,
    })
    expect(getFetchCalls()[0][0]).toBe('/admin/grid-shared-blocks/api/convert')
  })

  it('detachSharedBlock posts the placement ref', async () => {
    await detachSharedBlock({ element: { type: 'element', id: 10 } })

    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid-shared-blocks/api/detach')
    expect(JSON.parse(String(init?.body))).toEqual({ element: { type: 'element', id: 10 } })
  })

  it('setSharedBlockPublished patches the publish flag', async () => {
    await setSharedBlockPublished({ blockId: 3, published: true })

    const [url, init] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid-shared-blocks/api/setPublished')
    expect(init?.method).toBe('PATCH')
    expect(JSON.parse(String(init?.body))).toEqual({ blockId: 3, published: true })
  })
})

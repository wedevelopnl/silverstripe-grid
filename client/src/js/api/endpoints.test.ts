import { describe, expect, it } from 'vitest'
import { createTreeApiResponse, resetIdCounter } from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { isColumnNode } from '@/types/elements'
import {
  archiveElement,
  createContentElement,
  createElement,
  duplicateElement,
  duplicateToElement,
  fetchAcceptableContainers,
  fetchElementTree,
  fetchPages,
  fetchZones,
  normaliseTreeResponse,
  publishElement,
  reorderElement,
  resetGridSettingsOverrides,
  unpublishElement,
  updateGridSettings,
} from './endpoints'

beforeEach(() => {
  resetIdCounter()
  mockFetchSuccess({})
})

describe('fetchElementTree', () => {
  it('constructs correct URL with encoded zone and normalises the response', async () => {
    mockFetchSuccess({
      rootParent: { type: 'page', id: 42 },
      nodes: [],
    })
    const result = await fetchElementTree(42, 'main area')
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/readTree/42/main%20area')
    expect(result.rootParent).toEqual({ type: 'page', id: 42 })
  })

  it('appends /version/N path segment when version is provided', async () => {
    mockFetchSuccess({ rootParent: { type: 'page', id: 42 }, nodes: [] })
    await fetchElementTree(42, 'main', 5)
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/readTree/42/main/version/5')
  })

  it('omits /version path segment when version is undefined', async () => {
    mockFetchSuccess({ rootParent: { type: 'page', id: 42 }, nodes: [] })
    await fetchElementTree(42, 'main')
    const [url] = getFetchCalls()[0]
    expect(url).toBe('/admin/grid/api/readTree/42/main')
  })
})

describe('normaliseTreeResponse', () => {
  it('attaches derived nodeKey and parentKey to every node', () => {
    const raw = {
      rootParent: { type: 'page', id: 1 },
      nodes: [
        {
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
          allowedTypes: null,
          children: [],
        },
      ],
    }

    const normalised = normaliseTreeResponse(raw)
    expect(normalised.rootParent).toEqual({ type: 'page', id: 1 })
    expect(normalised.nodes[0].nodeKey).toBe('section-10')
    expect(normalised.nodes[0].parentKey).toBe('page-1')
  })

  it('throws on malformed payload', () => {
    // Schema validation surfaces as ZodError (subclass of Error). Callers in
    // hooks/components surface this as a generic load error to the user.
    expect(() => normaliseTreeResponse(null)).toThrow()
    expect(() => normaliseTreeResponse({ rootParent: { type: 'page', id: 1 } })).toThrow()
  })

  it('rejects a node carrying an unknown wire field that bypasses the schema', () => {
    // A future server field must be caught by the wire schema, not silently
    // spread through attachDerivedFields. Zod is strict on the discriminated
    // container variants — an unexpected shape (container fields without a
    // valid containerType) must throw rather than produce a malformed node.
    const raw = {
      rootParent: { type: 'page', id: 1 },
      nodes: [
        {
          self: { type: 'section', id: 10 },
          parent: { type: 'page', id: 1 },
          title: 'S',
          blockSchema: { typeName: 'S', label: 'S', icon: 'i', type: 's', title: 'S' },
          obsoleteClassName: null,
          version: 1,
          canDelete: true,
          canPublish: true,
          canUnpublish: false,
          canCreate: true,
          editLink: null,
          status: 'published',
          // container-only fields present, but containerType is a bogus value:
          containerType: 'nonsense',
          allowedTypes: null,
          children: [],
        },
      ],
    }
    expect(() => normaliseTreeResponse(raw)).toThrow()
  })

  it('attaches derived fields to a column node without losing gridSettings', () => {
    const raw = {
      rootParent: { type: 'page', id: 1 },
      nodes: [
        {
          self: { type: 'column', id: 30 },
          parent: { type: 'row', id: 20 },
          title: 'Col',
          blockSchema: {
            typeName: 'Column',
            label: 'Column',
            icon: 'i',
            type: 'column',
            title: 'Col',
          },
          obsoleteClassName: null,
          version: 1,
          canDelete: true,
          canPublish: true,
          canUnpublish: false,
          canCreate: true,
          editLink: null,
          status: 'draft',
          containerType: 'column',
          allowedTypes: null,
          children: [],
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

describe('factory integration — createTreeApiResponse', () => {
  it('produces a valid tree response shape', () => {
    const tree = createTreeApiResponse({ pageId: 1 })
    expect(tree.rootParent).toEqual({ type: 'page', id: 1 })
    expect(tree.nodes.length).toBeGreaterThan(0)
    expect(tree.nodes[0].nodeKey).toMatch(/^section-\d+$/)
  })
})

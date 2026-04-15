import { describe, it, expect } from 'vitest';
import {
  fetchElementTree,
  createElement,
  publishElement,
  unpublishElement,
  archiveElement,
  duplicateElement,
  reorderElement,
  createContentElement,
  updateGridSettings,
  resetGridSettingsOverrides,
  duplicateToElement,
  fetchAcceptableContainers,
  fetchZones,
  fetchPages,
  normaliseTreeResponse,
} from './endpoints';
import { mockFetchSuccess, getFetchCalls } from '@/testing/mockFetch';
import { createTreeApiResponse, resetIdCounter } from '@/testing/factories';

beforeEach(() => {
  resetIdCounter();
  mockFetchSuccess({});
});

describe('fetchElementTree', () => {
  it('constructs correct URL with encoded zone and normalises the response', async () => {
    mockFetchSuccess({
      rootParent: { type: 'page', id: 42 },
      nodes: [],
      overrideCounts: {},
    });
    const result = await fetchElementTree(42, 'main area');
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/readTree/42/main%20area');
    expect(result.rootParent).toEqual({ type: 'page', id: 42 });
  });

  it('appends /version/N path segment when version is provided', async () => {
    mockFetchSuccess({ rootParent: { type: 'page', id: 42 }, nodes: [], overrideCounts: {} });
    await fetchElementTree(42, 'main', 5);
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/readTree/42/main/version/5');
  });

  it('omits /version path segment when version is undefined', async () => {
    mockFetchSuccess({ rootParent: { type: 'page', id: 42 }, nodes: [], overrideCounts: {} });
    await fetchElementTree(42, 'main');
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/readTree/42/main');
  });
});

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
            summary: '',
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
          statusFlags: {},
          containerType: 'section',
          allowedTypes: null,
          children: [],
        },
      ],
      overrideCounts: { md: 2 },
    };

    const normalised = normaliseTreeResponse(raw);
    expect(normalised.rootParent).toEqual({ type: 'page', id: 1 });
    expect(normalised.nodes[0].nodeKey).toBe('section-10');
    expect(normalised.nodes[0].parentKey).toBe('page-1');
    expect(normalised.overrideCounts).toEqual({ md: 2 });
  });

  it('throws on malformed payload', () => {
    expect(() => normaliseTreeResponse(null)).toThrow(TypeError);
    expect(() => normaliseTreeResponse({ rootParent: { type: 'page', id: 1 } })).toThrow(TypeError);
  });
});

describe('createElement', () => {
  it('sends POST to /api/create with NodeRef parent', async () => {
    await createElement({ containerType: 'row', parent: { type: 'section', id: 10 } });
    const [url, init] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/create');
    expect(init?.method).toBe('POST');
    expect(JSON.parse(init?.body as string)).toEqual({
      containerType: 'row',
      parent: { type: 'section', id: 10 },
    });
  });
});

describe('publishElement', () => {
  it('sends PATCH to /api/publish', async () => {
    await publishElement(5);
    const [url, init] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/publish');
    expect(init?.method).toBe('PATCH');
    expect(JSON.parse(init?.body as string)).toEqual({ id: 5 });
  });
});

describe('unpublishElement', () => {
  it('sends PATCH to /api/unpublish', async () => {
    await unpublishElement(5);
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/unpublish');
  });
});

describe('archiveElement', () => {
  it('sends DELETE to /api/delete with id as query string', async () => {
    await archiveElement(5);
    const [url, init] = getFetchCalls()[0];
    expect(String(url)).toBe('/admin/grid/api/delete?id=5');
    expect(init?.method).toBe('DELETE');
    expect(init?.body).toBeUndefined();
  });
});

describe('duplicateElement', () => {
  it('sends POST to /api/duplicate', async () => {
    await duplicateElement(5);
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/duplicate');
  });
});

describe('reorderElement', () => {
  it('sends PATCH to /api/reorder with scoped NodeRef fields', async () => {
    await reorderElement({
      element: { type: 'row', id: 1 },
      parent: { type: 'section', id: 2 },
      after: { type: 'row', id: 3 },
    });
    const [url, init] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/reorder');
    expect(JSON.parse(init?.body as string)).toEqual({
      element: { type: 'row', id: 1 },
      parent: { type: 'section', id: 2 },
      after: { type: 'row', id: 3 },
    });
  });

  it('sends null after when inserting at the head of the target container', async () => {
    await reorderElement({
      element: { type: 'section', id: 5 },
      parent: { type: 'page', id: 1 },
      after: null,
    });
    const [, init] = getFetchCalls()[0];
    expect(JSON.parse(init?.body as string).after).toBeNull();
  });
});

describe('createContentElement', () => {
  it('sends POST to /api/createContent', async () => {
    await createContentElement({ className: 'TextBlock', parentId: 10 });
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/createContent');
  });
});

describe('updateGridSettings', () => {
  it('sends PATCH to /api/updateGridSettings', async () => {
    await updateGridSettings({ id: 1, viewport: 'md', width: 6, offset: 0, visible: true });
    const [url, init] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/updateGridSettings');
    expect(JSON.parse(init?.body as string)).toEqual({
      id: 1,
      viewport: 'md',
      width: 6,
      offset: 0,
      visible: true,
    });
  });
});

describe('resetGridSettingsOverrides', () => {
  it('sends DELETE to /api/resetGridSettingsOverrides with params in query string', async () => {
    await resetGridSettingsOverrides({ pageId: 1, zone: 'main' });
    const [url, init] = getFetchCalls()[0];
    const urlString = String(url);
    expect(urlString).toContain('/admin/grid/api/resetGridSettingsOverrides?');
    expect(urlString).toContain('pageId=1');
    expect(urlString).toContain('zone=main');
    expect(init?.method).toBe('DELETE');
    expect(init?.body).toBeUndefined();
  });
});

describe('duplicateToElement', () => {
  it('sends POST to /api/duplicateTo with targetParent NodeRef', async () => {
    await duplicateToElement({
      id: 1,
      targetPageId: 2,
      targetZone: 'main',
      targetParent: { type: 'column', id: 3 },
    });
    const [url, init] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/duplicateTo');
    expect(JSON.parse(init?.body as string).targetParent).toEqual({ type: 'column', id: 3 });
  });
});

describe('fetchAcceptableContainers', () => {
  it('constructs correct URL with encoded params', async () => {
    await fetchAcceptableContainers(1, 'main zone', 'Text Block');
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/acceptableContainers/1/main%20zone/Text%20Block');
  });
});

describe('fetchZones', () => {
  it('constructs correct URL', async () => {
    await fetchZones(42);
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/zones/42');
  });
});

describe('fetchPages', () => {
  it('constructs URL without params when no search', async () => {
    await fetchPages();
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/pages');
  });

  it('includes encoded search param', async () => {
    await fetchPages('my page');
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/pages?search=my%20page');
  });
});

describe('factory integration — createTreeApiResponse', () => {
  it('produces a valid tree response shape', () => {
    const tree = createTreeApiResponse({ pageId: 1 });
    expect(tree.rootParent).toEqual({ type: 'page', id: 1 });
    expect(tree.nodes.length).toBeGreaterThan(0);
    expect(tree.nodes[0].nodeKey).toMatch(/^section-\d+$/);
  });
});

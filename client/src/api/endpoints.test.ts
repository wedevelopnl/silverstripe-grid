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
} from './endpoints';
import { mockFetchSuccess, getFetchCalls } from '@/testing/mockFetch';

beforeEach(() => {
  mockFetchSuccess({});
});

describe('fetchElementTree', () => {
  it('constructs correct URL with encoded zone', async () => {
    await fetchElementTree(42, 'main area');
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/readTree/42/main%20area');
  });

  it('appends /version/N path segment when version is provided', async () => {
    await fetchElementTree(42, 'main', 5);
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/readTree/42/main/version/5');
  });

  it('omits /version path segment when version is undefined', async () => {
    await fetchElementTree(42, 'main');
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/readTree/42/main');
  });
});

describe('createElement', () => {
  it('sends POST to /api/create', async () => {
    await createElement({ containerType: 'row', parentId: 10 });
    const [url, init] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/create');
    expect(init?.method).toBe('POST');
    expect(JSON.parse(init?.body as string)).toEqual({
      containerType: 'row',
      parentId: 10,
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
  it('sends PATCH to /api/reorder with params', async () => {
    await reorderElement({ elementID: 1, targetParentId: 2, afterElementID: 3 });
    const [url, init] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/reorder');
    expect(JSON.parse(init?.body as string)).toEqual({
      elementID: 1,
      targetParentId: 2,
      afterElementID: 3,
    });
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
  it('sends POST to /api/duplicateTo', async () => {
    await duplicateToElement({ id: 1, targetPageId: 2, targetZone: 'main', targetParentId: 3 });
    const [url] = getFetchCalls()[0];
    expect(url).toBe('/admin/grid/api/duplicateTo');
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

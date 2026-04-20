import { expect, test } from '@playwright/test';
import type {
  ColumnNode,
  SectionNode,
  TreeApiResponse,
} from '@/types/elements';
import type { ElementStatus } from '@/types/status';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

const API_BASE = '/admin/grid/api/readTree';

test.describe('API contract', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('readTree response returns a valid element tree', async ({ request }) => {
    const fixture = await loadFixture(request, 'complex-page');
    const response = await request.get(`${API_BASE}/${fixture.pageId}/main`);

    expect(response.ok()).toBe(true);

    const { nodes } = await response.json() as TreeApiResponse;

    // Sanity: at least one section exists
    expect(nodes.length).toBeGreaterThan(0);
  });

  test('versioned state flags reflect fixture post-actions', async ({ request }) => {
    const fixture = await loadFixture(request, 'complex-page');
    const response = await request.get(`${API_BASE}/${fixture.pageId}/main`);
    const { nodes } = await response.json() as TreeApiResponse;

    // Collect all containers and leaf elements across the tree
    type FlaggedNode = { title: string; status: ElementStatus };
    const leaves: FlaggedNode[] = [];
    const containers: FlaggedNode[] = [];

    for (const section of nodes) {
      if (!('containerType' in section) || section.containerType !== 'section') continue;
      const sectionNode = section as SectionNode;
      containers.push({ title: sectionNode.title, status: sectionNode.status });
      for (const row of sectionNode.children ?? []) {
        containers.push({ title: row.title, status: row.status });
        for (const col of row.children ?? []) {
          containers.push({ title: col.title, status: col.status });
          for (const leaf of col.children ?? []) {
            leaves.push({ title: leaf.title, status: leaf.status });
          }
        }
      }
    }

    // Leaf assertions: publishRecursive → unpublish draft_leaf → modify modified_leaf
    const draft = leaves.find((l) => l.title === 'Draft Only Block');
    expect(draft, 'Draft Only Block not found in tree').toBeDefined();
    expect(draft!.status).toBe('draft');

    const published = leaves.find((l) => l.title === 'Published Block');
    expect(published, 'Published Block not found in tree').toBeDefined();
    expect(published!.status).toBe('published');

    const modified = leaves.find((l) => l.title.startsWith('Modified Text Block'));
    expect(modified, 'Modified Text Block not found in tree').toBeDefined();
    expect(modified!.status).toBe('modified');

    // Container assertions: modify row1 → unpublish section2 (cascades to descendants)
    const publishedSection = containers.find((c) => c.title === 'Main Section');
    expect(publishedSection, 'Main Section not found').toBeDefined();
    expect(publishedSection!.status).toBe('published');

    const modifiedRow = containers.find((c) => c.title.startsWith('First Row'));
    expect(modifiedRow, 'First Row not found').toBeDefined();
    expect(modifiedRow!.status).toBe('modified');

    const draftSection = containers.find((c) => c.title === 'Draft Section');
    expect(draftSection, 'Draft Section not found').toBeDefined();
    expect(draftSection!.status).toBe('draft');
  });

  test('reorder rejects cross-zone section move with 422', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'multi-zone');

    // Navigate to CMS so we're authenticated with session cookies
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toHaveCount(0, { timeout: 15_000 });

    // Extract SecurityID from the CMS config
    const securityId = await page.evaluate(() => (window as any).ss?.config?.SecurityID ?? '');
    expect(securityId).not.toBe('');

    // Get fixture IDs for sections in different zones
    const mainAlphaId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\Section']['main_alpha'];
    const sidebarAlphaId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\Section']['sidebar_alpha'];

    // Attempt cross-zone reorder via direct API call (NodeRef format)
    const response = await page.request.patch('/admin/grid/api/reorder', {
      headers: {
        'Content-Type': 'application/json',
        'X-SecurityID': securityId,
      },
      data: {
        element: { type: 'section', id: mainAlphaId },
        parent: { type: 'page', id: fixture.pageId },
        after: { type: 'section', id: sidebarAlphaId },
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.status).toBe('error');
    expect(body.errors[0].value).toContain('no longer exists');
  });

  test('column nodes include gridSettings with per-viewport structure', async ({ request }) => {
    const fixture = await loadFixture(request, 'complex-page');
    const response = await request.get(`${API_BASE}/${fixture.pageId}/main`);
    const { nodes } = await response.json() as TreeApiResponse;

    // Collect all column nodes
    const columns: ColumnNode[] = [];
    for (const section of nodes) {
      if (!('containerType' in section) || section.containerType !== 'section') continue;
      const sectionNode = section as SectionNode;
      for (const row of sectionNode.children ?? []) {
        for (const col of row.children ?? []) {
          columns.push(col);
        }
      }
    }

    expect(columns.length).toBeGreaterThanOrEqual(2);

    for (const col of columns) {
      // gridSettings uses default+overrides structure.
      // default holds the base values; overrides holds per-viewport deviations.
      expect(col.gridSettings).toBeDefined();
      expect(typeof col.gridSettings).toBe('object');
      expect(col.gridSettings).toHaveProperty('default');
      expect(col.gridSettings).toHaveProperty('overrides');

      const { default: defaults, overrides } = col.gridSettings;
      expect(typeof defaults.width, `Invalid default width type in column "${col.title}"`).toBe('number');
      expect(typeof defaults.offset, `Invalid default offset type in column "${col.title}"`).toBe('number');
      expect(typeof defaults.visible, `Invalid default visible type in column "${col.title}"`).toBe('boolean');

      for (const [vp, settings] of Object.entries(overrides)) {
        expect(typeof vp).toBe('string');
        expect(typeof settings.width, `Invalid width type in override "${vp}" of column "${col.title}"`).toBe('number');
        expect(typeof settings.offset, `Invalid offset type in override "${vp}" of column "${col.title}"`).toBe('number');
        expect(typeof settings.visible, `Invalid visible type in override "${vp}" of column "${col.title}"`).toBe('boolean');
      }
    }

    // Verify specific override values from ComplexPage.yml
    const leftCol = columns.find((c) => c.title === 'Left Column');
    expect(leftCol, 'Left Column not found').toBeDefined();
    expect(leftCol!.gridSettings.default).toEqual({ width: 8, offset: 0, visible: true });
    expect(leftCol!.gridSettings.overrides['lg']).toEqual({ width: 6, offset: 0, visible: true });
    expect(Object.keys(leftCol!.gridSettings.overrides)).toEqual(['lg']);

    const rightCol = columns.find((c) => c.title === 'Right Column');
    expect(rightCol, 'Right Column not found').toBeDefined();
    expect(rightCol!.gridSettings.default).toEqual({ width: 4, offset: 0, visible: true });
    expect(rightCol!.gridSettings.overrides['xs']).toEqual({ width: 12, offset: 0, visible: false });
    expect(rightCol!.gridSettings.overrides['lg']).toEqual({ width: 6, offset: 0, visible: true });
    expect(Object.keys(rightCol!.gridSettings.overrides)).toEqual(['xs', 'lg']);

    // Default column has empty overrides (all defaults apply as-is).
    const defaultCol = columns.find((c) => c.title === 'Draft Section Column');
    expect(defaultCol, 'Draft Section Column not found').toBeDefined();
    expect(defaultCol!.gridSettings.default).toEqual({ width: 12, offset: 0, visible: true });
    expect(Object.keys(defaultCol!.gridSettings.overrides)).toHaveLength(0);
  });

  test('element nodes include editLink field', async ({ request }) => {
    const fixture = await loadFixture(request, 'complex-page');
    const response = await request.get(`${API_BASE}/${fixture.pageId}/main`);
    const { nodes } = await response.json() as TreeApiResponse;

    for (const section of nodes) {
      expect(section).toHaveProperty('editLink');
      expect(typeof section.editLink === 'string' || section.editLink === null).toBe(true);
    }
  });

  test('container allowedTypes include label, icon, and description', async ({ request }) => {
    const fixture = await loadFixture(request, 'complex-page');
    const response = await request.get(`${API_BASE}/${fixture.pageId}/main`);
    const { nodes } = await response.json() as TreeApiResponse;

    for (const section of nodes) {
      if (!('containerType' in section) || section.containerType !== 'section') continue;
      const sectionNode = section as SectionNode;

      // Section's allowedTypes should have enriched info objects
      if (sectionNode.allowedTypes !== null) {
        for (const [className, info] of Object.entries(sectionNode.allowedTypes)) {
          expect(typeof info.label, `allowedTypes[${className}].label should be a string`).toBe('string');
          expect(typeof info.icon, `allowedTypes[${className}].icon should be a string`).toBe('string');
          expect(typeof info.description, `allowedTypes[${className}].description should be a string`).toBe('string');
        }
      }
    }
  });

  test('createContent rejects invalid className with 400', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'content-elements');

    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toHaveCount(0, { timeout: 15_000 });

    const securityId = await page.evaluate(() => (window as any).ss?.config?.SecurityID ?? '');
    expect(securityId).not.toBe('');

    const colId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\Column']['empty_col'];

    const response = await page.request.post('/admin/grid/api/createContent', {
      headers: {
        'Content-Type': 'application/json',
        'X-SecurityID': securityId,
      },
      data: {
        className: 'NonExistentClass',
        parentId: colId,
      },
    });

    expect(response.status()).toBe(400);
  });

  test('createContent accepts valid ContentElement className with 204', async ({ page }) => {
    const fixture = await loadFixture(page.request, 'content-elements');

    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toHaveCount(0, { timeout: 15_000 });

    const securityId = await page.evaluate(() => (window as any).ss?.config?.SecurityID ?? '');
    expect(securityId).not.toBe('');

    const colId = fixture.fixtureMap['WeDevelop\\Grid\\Model\\Column']['empty_col'];

    const response = await page.request.post('/admin/grid/api/createContent', {
      headers: {
        'Content-Type': 'application/json',
        'X-SecurityID': securityId,
      },
      data: {
        className: 'WeDevelop\\Grid\\Model\\ContentElement',
        parentId: colId,
      },
    });

    expect(response.status()).toBe(204);
  });
});

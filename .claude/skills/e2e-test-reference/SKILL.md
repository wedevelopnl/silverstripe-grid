---
name: e2e-test-reference
description: E2E testing reference for Playwright specs, fixture YAML, test selectors, drag helpers, and fixture post-actions. Use when creating new E2E test specs, generating fixture YAML files, or needing reference for test selectors, helpers, drag simulation, or fixture post-actions. Also use when asking "how do I write an E2E test", "what test IDs are available", or "how do fixtures work".
---

# E2E Test Reference

Implementation reference for writing Playwright E2E specs in this project. Covers spec structure, fixture system, available helpers, test selectors, and drag simulation.

## Spec Template

Create specs at `tests/E2E/specs/{feature-area}.spec.ts`:

```typescript
import { expect, test } from '@playwright/test';
import { loadFixture, resetFixtures } from '../helpers/fixtures';

test.describe('{Feature Area} — {User Story Summary}', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('{describes the full user journey}', async ({ page }) => {
    const fixture = await loadFixture(page.request, '{fixture-name}');
    await page.goto(`/admin/pages/edit/show/${fixture.pageId}`);
    await expect(page.getByTestId('grid-editor-loading')).toBeHidden({ timeout: 15_000 });

    await test.step('{first meaningful user action}', async () => {
      // interactions + assertions
    });

    await test.step('{second meaningful user action}', async () => {
      // interactions + assertions
    });

    await test.step('{verification: final state check}', async () => {
      // assertions proving the journey succeeded
    });
  });
});
```

### Spec conventions

- Use `test.step()` inside a single `test()` block — each step is a phase of the user journey, not an isolated test
- Use `page.request` (not the standalone `request` fixture) for fixture loading — it shares the browser's authenticated session cookies
- Always await `grid-editor-loading` hidden before interacting with the grid
- CMS editor URL: `/admin/pages/edit/show/${fixture.pageId}`
- Frontend URL: `fixture.pageUrl`
- Access element database IDs: `fixture.fixtureMap['WeDevelop\\Grid\\Model\\Column']['col1']`
- Set viewport for specs with tall hierarchies: `test.use({ viewport: { width: 1280, height: 1400 } })`

## Helpers

### `helpers/fixtures.ts` — Fixture Loading

```typescript
// Load a named fixture, returns { pageId, pageUrl, fixtureMap }
const fixture = await loadFixture(page.request, 'element-tree');

// Shorthand: load + navigate + wait for grid
const fixture = await loadAndNavigate(page, 'element-tree');

// Reset all E2E data (removes pages with e2e-* URL segments)
await resetFixtures(request);
```

The `fixtureMap` is typed `Record<className, Record<identifier, dbId>>` — use it to reference specific elements by their YAML fixture identifier.

### `helpers/drag.ts` — Drag Simulation

dnd-kit uses PointerSensor with an 8px activation threshold. The helpers simulate raw pointer events with timing delays for React reconciliation.

```typescript
// Full drag: activate → move to target → drop
await performDrag(page, sourceLocator, targetLocator);

// Granular control: activate drag, then position manually
const handle = await activateDragByTitle(page, 'Row A1');
// handle returns { x, y } center coordinates of the drag handle

// Start drag with explicit control over drop
const dragHandle = await startDrag(page, sourceLocator, targetLocator);
// ... intermediate assertions while dragging ...
await dropAndSettle(page, targetX, targetY);

// Wait for mutation settlement (reorder API + refetch + 500ms for dnd-kit rect re-registration)
const settle = waitForMutationSettlement(page);
await dropAndSettle(page, x, y);
await settle();
```

**Critical timing**: drag helpers include 150ms delays after activation and moves for React state batching + dnd-kit collision updates. The 500ms settlement pause after drop is necessary for TanStack Query cache update and dnd-kit droppable rect re-registration.

## Test Selectors

### Container structure

| Selector | Element |
|----------|---------|
| `getByTestId('grid-editor')` | Main editor wrapper (has `data-zone` attr) |
| `getByTestId('grid-editor-loading')` | Loading spinner |
| `getByTestId('section-block')` | Section container |
| `getByTestId('row-block')` | Row container |
| `getByTestId('column-block')` | Column container |
| `getByTestId('section-header')` | Section header wrapper |
| `getByTestId('row-header')` | Row header wrapper |
| `getByTestId('column-header')` | Column header wrapper |

### Titles and content

| Selector | Element |
|----------|---------|
| `getByTestId('section-title')` | Section title text |
| `getByTestId('row-title')` | Row title text |
| `getByTestId('column-title')` | Column title text |
| `getByTestId('element-card')` | Content element card |
| `getByTestId('element-card-title')` | Content element title |
| `getByTestId('column-badge')` | Column width badge |
| `getByTestId('column-offset-badge')` | Column offset badge |
| `getByTestId('column-badge-listbox')` | Width picker dropdown |
| `getByTestId('column-offset-badge-listbox')` | Offset picker dropdown |

### Controls

| Selector | Element |
|----------|---------|
| `getByTestId('drag-handle')` | Drag handle (has `aria-label="Move {title}"`) |
| `getByTestId('drag-handle-icon')` | Icon within drag handle |
| `getByTestId('collapse-toggle')` | Expand/collapse (has `aria-expanded`) |
| `getByTestId('actions-menu-trigger')` | Three-dot actions menu |
| `getByTestId('add-child-button')` | Add child element |
| `getByTestId('add-child-empty')` | Add child (empty state) |
| `getByTestId('add-child-append')` | Add child (append) |
| `getByTestId('add-content-button')` | Add content element |
| `getByTestId('element-type-picker')` | Element type selection modal |
| `getByTestId('element-type-picker-close')` | Modal close button |
| `getByTestId('element-type-tile')` | Clickable element type option |

### Edit links

| Selector | Element |
|----------|---------|
| `getByTestId('section-edit-link')` | Section edit navigation |
| `getByTestId('row-edit-link')` | Row edit navigation |
| `getByTestId('column-edit-link')` | Column edit navigation |

### Drag overlay

| Selector | Element |
|----------|---------|
| `getByTestId('drag-overlay-element')` | Element drag overlay |
| `getByTestId('drag-overlay-row')` | Row drag overlay |
| `getByTestId('drag-overlay-column')` | Column drag overlay |
| `getByTestId('drag-overlay-element-title')` | Title in drag overlay |

### Viewport & dialogs

| Selector | Element |
|----------|---------|
| `getByTestId('viewport-switcher')` | Viewport switching UI |
| `getByTestId('viewport-button')` | Viewport button (has `aria-pressed`) |
| `getByTestId('confirm-dialog')` | Archive/delete confirmation (has `[open]`) |

### ARIA-based locators

```typescript
// Viewport switcher group
page.getByRole('group', { name: 'Viewport size' });

// Specific viewport button
page.getByRole('button', { name: 'Medium', exact: true });

// Drag handle by element title
page.locator('[data-testid="drag-handle"][aria-label="Move Row A1"]');
```

### Scoping locators to containers

Filter by text content to target specific elements in the hierarchy:

```typescript
const section = page.getByTestId('section-block').filter({ hasText: 'Main Section' });
const row = section.getByTestId('row-block').filter({ hasText: 'Row A1' });
const column = row.getByTestId('column-block').first();
```

## Fixture System

### Existing fixtures

Check `_config/dev.yml` for the current fixture list — it is the source of truth. At time of writing:

| Name | Description |
|------|-------------|
| `element-tree` | Full hierarchy: page → sections → rows → columns → content elements (published) |
| `empty-page` | Page with no grid content |
| `collapse-test` | Hierarchy with specific collapse state |
| `complex-page` | Mixed versioned states: published, draft-only, modified |
| `multi-zone` | MultiZonePage with `main` and `sidebar` zones |
| `media-elements` | Content with media/image elements |
| `grid-settings` | Columns with various GridSettings configurations |

### Creating a new fixture

#### 1. Write the YAML file

Create at `tests/E2E/Fixture/{Name}.yml`. Use top-down ordering — parents before children — so `=>ClassName.identifier` references resolve. Auto-scaffolding is suppressed during fixture loading (the loader sets `auto_scaffold: false`), so no duplicate children are created.

```yaml
Page:
  e2e_page:
    Title: 'E2E Feature Test Page'
    URLSegment: 'e2e-feature-test'

WeDevelop\Grid\Model\Section:
  section1:
    Title: 'Main Section'
    Sort: 1
    Zone: main
    Parent: =>Page.e2e_page

WeDevelop\Grid\Model\Row:
  row1:
    Title: 'First Row'
    Sort: 1
    Parent: =>WeDevelop\Grid\Model\Section.section1

WeDevelop\Grid\Model\Column:
  col1:
    Title: 'Left Column'
    Sort: 1
    Parent: =>WeDevelop\Grid\Model\Row.row1
    GridSettings: '{"md":{"width":8,"offset":0,"visible":true}}'

WeDevelop\Grid\Model\ContentElement:
  leaf1:
    Title: 'Text Block'
    Sort: 1
    Parent: =>WeDevelop\Grid\Model\Column.col1
```

**Conventions:**
- Page URLSegments must start with `e2e-` (the reset endpoint removes pages matching this prefix)
- Use fully qualified class names for model references
- Polymorphic parent: `Parent: =>ClassName.identifier` (the fixture system resolves `ParentID` + `ParentClass`)
- Sections carry a `Zone` field (e.g., `main`, `sidebar`)
- GridSettings uses sparse JSON — only store viewport overrides, not all viewports

#### 2. Register the fixture

Add to `_config/dev.yml`:

```yaml
WeDevelop\Grid\Dev\FixtureLoader:
  fixtures:
    feature-test:
      path: 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/FeatureTest.yml'
```

With post-actions for versioned state setup:

```yaml
    feature-test:
      path: 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/FeatureTest.yml'
      post_actions:
        - action: publish_recursive
          class: Page
          identifier: e2e_page
        - action: unpublish
          class: 'WeDevelop\Grid\Model\ContentElement'
          identifier: draft_leaf
        - action: modify
          class: 'WeDevelop\Grid\Model\ContentElement'
          identifier: modified_leaf
          fields:
            Title: 'Modified Text Block (draft)'
        - action: attach_image
          class: 'WeDevelop\Grid\Model\ContentElement'
          identifier: media_leaf
          relation: Image
          path: 'tests/E2E/Fixture/images/sample.jpg'
```

### Post-actions

| Action | Purpose | Result |
|--------|---------|--------|
| `publish_recursive` | Publishes record + all owned descendants | Record visible on Live stage |
| `unpublish` | Removes from Live (cascades via `$owns`) | Draft-only state (`addedtodraft` flag) |
| `modify` | Updates fields and re-saves | Modified draft state (`modified` flag) |
| `attach_image` | Loads image, publishes it, links via has_one | Image attached to element |

Order matters — `publish_recursive` first, then `unpublish`/`modify` to create mixed versioned states.

## Multi-Zone Testing

The `MultiZonePage` page type (dev-only) provides two zones: `main` and `sidebar`. Each zone renders its own `GridEditorField`.

```typescript
// Target a specific zone's editor
const mainEditor = page.getByTestId('grid-editor').filter({ has: page.locator('[data-zone="main"]') });
const sidebarEditor = page.getByTestId('grid-editor').filter({ has: page.locator('[data-zone="sidebar"]') });
```

## Authentication

Global setup (`tests/E2E/global.setup.ts`) authenticates as `admin`/`admin` and stores session in `tests/E2E/.auth/admin.json`. All specs inherit this via Playwright's `storageState` — no per-spec login needed.

## Execution Model

- `workers: 1`, `fullyParallel: false` — specs run serially
- `retries: 1` on first failure with trace capture
- Self-signed certs: `ignoreHTTPSErrors: true`
- Base URL from `E2E_BASE_URL` env var or parsed from `.docker/.env`
- Run specific spec: `npx playwright test specs/{file}`
- Run full suite: `task test-e2e`

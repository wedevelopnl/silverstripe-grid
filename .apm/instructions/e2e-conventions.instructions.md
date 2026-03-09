---
description: E2E test philosophy, Playwright patterns, and custom fixture loading system
applyTo: "**/*"
---

# E2E Test Conventions

## Test Philosophy

E2E tests validate **complete user flows**, not individual operations. Each spec describes a realistic user journey that exercises multiple units working together in a real browser+Docker environment.

- **E2E tests answer**: "Does this user story actually work end-to-end?"
- **E2E tests do NOT answer**: "Does this button click produce this API call?" — that's a functional test disguised as E2E, carrying all the cost (browser, Docker, fixtures) with none of the integration coverage benefit.

**Coverage boundaries**: Unit tests cover individual operations. Integration tests cover service coordination. E2E tests prove the assembled system delivers the user story.

### Anti-Pattern: One-Operation-Per-Spec

Do NOT write specs like:
- "should add element" → assert element appears
- "should delete row" → assert element gone
- "should reorder" → assert new order

These are expensive functional tests. Instead, write multi-step user journeys:
- "Content editor builds a page section with rows and columns, reorders elements, and publishes" — one spec covering the full authoring flow.

Aim for **3-5 meaningful user journey specs** per feature area, not 15-20 narrow operation tests.

## Fixture System

The project uses a custom HTTP-based fixture system, not Playwright's built-in fixtures.

### Architecture

- `FixtureController` — HTTP endpoints at `/dev/grid-fixtures/{load,reset}`, gated to dev environment only
- `FixtureLoader` — Loads YAML fixture files via SilverStripe's `FixtureFactory`, applies post-actions
- `FixturePostAction` — Post-write operations: `publish_recursive`, `unpublish`, `modify` (field updates)
- `FixtureResult` — JSON response with `pageId`, `pageUrl`, `fixtureMap`

### Loading Fixtures in Specs

Use `loadAndNavigate` for the common pattern of loading a fixture and navigating to the CMS editor:

```typescript
import { resetFixtures, loadAndNavigate } from '../helpers/fixtures';

test.describe('Feature area', () => {
  test.afterAll(async ({ request }) => {
    await resetFixtures(request);
  });

  test('user journey description', async ({ page }) => {
    await loadAndNavigate(page, 'fixture-name');
    // Grid editor is loaded and ready — start asserting
  });
});
```

Use `loadFixture` directly only when you need the fixture data (e.g., `fixture.pageId`, `fixture.fixtureMap`) without navigating, or when using `request` without a `page` (e.g., API-only tests):

```typescript
const fixture = await loadFixture(page.request, 'fixture-name');
const response = await request.get(`/admin/grid/api/readTree/${fixture.pageId}/main`);
```

**Important**: Use `page.request` (not the standalone `request` fixture) when a `page` object is available — this shares browser cookies and avoids `strict_user_agent_check` session invalidation.

### Fixture YAML Conventions

- All pages must use `e2e-` as the URLSegment prefix (this is how `reset()` identifies E2E data)
- Order elements **bottom-up**: leaf elements before columns, columns before rows, rows before sections, sections before the page. This prevents `onAfterWrite` auto-scaffolding from creating duplicate children
- `GridSettings` is stored as a JSON string on `Column`
- Register fixtures in `_config/dev.yml` under `FixtureLoader.fixtures`

### Post-Actions

Post-actions run after YAML write, still in DRAFT stage:
- `publish_recursive` — calls `publishRecursive()` on the record
- `unpublish` — calls `doUnpublish()` on the record
- `modify` — sets specific fields and writes (creates draft-modified state)

### Available Fixtures

Registered in `_config/dev.yml`: `element-tree`, `empty-page`, `collapse-test`, `drag-and-drop`, `multi-zone`, `complex-page`, `ghost-jump`, `cross-container-ghost`, `cross-section-drop`, `cross-section-drop-single`, `cross-row-column-drop`, `cross-row-column-drop-single`, `cross-column-element-drop`, `cross-column-element-drop-single`

## Locator Strategy

E2E specs test **what is rendered**, not implementation details. Locators must be resilient to theme and markup changes.

### Preferred: `getByTestId`

Use `data-testid` attributes as the primary locator strategy. These are stable, intentional contracts between the component and the test:

```typescript
page.getByTestId('section-block')
page.getByTestId('column-badge')
page.getByTestId('viewport-button')
```

### Acceptable: Accessible Roles and Labels

Use `getByRole`, `getByLabel`, `getByText` when testing from the user's perspective:

```typescript
page.getByRole('button', { name: 'Medium', exact: true })
page.getByRole('group', { name: 'Viewport size' })
```

### Anti-Pattern: CSS Class and ID Selectors

**Do NOT use CSS class selectors** (`.row-block`, `.col-md-6`) or DOM IDs (`#Form_EditForm_Title`) unless absolutely necessary. These tie the test to the theme/CSS layer, making specs extremely brittle — a CSS refactor or theme change breaks every test that uses class selectors.

```typescript
// Wrong — brittle, tied to CSS implementation
page.locator('.row-block')
page.locator('#Form_EditForm_Title')

// Correct — stable, tests what is rendered
page.getByTestId('row-block')
page.getByRole('textbox', { name: 'Title' })
```

If a `data-testid` doesn't exist for an element you need to locate, **add one to the component** rather than reaching for a class selector.

## Drag & Drop Helpers

Shared helpers in `tests/E2E/helpers/drag.ts` for simulating dnd-kit pointer-based drags:

- **`activateDragByTitle(page, title, options?)`** — Locates drag handle by `aria-label="Move {title}"`, presses mouse down, moves 10px on axis to exceed 8px PointerSensor threshold. Options: `{ axis?: 'vertical' | 'horizontal', overlayTestId?: string }`. Returns `{ x, y }` of handle center.
- **`dropAndSettle(page, x, y)`** — Moves mouse to target coordinates, releases, and awaits mutation settlement (PATCH + refetch + 500ms React reconciliation).
- **`waitForMutationSettlement(page)`** — Registers response listeners for `/api/reorder` and `/api/readTree/`. Must be called BEFORE the action that triggers the mutation. Returns async settle function.

### DnD Journey Test Pattern

Cross-container drop specs use a journey pattern: 6 sequential drag operations in a single fixture load, then reload to verify persistence. Use `test.step()` for debuggability. Prefix each step with a schematic comment showing the before→after state:

```typescript
// Col A [A2, A3]           →  Col A [A2, A3]
// Col B [B1, B2, B3]       →  Col B [*A1*, B1, B2, B3]
await test.step('Forward, before-first: A1 → Col B before B1', async () => {
  await activateDragByTitle(page, 'Element A1', { overlayTestId: 'drag-overlay-element' });
  await enterColumn(page, colB, 4);
  const pos = await elPosition(colB, { before: 'Element B1' });
  await dropAndSettle(page, pos.x, pos.y);
  await expect(colB.getByTestId('element-card-title')).toHaveText([...]);
});
```

Keep hierarchy-specific helpers (container locators, enter functions, position calculators) in the spec file. Share only generic helpers via `tests/E2E/helpers/drag.ts`.

## Playwright Patterns

- **Serial execution**: `fullyParallel: false`, `workers: 1` — tests share database state
- **Auth**: Global setup authenticates as `admin`/`admin`, stores state in `tests/E2E/.auth/admin.json`
- **Base URL**: Resolved from `E2E_BASE_URL` env var or `WEB_PORT` in `.docker/.env`
- **Wait for grid**: Always wait for `getByTestId('grid-editor-loading')` to be hidden (15s timeout) before asserting grid content
- **Navigate to editor**: `page.goto(\`/admin/pages/edit/show/${fixture.pageId}\`)`
- **Fixture map**: Access secondary page/element IDs via `fixture.fixtureMap['ClassName']['identifier']`

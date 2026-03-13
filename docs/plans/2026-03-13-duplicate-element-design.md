# Duplicate Element Feature — Design

## Overview

Enable editors to duplicate any grid element (Section, Row, Column, content element) including its full subtree. Duplication can target the same container, a different zone on the same page, or a completely different page.

## UX Flow

### Context menu actions

Each element's action menu provides two entries:

- **"Duplicate"** — Immediate, no dialog. Shallow copy placed after the original in the same container. Uses existing `POST /api/duplicate`.
- **"Duplicate to..."** — Opens a multi-step dialog for cross-container/cross-page duplication.

### "Duplicate to..." dialog

A modal with up to 3 adaptive steps:

1. **Pick target page** — Searchable flat list of pages. Current page pre-selected. Pages without `GridEditorField` zones are visible but disabled (unselectable).
2. **Pick target zone** — Only shown if the target page has multiple zones. Auto-skipped for single-zone pages.
3. **Pick target container** — Flat list of acceptable containers (from `GET /api/acceptableContainers`). Skipped for Sections (they go directly into the zone). Shows container title and type. Informative message if no compatible containers exist.

Confirm triggers `POST /api/duplicateTo`. Errors display inline in the dialog so the user can pick a different target without restarting.

## Backend API

### Existing: `POST /api/duplicate` (unchanged)

Same-container shallow copy. Powers "Duplicate here".

### New: `POST /api/duplicateTo`

**Request body:**
```json
{
  "id": 42,
  "targetPageId": 7,
  "targetZone": "main",
  "targetParentId": 15
}
```

**`targetParentId` semantics:**
- For Row/Column/content element: the ID of the chosen container
- For Section: the page ID (Sections are root elements in a zone)

**Behavior:**
1. Load source element, check `canCreate()`
2. Load target parent by `targetParentId`, verify it exists on `targetPageId` in `targetZone`, verify `canEdit()`
3. Validate hierarchy: is the source element type allowed as child of target parent? (reuse `ElementAllowanceTrait`)
4. Deep-duplicate via `DataObject::duplicate(true)` — follows `cascade_duplicates` for full subtree
5. Re-parent clone to target parent, set Sort to append at end
6. Generate copy title via `TitleGenerator` (top-level element only, children keep original titles)
7. Return 204 on success, 422 on validation failure, 403 on permission denial

### New: `GET /api/acceptableContainers/$PageID/$Zone/$ElementType`

Returns containers in the target zone that accept the given element type.

**Response:**
```json
[
  { "id": 15, "title": "Hero Section", "type": "section" },
  { "id": 23, "title": "Content Section", "type": "section" }
]
```

Filtering logic: walk one level up from the source element's type using `ContainerType` hierarchy rules to determine which container type to list.

### New: `GET /api/zones/$PageID`

Inspects the page's CMS fields, finds all `GridEditorField` instances, returns their zone names.

**Response:**
```json
["main", "sidebar"]
```

### New: `GET /api/pages`

Returns a flat, searchable list of pages the user can edit.

**Query parameters:** search/filter string, pagination.

**Response:**
```json
[
  { "id": 1, "title": "Home", "parentId": 0, "hasGridZones": true },
  { "id": 5, "title": "About", "parentId": 1, "hasGridZones": false }
]
```

Pages with `hasGridZones: false` are visible for orientation but disabled in the picker.

## Deep Copy Implementation

### `duplicate(true)` path

SilverStripe's `DataObject::duplicate(true)` follows `cascade_duplicates`:
- Section: `cascade_duplicates: ['Rows']`
- Row: `cascade_duplicates: ['Columns']`
- Column: `cascade_duplicates: ['Elements']`

A single `$section->duplicate(true)` produces the full Section → Row → Column → content element tree.

### Key behaviors

- **Auto-scaffolding safety:** `onAfterWrite` guards check `$this->getChildren()->count() > 0`. Since `duplicate(true)` writes children before triggering the parent's `onAfterWrite`, the guard should prevent duplicate scaffolding. Must be verified with integration tests.
- **Title generation:** Only the top-level element gets a "copy" title. Children retain original titles.
- **GridSettings:** Column sparse JSON transfers naturally via field copy.
- **Child sort order:** Cloned children retain their relative Sort values, preserving internal ordering.
- **Published state:** `duplicate()` always produces draft records. Editor must explicitly publish.

## Frontend Implementation

### New API endpoints (`client/src/api/endpoints.ts`)

- `duplicateToElement(id, targetPageId, targetZone, targetParentId)` — `POST /api/duplicateTo`
- `fetchAcceptableContainers(pageId, zone, elementType)` — `GET /api/acceptableContainers`
- `fetchZones(pageId)` — `GET /api/zones`
- `fetchPages(search?)` — `GET /api/pages`

### New mutation hook

- `useDuplicateToElement(pageId, zone)` — wraps `duplicateToElement`, invalidates tree query on success

### New query hooks

- `useAcceptableContainers(pageId, zone, elementType)` — fetches container list for the picker
- `useZones(pageId)` — fetches zones, enabled only when a page is selected
- `usePages(search?)` — fetches page list with debounced search

### Dialog component

- `DuplicateToDialog` — Multi-step modal with page → zone → container flow
- Adaptive: skips zone step for single-zone pages, skips container step for Sections
- Error messages display inline without closing the dialog

### Context menu integration

Two entries added to the element action menu:
- "Duplicate" — calls existing `useDuplicateElement` mutation directly
- "Duplicate to..." — opens `DuplicateToDialog`

## Testing Strategy

### PHP Unit tests

- Deep copy with `duplicate(true)`: verify full subtree cloned, auto-scaffolding guards hold

### PHP Integration tests

- `apiDuplicateTo` — same page/same zone: Section appended with full subtree
- `apiDuplicateTo` — same page/different zone: Row into different zone's Section
- `apiDuplicateTo` — different page: Section to another page's zone
- `apiDuplicateTo` — hierarchy violation: Row into Column (422)
- `apiDuplicateTo` — permission denied: target parent not editable (403)
- `apiDuplicateTo` — invalid target: nonexistent targetParentId (404)
- `apiAcceptableContainers` — correct containers for each element type
- `apiZones` — zones extracted from CMS fields
- `apiPages` — permission filtering, `hasGridZones` flag

### Frontend tests (Vitest)

- Dialog step progression, skip logic for single zone and Section-level duplication
- `duplicateToElement` mutation payload construction
- `useAcceptableContainers` response parsing and empty state
- Inline error rendering

### E2E tests

- Journey: Duplicate Section within same zone via "Duplicate here"
- Journey: Duplicate Section to different page via "Duplicate to..." dialog
- Journey: Duplicate content element to different container on same page

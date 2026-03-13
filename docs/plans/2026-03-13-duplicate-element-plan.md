# Duplicate Element — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable editors to duplicate any grid element (with full subtree) into the same container, a different zone, or a different page via a multi-step dialog.

**Architecture:** Three new GET endpoints (pages, zones, acceptableContainers) feed a multi-step React dialog. One new POST endpoint (duplicateTo) performs deep duplication with re-parenting. The existing `POST /api/duplicate` remains unchanged for same-container "Duplicate here". All four components (SectionBlock, RowBlock, ColumnBlock, ElementCard) gain two new action menu entries.

**Tech Stack:** PHP 8.3 / SilverStripe 6 (backend), React 18 / TypeScript 5.9 / TanStack Query / Zod (frontend), PHPUnit 11 (integration tests), Vitest + RTL (frontend tests)

**Design doc:** `docs/plans/2026-03-13-duplicate-element-design.md`

---

## Phase 1: Backend — Request DTO and Body Parser

### Task 1: `DuplicateToRequest` value object

**Files:**
- Create: `src/Value/DuplicateToRequest.php`

**Step 1: Write the value object**

```php
<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class DuplicateToRequest
{
    /**
     * @param positive-int $id
     * @param positive-int $targetPageId
     * @param non-empty-string $targetZone
     * @param positive-int $targetParentId
     */
    public function __construct(
        public int $id,
        public int $targetPageId,
        public string $targetZone,
        public int $targetParentId,
    ) {
    }
}
```

**Step 2: Commit**

```
feat: add DuplicateToRequest value object
```

### Task 2: `parseDuplicateToBody` in RequestBodyParser

**Files:**
- Modify: `src/Service/RequestBodyParser.php`
- Test: `tests/Integration/Controllers/GridControllerTest.php` (add validation tests later in Task 6)

**Step 1: Write the failing test**

Add to `GridControllerTest.php` after the existing duplicate tests (~line 324):

```php
// --- apiDuplicateTo: validation -------------------------------------------

public function testDuplicateToReturns400WhenIdMissing(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $response = $this->postJson('/admin/grid/api/duplicateTo', [
        'targetPageId' => 1,
        'targetZone' => 'main',
        'targetParentId' => 1,
    ]);

    $this->assertJsonError(400, 'id must be a positive integer.', $response);
}
```

**Step 2: Run test — expect failure** (endpoint doesn't exist yet, should 404)

Run: `make test-integration` with filter `testDuplicateToReturns400WhenIdMissing`

**Step 3: Add parser method to `RequestBodyParser`**

Add to `src/Service/RequestBodyParser.php` before the `fail()` method:

```php
/**
 * @param array<string, mixed> $data
 * @return Result<DuplicateToRequest>
 */
public function parseDuplicateToBody(array $data): Result
{
    $id = $data['id'] ?? null;
    $targetPageId = $data['targetPageId'] ?? null;
    $targetZone = $data['targetZone'] ?? null;
    $targetParentId = $data['targetParentId'] ?? null;

    if (!is_int($id) || $id < 1) {
        return $this->fail('id must be a positive integer.');
    }

    if (!is_int($targetPageId) || $targetPageId < 1) {
        return $this->fail('targetPageId must be a positive integer.');
    }

    if (!is_string($targetZone) || $targetZone === '') {
        return $this->fail('targetZone must be a non-empty string.');
    }

    if (!is_int($targetParentId) || $targetParentId < 1) {
        return $this->fail('targetParentId must be a positive integer.');
    }

    /** @var non-empty-string $targetZone Narrowed by === '' guard */
    return Result::ok(new DuplicateToRequest($id, $targetPageId, $targetZone, $targetParentId));
}
```

Add `use WeDevelop\Grid\Value\DuplicateToRequest;` to the imports.

**Step 4: Commit**

```
feat: add parseDuplicateToBody to RequestBodyParser
```

---

## Phase 2: Backend — `apiDuplicateTo` Endpoint

### Task 3: `persistDuplicateAppend` in ElementPersistenceService

The existing `persistDuplicate` inserts after a reference element. The cross-target duplicate needs to append at the end of a container.

**Files:**
- Modify: `src/Service/ElementPersistenceService.php`

**Step 1: Write the failing test**

Add an integration test in `GridControllerTest.php` (will be called via the endpoint in Task 4):

```php
public function testDuplicateToAppendsSectionAtEndOfTargetZone(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $sourcePage = $this->objFromFixture(TestPage::class, 'testpage');
    $section = $this->objFromFixture(Section::class, 'section1');

    // Create a second page as the target
    $targetPage = TestPage::create();
    $targetPage->Title = 'Target Page';
    $targetPage->write();

    // Create an existing section in the target page
    $existingSection = Section::create();
    $existingSection->Title = 'Existing Section';
    $existingSection->ParentID = $targetPage->ID;
    $existingSection->ParentClass = $targetPage::class;
    $existingSection->Zone = 'main';
    $existingSection->Sort = 1;
    $existingSection->write();

    $response = $this->postJson('/admin/grid/api/duplicateTo', [
        'id' => $section->ID,
        'targetPageId' => $targetPage->ID,
        'targetZone' => 'main',
        'targetParentId' => $targetPage->ID,
    ]);

    $this->assertSame(204, $response->getStatusCode());

    // Clone should be the newest section
    $clone = Section::get()->sort('ID', 'DESC')->first();
    $this->assertSame('First Section copy', $clone->Title);
    $this->assertSame((int) $targetPage->ID, (int) $clone->ParentID);
    $this->assertSame('main', $clone->Zone);

    // Clone sort should be after the existing section
    $this->assertGreaterThan((int) $existingSection->Sort, (int) $clone->Sort);
}
```

**Step 2: Run test — expect failure** (endpoint doesn't exist)

**Step 3: Add `persistAppend` method**

Add to `src/Service/ElementPersistenceService.php`:

```php
/**
 * Persist a duplicated element at the end of its parent's children.
 *
 * @return Result<GridElement>
 */
public function persistAppend(GridElement $element): Result
{
    try {
        $element->ensureSortSet();
        $element->write();
    } catch (ValidationException $validationException) {
        return Result::fail(...$this->translateValidationException($validationException));
    }

    return Result::ok($element);
}
```

**Step 4: Commit**

```
feat: add persistAppend to ElementPersistenceService
```

### Task 4: `apiDuplicateTo` controller method

**Files:**
- Modify: `src/Controllers/GridController.php`

**Step 1: Register the endpoint**

Add to `$url_handlers` (after line 81):
```php
'POST api/duplicateTo' => 'apiDuplicateTo',
```

Add to `$allowed_actions` (after line 94):
```php
'apiDuplicateTo',
```

**Step 2: Implement the handler**

Add after `apiDuplicate` (~line 311):

```php
public function apiDuplicateTo(HTTPRequest $request): HTTPResponse
{
    $data = $this->parseJsonBody($request);
    $parseResult = $this->requestBodyParser->parseDuplicateToBody($data);
    if ($parseResult->isErr()) {
        return $this->resultToResponse($parseResult, 400);
    }

    $body = $parseResult->unwrap();

    $element = $this->requireElementWithPermission(
        $body->id,
        static fn (GridElement $e): bool => $e->canCreate(),
    );

    // Resolve the target parent — Section targets are pages, others are elements
    $isSectionDuplicate = $element instanceof Section
        || (method_exists($element, 'getContainerType') && $element->getContainerType() === ContainerType::Section);

    /** @var DataObject|null $targetParent */
    $targetParent = Versioned::withVersionedMode(static function () use ($body, $isSectionDuplicate): ?DataObject {
        Versioned::set_stage(Versioned::DRAFT);

        if ($isSectionDuplicate) {
            return SiteTree::get()->byID($body->targetParentId);
        }

        return GridElement::get()->byID($body->targetParentId);
    });

    if ($targetParent === null || !$targetParent->exists()) {
        $this->jsonError(404);
    }

    if (!$targetParent->canEdit()) {
        $this->jsonError(403);
    }

    // Deep-duplicate the entire subtree
    $clone = $element->duplicate(true);

    // Re-parent to target
    $clone->ParentID = $body->targetParentId;
    $clone->ParentClass = $targetParent::class;

    // Set zone for sections
    if ($clone instanceof Section) {
        $clone->Zone = $body->targetZone;
    }

    // Generate copy title (top-level only)
    /** @var non-empty-string $cloneTitle */
    $cloneTitle = $clone->Title ?: $element->Title ?: 'Untitled';
    $clone->Title = TitleGenerator::generateCopyTitle($cloneTitle);

    $clone->Sort = 0;

    $result = $this->persistenceService->persistAppend($clone);
    if ($result->isErr()) {
        return $this->resultToResponse($result);
    }

    return $this->jsonSuccess(204);
}
```

**Step 3: Run the tests from Task 3 Step 1 — expect pass**

Run: `make test-integration`

**Step 4: Commit**

```
feat: add apiDuplicateTo endpoint for cross-target deep duplication
```

### Task 5: Deep copy integration tests

Verify `duplicate(true)` produces full subtrees and auto-scaffolding guards hold.

**Files:**
- Modify: `tests/Integration/Controllers/GridControllerTest.php`

**Step 1: Write tests**

```php
public function testDuplicateToDeepCopiesSectionSubtree(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $section = $this->objFromFixture(Section::class, 'section1');
    $originalRowCount = $section->Rows()->count();
    $originalColumnCount = Row::get()->filter([
        'ParentID' => $section->Rows()->column('ID'),
        'ParentClass' => Row::class,
    ])->count();

    $targetPage = TestPage::create();
    $targetPage->Title = 'Deep Copy Target';
    $targetPage->write();

    $response = $this->postJson('/admin/grid/api/duplicateTo', [
        'id' => $section->ID,
        'targetPageId' => $targetPage->ID,
        'targetZone' => 'main',
        'targetParentId' => $targetPage->ID,
    ]);

    $this->assertSame(204, $response->getStatusCode());

    $clone = Section::get()->sort('ID', 'DESC')->first();
    $this->assertSame($originalRowCount, $clone->Rows()->count());
}

public function testDuplicateToReturns403WhenTargetNotEditable(): void
{
    // Log in with view-only permission
    $this->logInForHttp('CMS_ACCESS_LeftAndMain');
    Versioned::set_stage(Versioned::DRAFT);

    $section = $this->objFromFixture(Section::class, 'section1');

    // Target page that the user cannot edit
    $targetPage = TestPage::create();
    $targetPage->Title = 'Protected Page';
    $targetPage->CanEditType = 'OnlyTheseUsers';
    $targetPage->write();

    $response = $this->postJson('/admin/grid/api/duplicateTo', [
        'id' => $section->ID,
        'targetPageId' => $targetPage->ID,
        'targetZone' => 'main',
        'targetParentId' => $targetPage->ID,
    ]);

    $this->assertSame(403, $response->getStatusCode());
}

public function testDuplicateToReturns422ForHierarchyViolation(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $row = $this->objFromFixture(Row::class, 'row1');
    $column = $this->objFromFixture(Column::class, 'column1');

    // Try to duplicate a row INTO a column (hierarchy violation)
    $page = $this->objFromFixture(TestPage::class, 'testpage');

    $response = $this->postJson('/admin/grid/api/duplicateTo', [
        'id' => $row->ID,
        'targetPageId' => $page->ID,
        'targetZone' => 'main',
        'targetParentId' => $column->ID,
    ]);

    // Should fail hierarchy validation on write
    $this->assertSame(422, $response->getStatusCode());
}

public function testDuplicateToChildrenRetainOriginalTitles(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $section = $this->objFromFixture(Section::class, 'section1');
    $originalRow = $section->Rows()->first();
    $originalRowTitle = $originalRow->Title;

    $targetPage = TestPage::create();
    $targetPage->Title = 'Title Test Target';
    $targetPage->write();

    $response = $this->postJson('/admin/grid/api/duplicateTo', [
        'id' => $section->ID,
        'targetPageId' => $targetPage->ID,
        'targetZone' => 'main',
        'targetParentId' => $targetPage->ID,
    ]);

    $this->assertSame(204, $response->getStatusCode());

    $clone = Section::get()->sort('ID', 'DESC')->first();
    $this->assertStringContainsString('copy', $clone->Title);

    $clonedRow = $clone->Rows()->first();
    $this->assertSame($originalRowTitle, $clonedRow->Title);
}
```

**Step 2: Run tests — expect pass**

Run: `make test-integration`

**Step 3: Commit**

```
test: add integration tests for apiDuplicateTo deep copy behavior
```

---

## Phase 3: Backend — Supporting Endpoints

### Task 6: `apiAcceptableContainers` endpoint

**Files:**
- Modify: `src/Controllers/GridController.php`

**Step 1: Write the failing test**

```php
public function testAcceptableContainersReturnsCorrectContainersForRow(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $page = $this->objFromFixture(TestPage::class, 'testpage');

    $response = $this->get('/admin/grid/api/acceptableContainers/' . $page->ID . '/main/row');

    $this->assertSame(200, $response->getStatusCode());

    $body = json_decode($response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

    // Should return sections (rows go into sections)
    $this->assertNotEmpty($body);
    foreach ($body as $container) {
        $this->assertSame('section', $container['type']);
        $this->assertArrayHasKey('id', $container);
        $this->assertArrayHasKey('title', $container);
    }
}

public function testAcceptableContainersReturnsEmptyForSectionType(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $page = $this->objFromFixture(TestPage::class, 'testpage');

    $response = $this->get('/admin/grid/api/acceptableContainers/' . $page->ID . '/main/section');

    $this->assertSame(200, $response->getStatusCode());

    $body = json_decode($response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

    // Sections are root-level, no container needed
    $this->assertSame([], $body);
}
```

**Step 2: Run test — expect failure**

**Step 3: Implement the endpoint**

Add to `$url_handlers`:
```php
'GET api/acceptableContainers/$PageID!/$Zone!/$ElementType!' => 'apiAcceptableContainers',
```

Add to `$allowed_actions`:
```php
'apiAcceptableContainers',
```

Add handler method:

```php
public function apiAcceptableContainers(HTTPRequest $request): HTTPResponse
{
    $pageId = (int) $request->param('PageID');
    /** @var non-empty-string $zone */
    $zone = (string) $request->param('Zone');
    $elementType = (string) $request->param('ElementType');

    /** @var SiteTree|null $page */
    $page = Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
        Versioned::set_stage(Versioned::DRAFT);
        return SiteTree::get()->byID($pageId);
    });

    if ($page === null) {
        $this->jsonError(404);
    }

    if (!$page->canView()) {
        $this->jsonError(403);
    }

    // Determine what container type holds this element type
    $parentContainerType = match ($elementType) {
        'row' => ContainerType::Section,
        'column' => ContainerType::Row,
        'element' => ContainerType::Column,
        'section' => null, // Sections go directly into pages
        default => null,
    };

    if ($parentContainerType === null) {
        return $this->jsonSuccess(200, []);
    }

    // Load the tree and collect containers of the right type
    $tree = $this->treeBuilder->buildForPage($page, $zone);
    $containers = [];
    $this->collectContainers($tree, $parentContainerType, $containers);

    return $this->jsonSuccess(200, $containers);
}

/**
 * Recursively collect containers of the specified type from the tree.
 *
 * @param array<int, list<\WeDevelop\Grid\Value\GridNode>>|list<\WeDevelop\Grid\Value\GridNode> $nodes
 * @param list<array{id: positive-int, title: string, type: string}> $containers
 */
private function collectContainers(array $nodes, ContainerType $targetType, array &$containers): void
{
    foreach ($nodes as $nodeOrList) {
        if (is_array($nodeOrList) && !isset($nodeOrList['id'])) {
            // Top-level keyed by page ID
            $this->collectContainers($nodeOrList, $targetType, $containers);
            continue;
        }

        /** @var \WeDevelop\Grid\Value\GridNode $node */
        $node = $nodeOrList;

        if ($node->containerType === $targetType) {
            $containers[] = [
                'id' => $node->id,
                'title' => $node->title,
                'type' => $targetType->value,
            ];
        }

        if ($node->children !== null) {
            $this->collectContainers($node->children, $targetType, $containers);
        }
    }
}
```

Note: The `collectContainers` helper traverses `GridNode` objects from the tree builder. The exact recursion pattern depends on the `buildForPage` return shape — it returns `array<int, list<GridNode>>` (keyed by page ID). Adjust the traversal if the actual shape differs. The implementer should read `GridTreeBuilder::buildForPage()` to confirm the exact return type and adapt accordingly.

**Step 4: Run tests — expect pass**

**Step 5: Commit**

```
feat: add apiAcceptableContainers endpoint
```

### Task 7: `apiZones` endpoint

**Files:**
- Modify: `src/Controllers/GridController.php`

**Step 1: Write the failing test**

```php
public function testZonesReturnsPageZones(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $page = $this->objFromFixture(TestPage::class, 'testpage');

    $response = $this->get('/admin/grid/api/zones/' . $page->ID);

    $this->assertSame(200, $response->getStatusCode());

    $body = json_decode($response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
    $this->assertContains('main', $body);
}

public function testZonesReturns404ForNonExistentPage(): void
{
    $this->logInForHttp();

    $response = $this->get('/admin/grid/api/zones/999999');

    $this->assertSame(404, $response->getStatusCode());
}
```

**Step 2: Run test — expect failure**

**Step 3: Implement**

Add to `$url_handlers`:
```php
'GET api/zones/$PageID!' => 'apiZones',
```

Add to `$allowed_actions`:
```php
'apiZones',
```

Add handler:

```php
public function apiZones(HTTPRequest $request): HTTPResponse
{
    $pageId = (int) $request->param('PageID');

    /** @var SiteTree|null $page */
    $page = Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
        Versioned::set_stage(Versioned::DRAFT);
        return SiteTree::get()->byID($pageId);
    });

    if ($page === null) {
        $this->jsonError(404);
    }

    if (!$page->canView()) {
        $this->jsonError(403);
    }

    // Extract zones from GridEditorField instances in the page's CMS fields
    $fields = $page->getCMSFields();
    $zones = [];

    foreach ($fields->flattenFields() as $field) {
        if ($field instanceof \WeDevelop\Grid\Forms\GridEditorField) {
            $zones[] = $field->getZone();
        }
    }

    return $this->jsonSuccess(200, array_values(array_unique($zones)));
}
```

**Step 4: Run tests — expect pass**

**Step 5: Commit**

```
feat: add apiZones endpoint for page zone discovery
```

### Task 8: `apiPages` endpoint

**Files:**
- Modify: `src/Controllers/GridController.php`

**Step 1: Write the failing test**

```php
public function testPagesReturnsEditablePages(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $response = $this->get('/admin/grid/api/pages');

    $this->assertSame(200, $response->getStatusCode());

    $body = json_decode($response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
    $this->assertIsArray($body);

    // At least the test page should be present
    $this->assertNotEmpty($body);

    $first = $body[0];
    $this->assertArrayHasKey('id', $first);
    $this->assertArrayHasKey('title', $first);
    $this->assertArrayHasKey('parentId', $first);
    $this->assertArrayHasKey('hasGridZones', $first);
}

public function testPagesSearchFiltersResults(): void
{
    $this->logInForHttp();
    Versioned::set_stage(Versioned::DRAFT);

    $page = $this->objFromFixture(TestPage::class, 'testpage');

    $response = $this->get('/admin/grid/api/pages?search=' . urlencode($page->Title));

    $this->assertSame(200, $response->getStatusCode());

    $body = json_decode($response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
    $this->assertNotEmpty($body);

    // All results should contain the search term
    foreach ($body as $result) {
        $this->assertStringContainsStringIgnoringCase($page->Title, $result['title']);
    }
}
```

**Step 2: Run test — expect failure**

**Step 3: Implement**

Add to `$url_handlers`:
```php
'GET api/pages' => 'apiPages',
```

Add to `$allowed_actions`:
```php
'apiPages',
```

Add handler:

```php
public function apiPages(HTTPRequest $request): HTTPResponse
{
    $search = trim((string) $request->getVar('search'));

    $pages = Versioned::withVersionedMode(static function () use ($search) {
        Versioned::set_stage(Versioned::DRAFT);

        $list = SiteTree::get()->sort('Title', 'ASC');

        if ($search !== '') {
            $list = $list->filter('Title:PartialMatch', $search);
        }

        return $list->limit(50);
    });

    $results = [];

    /** @var SiteTree $page */
    foreach ($pages as $page) {
        if (!$page->canEdit()) {
            continue;
        }

        // Check if the page has any GridEditorField in its CMS fields
        $hasGridZones = false;
        $fields = $page->getCMSFields();
        foreach ($fields->flattenFields() as $field) {
            if ($field instanceof \WeDevelop\Grid\Forms\GridEditorField) {
                $hasGridZones = true;
                break;
            }
        }

        $results[] = [
            'id' => (int) $page->ID,
            'title' => $page->Title ?: 'Untitled',
            'parentId' => (int) $page->ParentID,
            'hasGridZones' => $hasGridZones,
        ];
    }

    return $this->jsonSuccess(200, $results);
}
```

**Performance note:** `getCMSFields()` is expensive to call per page. If this becomes a bottleneck, consider caching or a lighter detection mechanism. For the initial implementation with a limit of 50 pages, it should be acceptable.

**Step 4: Run tests — expect pass**

**Step 5: Commit**

```
feat: add apiPages endpoint for searchable page list
```

---

## Phase 4: Frontend — API Client and Hooks

### Task 9: API client functions

**Files:**
- Modify: `client/src/api/endpoints.ts`

**Step 1: Add the new endpoint functions**

```typescript
// --- Duplicate To ---

export interface DuplicateToParams {
  id: number;
  targetPageId: number;
  targetZone: string;
  targetParentId: number;
}

export async function duplicateToElement(
  params: DuplicateToParams,
): Promise<void> {
  const base = getControllerLink();
  await apiPost(`${base}/api/duplicateTo`, params);
}

// --- Acceptable Containers ---

export interface AcceptableContainer {
  id: number;
  title: string;
  type: string;
}

export async function fetchAcceptableContainers(
  pageId: number,
  zone: string,
  elementType: string,
): Promise<AcceptableContainer[]> {
  const base = getControllerLink();
  return apiGet<AcceptableContainer[]>(
    `${base}/api/acceptableContainers/${pageId}/${encodeURIComponent(zone)}/${encodeURIComponent(elementType)}`,
  );
}

// --- Zones ---

export async function fetchZones(pageId: number): Promise<string[]> {
  const base = getControllerLink();
  return apiGet<string[]>(`${base}/api/zones/${pageId}`);
}

// --- Pages ---

export interface PageEntry {
  id: number;
  title: string;
  parentId: number;
  hasGridZones: boolean;
}

export async function fetchPages(search?: string): Promise<PageEntry[]> {
  const base = getControllerLink();
  const params = search ? `?search=${encodeURIComponent(search)}` : '';
  return apiGet<PageEntry[]>(`${base}/api/pages${params}`);
}
```

**Step 2: Commit**

```
feat: add frontend API client functions for duplicate-to endpoints
```

### Task 10: Query key extensions

**Files:**
- Modify: `client/src/hooks/queryKeys.ts`

**Step 1: Add query keys**

```typescript
export const queryKeys = {
  elementTree: {
    all: () => ['elementTree'] as const,
    byPage: (pageId: number, zone: string) => ['elementTree', pageId, zone] as const,
  },
  pages: {
    all: () => ['pages'] as const,
    search: (search: string) => ['pages', search] as const,
  },
  zones: {
    byPage: (pageId: number) => ['zones', pageId] as const,
  },
  acceptableContainers: {
    byTarget: (pageId: number, zone: string, elementType: string) =>
      ['acceptableContainers', pageId, zone, elementType] as const,
  },
} as const;
```

**Step 2: Commit**

```
feat: add query keys for pages, zones, and acceptable containers
```

### Task 11: Mutation and query hooks

**Files:**
- Modify: `client/src/hooks/useElementMutations.ts`
- Create: `client/src/hooks/useDuplicateToQueries.ts`

**Step 1: Add mutation hook**

In `useElementMutations.ts`, add after `useDuplicateElement`:

```typescript
export function useDuplicateToElement(pageId: number, zone: string) {
  return useMutation<void, ApiError, DuplicateToParams>({
    mutationFn: duplicateToElement,
    ...useInvalidateOnSuccess(pageId, zone),
  });
}
```

Add import: `import type { DuplicateToParams } from '@/api/endpoints';` and `import { duplicateToElement } from '@/api/endpoints';`

**Step 2: Create query hooks file**

```typescript
import { useQuery } from '@tanstack/react-query';
import {
  fetchPages,
  fetchZones,
  fetchAcceptableContainers,
} from '@/api/endpoints';
import type { PageEntry, AcceptableContainer } from '@/api/endpoints';
import { queryKeys } from './queryKeys';

export function usePages(search: string, enabled = true) {
  return useQuery<PageEntry[]>({
    queryKey: queryKeys.pages.search(search),
    queryFn: () => fetchPages(search || undefined),
    enabled,
  });
}

export function useZones(pageId: number | null) {
  return useQuery<string[]>({
    queryKey: queryKeys.zones.byPage(pageId ?? 0),
    queryFn: () => fetchZones(pageId!),
    enabled: pageId !== null,
  });
}

export function useAcceptableContainers(
  pageId: number | null,
  zone: string | null,
  elementType: string | null,
) {
  return useQuery<AcceptableContainer[]>({
    queryKey: queryKeys.acceptableContainers.byTarget(
      pageId ?? 0,
      zone ?? '',
      elementType ?? '',
    ),
    queryFn: () => fetchAcceptableContainers(pageId!, zone!, elementType!),
    enabled: pageId !== null && zone !== null && elementType !== null,
  });
}
```

**Step 3: Commit**

```
feat: add mutation and query hooks for duplicate-to feature
```

---

## Phase 5: Frontend — "Duplicate here" Action

### Task 12: `useDuplicateAction` hook

**Files:**
- Create: `client/src/hooks/useDuplicateAction.ts`

**Step 1: Write the failing test**

Create `client/src/tests/hooks/useDuplicateAction.test.ts`:

```typescript
import { describe, it, expect, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useDuplicateAction } from '@/hooks/useDuplicateAction';

// Mock dependencies
vi.mock('@/hooks/GridEditorContext', () => ({
  useGridEditorContext: () => ({ pageId: 1, zone: 'main' }),
}));

vi.mock('@/hooks/useElementMutations', () => ({
  useDuplicateElement: () => ({ mutate: vi.fn() }),
}));

describe('useDuplicateAction', () => {
  it('returns null action when canCreate is false', () => {
    const node = { id: 1, canCreate: false } as any;
    const { result } = renderHook(() => useDuplicateAction(node));
    expect(result.current.action).toBeNull();
  });

  it('returns action with correct key and label when canCreate is true', () => {
    const node = { id: 1, canCreate: true } as any;
    const { result } = renderHook(() => useDuplicateAction(node));
    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.key).toBe('duplicate');
    expect(result.current.action!.label).toBe('Duplicate');
    expect(result.current.action!.destructive).toBeUndefined();
  });
});
```

**Step 2: Run test — expect failure**

Run: `npm run test -- --run useDuplicateAction`

**Step 3: Implement the hook**

```typescript
import { useCallback } from 'react';
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu';
import type { ElementNode } from '@/types/elements';
import { useGridEditorContext } from './GridEditorContext';
import { useDuplicateElement } from './useElementMutations';
import { showToast } from '@/utils/toast';

interface UseDuplicateActionResult {
  readonly action: ActionItem | null;
}

export function useDuplicateAction(node: ElementNode): UseDuplicateActionResult {
  const { pageId, zone } = useGridEditorContext();
  const duplicateElement = useDuplicateElement(pageId, zone);

  const handleDuplicate = useCallback(() => {
    duplicateElement.mutate(node.id, {
      onError: (error) => {
        showToast(error.message);
      },
    });
  }, [duplicateElement, node.id]);

  if (!node.canCreate) {
    return { action: null };
  }

  const action: ActionItem = {
    key: 'duplicate',
    label: 'Duplicate',
    onAction: handleDuplicate,
  };

  return { action };
}
```

**Step 4: Run test — expect pass**

**Step 5: Commit**

```
feat: add useDuplicateAction hook for same-container duplication
```

### Task 13: Wire duplicate action into all block components

**Files:**
- Modify: `client/src/components/SectionBlock/SectionBlock.tsx`
- Modify: `client/src/components/RowBlock/RowBlock.tsx`
- Modify: `client/src/components/ColumnBlock/ColumnBlock.tsx`
- Modify: `client/src/components/ElementCard/ElementCard.tsx`

**Step 1: Update each component**

The pattern is identical for all four. In each file:

1. Add import: `import { useDuplicateAction } from '@/hooks/useDuplicateAction';`
2. Add hook call: `const { action: duplicateAction } = useDuplicateAction(section);` (use appropriate variable name)
3. Update actions array to include duplicateAction:

```typescript
const actions = [
  ...(duplicateAction !== null ? [duplicateAction] : []),
  ...(archiveAction !== null ? [archiveAction] : []),
];
```

This places "Duplicate" before "Archive" in the menu — non-destructive actions first.

**Step 2: Run existing tests**

Run: `npm run test`

**Step 3: Commit**

```
feat: wire duplicate action into all block component action menus
```

---

## Phase 6: Frontend — "Duplicate to..." Dialog

### Task 14: Determine element type for container resolution

Before building the dialog, we need a utility that maps an element node to the `elementType` string the `acceptableContainers` endpoint expects.

**Files:**
- Create: `client/src/utils/getElementType.ts`

**Step 1: Write the failing test**

Create `client/src/tests/utils/getElementType.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { getElementType } from '@/utils/getElementType';

describe('getElementType', () => {
  it('returns "section" for section nodes', () => {
    expect(getElementType({ containerType: 'section' } as any)).toBe('section');
  });

  it('returns "row" for row nodes', () => {
    expect(getElementType({ containerType: 'row' } as any)).toBe('row');
  });

  it('returns "column" for column nodes', () => {
    expect(getElementType({ containerType: 'column' } as any)).toBe('column');
  });

  it('returns "element" for simple element nodes', () => {
    expect(getElementType({} as any)).toBe('element');
  });
});
```

**Step 2: Run test — expect failure**

**Step 3: Implement**

```typescript
import type { ElementNode } from '@/types/elements';
import { isContainerNode } from '@/types/elements';

export function getElementType(node: ElementNode): string {
  if (isContainerNode(node)) {
    return node.containerType;
  }
  return 'element';
}
```

**Step 4: Run test — expect pass**

**Step 5: Commit**

```
feat: add getElementType utility for container resolution
```

### Task 15: `DuplicateToDialog` component

**Files:**
- Create: `client/src/components/DuplicateToDialog/DuplicateToDialog.tsx`
- Create: `client/src/components/DuplicateToDialog/DuplicateToDialog.scss`

**Step 1: Write the failing test**

Create `client/src/tests/components/DuplicateToDialog.test.tsx`:

```typescript
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import DuplicateToDialog from '@/components/DuplicateToDialog/DuplicateToDialog';

// Mock hooks — these will be refined as the dialog shape solidifies
vi.mock('@/hooks/useDuplicateToQueries', () => ({
  usePages: () => ({
    data: [
      { id: 1, title: 'Home', parentId: 0, hasGridZones: true },
      { id: 2, title: 'About', parentId: 0, hasGridZones: false },
    ],
    isLoading: false,
  }),
  useZones: () => ({
    data: ['main'],
    isLoading: false,
  }),
  useAcceptableContainers: () => ({
    data: [{ id: 10, title: 'Hero Section', type: 'section' }],
    isLoading: false,
  }),
}));

describe('DuplicateToDialog', () => {
  it('renders page picker on open', () => {
    render(
      <DuplicateToDialog
        isOpen
        elementType="row"
        currentPageId={1}
        onConfirm={vi.fn()}
        onCancel={vi.fn()}
      />,
    );

    expect(screen.getByText('Home')).toBeInTheDocument();
  });

  it('disables pages without grid zones', () => {
    render(
      <DuplicateToDialog
        isOpen
        elementType="row"
        currentPageId={1}
        onConfirm={vi.fn()}
        onCancel={vi.fn()}
      />,
    );

    const aboutOption = screen.getByText('About');
    // The About page (hasGridZones: false) should appear disabled
    expect(aboutOption.closest('[aria-disabled="true"]')).not.toBeNull();
  });
});
```

**Step 2: Run test — expect failure**

**Step 3: Implement the dialog**

The dialog is a multi-step modal. Build it incrementally — start with the page picker step, add zone and container steps. The implementer should follow these guidelines:

- Use `<dialog>` element (same as `ConfirmDialog`)
- Use `data-testid` attributes for all interactive elements
- Three internal steps managed by local state: `'page' | 'zone' | 'container'`
- Each step renders a list or search field
- "Back" button navigates to previous step
- "Confirm" button only enabled when all required selections are made
- Inline error message area for API errors
- Skips zone step when `zones.length === 1` (auto-selects the single zone)
- Skips container step when `elementType === 'section'` (sections go into page)

Props interface:

```typescript
interface DuplicateToDialogProps {
  readonly isOpen: boolean;
  readonly elementType: string;
  readonly currentPageId: number;
  readonly onConfirm: (targetPageId: number, targetZone: string, targetParentId: number) => void;
  readonly onCancel: () => void;
  readonly error?: string | null;
}
```

The implementer should create the SCSS file alongside the component, following the existing naming conventions (`confirm-dialog` → `duplicate-to-dialog`).

**Step 4: Run tests — expect pass**

**Step 5: Commit**

```
feat: add DuplicateToDialog multi-step component
```

### Task 16: `useDuplicateToAction` hook

**Files:**
- Create: `client/src/hooks/useDuplicateToAction.ts`

**Step 1: Write the failing test**

Create `client/src/tests/hooks/useDuplicateToAction.test.ts`:

```typescript
import { describe, it, expect, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useDuplicateToAction } from '@/hooks/useDuplicateToAction';

vi.mock('@/hooks/GridEditorContext', () => ({
  useGridEditorContext: () => ({ pageId: 1, zone: 'main' }),
}));

vi.mock('@/hooks/useElementMutations', () => ({
  useDuplicateToElement: () => ({ mutate: vi.fn() }),
}));

describe('useDuplicateToAction', () => {
  it('returns null when canCreate is false', () => {
    const node = { id: 1, canCreate: false } as any;
    const { result } = renderHook(() => useDuplicateToAction(node));
    expect(result.current.action).toBeNull();
  });

  it('returns action with label "Duplicate to..." when canCreate is true', () => {
    const node = { id: 1, canCreate: true } as any;
    const { result } = renderHook(() => useDuplicateToAction(node));
    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.key).toBe('duplicate-to');
    expect(result.current.action!.label).toBe('Duplicate to…');
  });
});
```

**Step 2: Run test — expect failure**

**Step 3: Implement**

```typescript
import { useCallback, useState } from 'react';
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu';
import type { ElementNode } from '@/types/elements';
import { getElementType } from '@/utils/getElementType';
import { useGridEditorContext } from './GridEditorContext';
import { useDuplicateToElement } from './useElementMutations';
import { showToast } from '@/utils/toast';

interface DuplicateToDialogState {
  readonly isOpen: boolean;
  readonly elementType: string;
  readonly onConfirm: (targetPageId: number, targetZone: string, targetParentId: number) => void;
  readonly onCancel: () => void;
  readonly error: string | null;
}

interface UseDuplicateToActionResult {
  readonly action: ActionItem | null;
  readonly dialog: DuplicateToDialogState | null;
}

export function useDuplicateToAction(node: ElementNode): UseDuplicateToActionResult {
  const { pageId, zone } = useGridEditorContext();
  const duplicateToElement = useDuplicateToElement(pageId, zone);
  const [isDialogOpen, setDialogOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleOpen = useCallback(() => {
    setError(null);
    setDialogOpen(true);
  }, []);

  const handleCancel = useCallback(() => {
    setDialogOpen(false);
    setError(null);
  }, []);

  const handleConfirm = useCallback(
    (targetPageId: number, targetZone: string, targetParentId: number) => {
      duplicateToElement.mutate(
        { id: node.id, targetPageId, targetZone, targetParentId },
        {
          onSuccess: () => {
            setDialogOpen(false);
            setError(null);
          },
          onError: (err) => {
            setError(err.message);
          },
        },
      );
    },
    [duplicateToElement, node.id],
  );

  if (!node.canCreate) {
    return { action: null, dialog: null };
  }

  const action: ActionItem = {
    key: 'duplicate-to',
    label: 'Duplicate to\u2026',
    onAction: handleOpen,
  };

  const dialog: DuplicateToDialogState = {
    isOpen: isDialogOpen,
    elementType: getElementType(node),
    onConfirm: handleConfirm,
    onCancel: handleCancel,
    error,
  };

  return { action, dialog };
}
```

**Step 4: Run test — expect pass**

**Step 5: Commit**

```
feat: add useDuplicateToAction hook with dialog state management
```

### Task 17: Wire "Duplicate to..." into all block components

**Files:**
- Modify: `client/src/components/SectionBlock/SectionBlock.tsx`
- Modify: `client/src/components/RowBlock/RowBlock.tsx`
- Modify: `client/src/components/ColumnBlock/ColumnBlock.tsx`
- Modify: `client/src/components/ElementCard/ElementCard.tsx`

**Step 1: Update each component**

In each file:

1. Add imports:
   ```typescript
   import { useDuplicateToAction } from '@/hooks/useDuplicateToAction';
   import DuplicateToDialog from '@/components/DuplicateToDialog/DuplicateToDialog';
   import { useGridEditorContext } from '@/hooks/GridEditorContext';
   ```

2. Add hook call:
   ```typescript
   const { action: duplicateToAction, dialog: duplicateToDialog } = useDuplicateToAction(section);
   const { pageId } = useGridEditorContext();
   ```

3. Update actions array:
   ```typescript
   const actions = [
     ...(duplicateAction !== null ? [duplicateAction] : []),
     ...(duplicateToAction !== null ? [duplicateToAction] : []),
     ...(archiveAction !== null ? [archiveAction] : []),
   ];
   ```

4. Add dialog render (alongside ConfirmDialog):
   ```tsx
   {duplicateToDialog !== null && duplicateToDialog.isOpen && (
     <DuplicateToDialog
       isOpen={duplicateToDialog.isOpen}
       elementType={duplicateToDialog.elementType}
       currentPageId={pageId}
       onConfirm={duplicateToDialog.onConfirm}
       onCancel={duplicateToDialog.onCancel}
       error={duplicateToDialog.error}
     />
   )}
   ```

**Step 2: Run all frontend tests**

Run: `npm run test`

**Step 3: Commit**

```
feat: wire duplicate-to action and dialog into all block components
```

---

## Phase 7: Integration Verification

### Task 18: Full QA pass

**Step 1: Run PHP static analysis**

Run: `make analyse`

**Step 2: Run PHP tests**

Run: `make test`

**Step 3: Run frontend QA**

Run: `npm run qa`

**Step 4: Fix any issues**

**Step 5: Commit any fixes**

```
fix: address QA findings from duplicate-to feature
```

---

## Phase 8: E2E Tests (deferred)

E2E tests require Docker services running and are more complex to write. They should be implemented after the feature is verified manually in the browser. The design document outlines three E2E test journeys:

1. Duplicate Section within same zone via "Duplicate here"
2. Duplicate Section to different page via "Duplicate to..." dialog
3. Duplicate content element to different container on same page

These should follow the existing E2E conventions in `CLAUDE.md` — use `loadAndNavigate`, `data-testid` locators, and the `resetFixtures` cleanup pattern.

---

## Task Dependency Graph

```
Task 1 (DTO) → Task 2 (Parser)
                    ↓
Task 3 (persistAppend) → Task 4 (apiDuplicateTo) → Task 5 (Deep copy tests)
                                                          ↓
Task 6 (acceptableContainers) → Task 7 (zones) → Task 8 (pages)
                                                       ↓
Task 9 (API client) → Task 10 (query keys) → Task 11 (hooks)
                                                    ↓
Task 12 (useDuplicateAction) → Task 13 (wire duplicate-here)
                                        ↓
Task 14 (getElementType) → Task 15 (DuplicateToDialog) → Task 16 (useDuplicateToAction) → Task 17 (wire duplicate-to)
                                                                                                  ↓
                                                                                           Task 18 (QA)
```

Backend tasks (1–8) and frontend tasks (9–17) can be parallelized after Task 8 completes, since the frontend depends on the API contract but not the implementation.

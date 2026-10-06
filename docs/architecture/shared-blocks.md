# Shared Blocks Architecture

A shared block is grid content maintained once in a library and placed on any number of pages. This document covers how that is built: the data model, the invariants that keep it tractable, the publishing model, and the places where the rest of the module has to know about it. For what authors see and do, read the [usage guide](../usage/shared-blocks.md) first.

## Key Files

| File | Role |
|---|---|
| `src/Model/SharedBlock.php` | The library record. Owns exactly one root element; carries permissions and the delete cleanup |
| `src/Model/SharedBlockReference.php` | The placement. A `GridElement` holding only placement data and a `has_one Block` |
| `src/Service/SharedBlockService.php` | Every lifecycle operation: create, place, convert, detach, delete, publish |
| `src/Controllers/SharedBlockController.php` | The block library API (`/admin/grid-shared-blocks`) — see [endpoints](backend.md#sharedblockcontroller-admingrid-shared-blocks) |
| `src/Validation/ReorderValidator.php` | Placement-time checks: `BLOCK_EMPTY`, `SHARED_BOUNDARY` |
| `src/Validation/PlacementRulesTrait.php` | The placement rules shared with write-time validation |
| `src/Service/SharedBlockUsageResolver.php` | Which pages place a block, on draft and on live |
| `src/Service/SharedBlockLocaliser.php` | Gives a block content in the active Fluent locale |
| `src/Admin/SharedBlockAdmin.php` | The library screen |
| `src/Forms/GridFieldAddSharedBlockButton.php` | The library's create control (with `client/js/shared-block-add.js`) |
| `src/Forms/SharedBlockItemRequest.php` | The block edit form's two delete actions |
| `src/Value/SharedBlockMeta.php`, `SharedBlockStatus.php`, `SharedBlockDeleteMode.php` | Wire metadata, aggregate publish state, delete mode |
| `client/src/js/components/SharedBlockFrame/` | The read-only frame a placement renders in the page editor |
| `client/src/js/utils/sharedBlockNesting.ts` | Client mirror of the no-nesting check |

## Data Model

```
Page (SiteTree)                       SharedBlock (library record)
  └── Section                           └── root element   (exactly one)
        └── Row                               └── … its scaffolded subtree
              └── SharedBlockReference ─ Block ─┘
```

A `SharedBlock` holds its subtree through the same polymorphic parent as everything else (`ParentClass = SharedBlock::class`), via its `RootElements` has_many. Structurally that is a has_many; every creation path writes exactly one root, and `getRootElement()` reads `->first()`.

A `SharedBlockReference` is an ordinary `GridElement`. It holds `Parent`, `Sort` and `Zone` (all inherited) plus `Block`, and nothing else — no content of its own.

### The effective class

Placement rules judge a reference by its block's **root class**, never by `SharedBlockReference`. A section-rooted block is placed where a Section may go, a leaf-rooted block where a content element may go.

`GridElement::getPlacementClass()` is the single question every caller asks: it answers `static::class` for an ordinary element, and `SharedBlockReference` overrides it to return `getEffectiveRootClass()`. A caller that reads `$element::class` directly treats every placement as a leaf.

`getEffectiveRootClass()` resolves on the **draft** stage, whatever the ambient stage. A block's shape is a structural fact; whether it is published is a separate question. Resolving on the current stage would make the LIVE write during a page publish see an unpublished block as empty, and fail validation.

| Placed at | Block must be rooted at |
|---|---|
| Page root | `Section` |
| Inside a `Section` | `Row` |
| Inside a `Row` | `Column` |
| Inside a `Column` | a content element |

## Invariants

These four rules are what keep the feature small. Each one removes a class of problem rather than handling it.

### No nesting

A reference may never sit anywhere inside a shared subtree, nor root a block. Cycles are therefore impossible and nothing needs cycle detection.

| Layer | Enforcement |
|---|---|
| Write time | `HierarchyValidationService` fails `SHARED_NESTING` |
| Convert | `SharedBlockService::convertToShared()` walks the whole subtree (`containsPlacement()`, one query per level). Conversion re-parents the root with a single UPDATE, so no descendant's own validation fires — without this walk a nested placement lands unchallenged and the block fails `SHARED_NESTING` on its first publish, unrepairable from the editor |
| Client | `containsSharedBlockPlacement()` hides "Convert to shared block" on such a subtree; place and convert are suppressed in the library editor via the editor root (`useEditorRoot().kind === 'sharedBlock'`) |

The client suppressions key off the editor root, not per-node fields: inside the library editor no node carries `sharedBlockKey` (see [Editor](#editor)), so a per-node check would see page-local content everywhere.

### No boundary crossing

Nothing moves between a shared subtree and page content, in either direction. `ReorderValidator` compares the source and target shared contexts and fails `SHARED_BOUNDARY` when they differ; a fresh, unparented element has no source context and skips the check. Client-side collision detection (`collisionDetection.ts`) filters drop targets by `sharedBlockKey` so the editor never offers such a drop. The validator is the backstop.

### One root, undeletable and unduplicable

The block's root is alone at its level. `GridElement::canDelete()` returns false when the parent is a `SharedBlock`, checked **above** `extendedCan`: it is a structural invariant, not a permission, so no `updateCanDelete` extension can grant it. `GridController::apiDuplicate` refuses the root because an in-place duplicate copies `ParentID`/`ParentClass`, giving the block a second root that `getRootElement()` then hides.

The client mirrors both through `isSharedBlockRootNode()`: no archive action (via the payload's `canDelete`), no duplicate actions, and no drag handle — a drag from the root has no legal target.

Framework removal paths check no permission (`DataObject::delete()`, `doArchive()`, `$cascade_deletes`), so deleting the block still takes the root with it.

Below the root, `canDelete()` delegates to the block's `canEdit()`, never its `canDelete()`: a project extension vetoing block deletion must not freeze the block's contents.

### No zones inside a block

`Zone` is placement data and belongs only to a page root. A block's subtree has none: `create()` assigns none, `convertToShared()` moves the root's zone onto the new reference and clears the root's, and `GridElementService::createElement()` forces `''` for any non-`SiteTree` parent. The library editor therefore sends no `zone` field at all — `parseCreateBody()` rejects an empty one. The client makes that structural: the editor root is an `EditorRoot` union (`client/src/js/types/editorRoot.ts`) whose `SharedBlockRoot` arm has no `zone`.

## Ownership and Publishing

A block has its own draft/live lifecycle. Publishing a page never publishes a block; publishing a block updates every page that places it.

| Owner | Relation | `$owns` / `$cascade_*` | Effect |
|---|---|---|---|
| Page (`GridPageExtension`) | `GridRoots` → `GridElement.Parent` | yes | Sections **and** page-root placements publish, archive and duplicate with the page |
| `SharedBlockReference` | `Block` (has_one) | **no** | Page publish stops at the boundary |
| `SharedBlock` | `RootElements` → `GridElement.Parent` | yes | `publishRecursive()` on the block carries the whole subtree |
| `Section` / `Row` / `Column` | `Rows` / `Columns` / `Elements` → `GridElement.Parent` | yes | Mid-tree placements are children like any other |

Three rules follow from that table:

- **One has_many per owner.** Two has_many relations on the same owner pointing at the same polymorphic `.Parent` make SilverStripe's reverse-owner lookup ambiguous, and the page's publish cascade breaks silently. `GridPageExtension` still declares `Sections` for `$Sections` in templates, but keeps it out of `$owns` and `$cascade_*`.
- **Child relations are typed to `GridElement`.** A placement standing in for a Row or Column is a `SharedBlockReference`. A relation narrowed to the concrete child class drops it from ownership, cascades, `getChildren()` and the template loops: it shows in the editor and never publishes or renders. For the same reason the auto-scaffold guard counts `GridElement::get()` children, not the child class.
- **Root enumeration covers both root classes.** `GridElement::ROOT_ELEMENT_CLASSES` names them (`Section`, `SharedBlockReference`); read roots through `OrmGridElementRepository::findByParents()`. `Section::get()` at page root has already dropped placements from Fluent copy-to-locale, locale deletion and the placement service's sibling list.

`SharedBlockService::setPublished()` is `publishRecursive()` / `doUnpublish()` on the block. The editor's badge comes from `SharedBlockStatus`, an aggregate: a block is only as published as its least published part, the record and every element in its subtree.

### The conversion window

Converting is draft-only, so live keeps rendering the old subtree. The **next page publish**, though, runs SilverStripe's disowned-object cleanup and clears that subtree's live parent link. From then until the block is published, the placement renders nothing on live. The editor's `notPublished` badge is the mitigation. Pinned by `SharedBlockServiceTest::testConvertLeavesTheOldSubtreeOnLiveUntilThePageIsRepublished` and `testRepublishingThePageUnlinksTheDisownedSubtreeFromLive`.

## Lifecycle Operations

All on `SharedBlockService`, all returning `Result`.

| Operation | Entry point | Mechanism |
|---|---|---|
| `create($rootClass)` | Library add button | Writes block + root in one transaction; the root's own write scaffolds the rest |
| `place($block, $parent, $zone, …)` | `api/place` | Localises the block, validates while the reference is still unparented, then writes and splices it in |
| `convertToShared($root, $title)` | `api/convert` | Re-parents the root to a new block; a new reference takes its exact `Sort` and `Zone`. No content copied, so IDs and history survive |
| `detach($reference)` | `api/detach` | `duplicate(true)` of the root, re-pointed to the reference's position; the reference is archived |
| `delete($block, $mode)` | Block edit form | See [Deleting](#deleting) |
| `setPublished($block, $published)` | `api/setPublished` | `publishRecursive()` / `doUnpublish()` |

Placements are **created** only by `SharedBlockController`: `RequestBodyParser` rejects `SharedBlockReference` and `GridNodeMapper::getAllowedTypes()` filters it out of the generic create path. Once created, a placement is an ordinary element, archived (`api/delete`) and moved (`api/reorder`) through `GridController`.

`place()` validates **before** assigning the parent: `ReorderValidator` short-circuits same-parent moves to ok, so validating an already-parented reference would check nothing. It also refuses a page-root placement without a zone before localising, so a refused placement never leaves a localised copy behind.

### Creating

Blocks are created already seeded; there is no create endpoint, and no rootless block can exist.

`SharedBlockAdmin::getGridFieldConfig()` replaces `GridFieldAddNewButton` with `GridFieldAddSharedBlockButton`, a `GridField_ActionProvider`. Every shape is a `GridField_FormAction` carrying `['root' => <container type | leaf class>]`; `handleAction()` resolves it (leaf classes validated by `ContainerType::Column->isChildCreatable()`), applies both `canCreate()` gates and redirects to the new block's editor. The library listing is not a grid editor, so the control is server-rendered PHP plus the standalone `client/js/shared-block-add.js`, never the editor bundle.

The stock `item/new` route is closed: `SharedBlockItemRequest::ItemEditForm()` 404s a record that is not in the database. It would otherwise produce a rootless block that could only ever grow a Section root. An empty `Title` gets a numbered `SharedBlock.DEFAULT_TITLE` in `onBeforeWrite()`.

### Deleting

Deleting a block is always allowed — `SharedBlock::canDelete()` does not veto on usage — but the caller must choose what happens to the placements:

| `SharedBlockDeleteMode` | Effect |
|---|---|
| `Remove` | Placements are archived with the block |
| `Unshare` | Each placement is first replaced with an independent copy (the detach mechanism); the copy is published wherever the placement was live. Refused when the block has no root left to copy |

Both reach live without republishing the consuming pages.

`SharedBlock::onBeforeDelete()` archives every remaining reference on draft, and deletes live-only stragglers from live. Placements are page content, so no ownership config on the block reaches them; this hook is what keeps references from being stranded on every delete path (the CMS action, a dev task, a bare `delete()` in project code). Never bypass it by deleting rows directly.

The library **listing** offers no removal: `getGridFieldConfig()` removes both `GridFieldArchiveAction` and `GridFieldDeleteAction`. Both must go — for a versioned model the archive action is what suppresses the stock delete (`augmentColumns()`), so removing it alone promotes the row action to a permanent delete. A row cannot ask for the mode, so removal lives only in the edit form.

## Validation

Shared blocks add three checks to the [validation layer](backend.md#validation-layer):

| Check | Where | Fails |
|---|---|---|
| Reference inside a shared context | Write time (`HierarchyValidationService`) | `SHARED_NESTING` |
| Block has no root | Placement time (`ReorderValidator`) | `BLOCK_EMPTY` |
| Source and target on different sides of a boundary | Placement time (`ReorderValidator`) | `SHARED_BOUNDARY` |

At write time an unresolvable effective class (block emptied, or no content in this locale) **passes**. A block may be emptied long after it was placed, and page publish writes every owned root to LIVE, so failing there made the consuming pages unpublishable. Refusing to place an empty block is the placement-time check's job.

A parent that is a `SharedBlock` passes too: any non-reference class may root a block.

## Permissions

| Method | Code |
|---|---|
| `canEdit`, `canDelete`, `canCreate` | `'CMS_ACCESS_CMSMain'` (page access) |
| `canView` | `CMS_ACCESS` (any CMS access) |

A shared block is page content maintained in one place, so managing one requires page access. `SharedBlockAdmin::$required_permission_codes` gates the screen on the same code so API and UI agree. The literal matters:

- Not the bare `CMS_ACCESS`: the framework special-cases it to succeed for **any** `CMS_ACCESS_*` grant, so it gates nothing.
- Not `'CMS_ACCESS_' . CMSMain::class`: `CMSMain` registers its code under the short name, so the FQCN variant is held by nobody and silently denies every non-admin.

`canView` stays broad on purpose — seeing what is in the library is not editing it. Narrow it per project with `updateCanView`.

## Fluent

The block record is one cross-locale row; its subtree is locale-isolated like every other grid element; placements are per-locale page content pointing at the same block.

The invariant: **a placement in a locale implies the block has content in that locale.** `SharedBlockLocaliser::ensureLocalised()` materialises it, called from `SharedBlockService::place()`, the page-copy hooks in `FluentGridPageExtension`, and the block's own copy hooks in `FluentSharedBlockExtension`. It is a no-op without Fluent.

Its idempotency guard only clones into a locale holding no roots for that block, so a translation is never overwritten and two pages placing one block cannot fork it. Two traps for callers:

- The page-copy pass must visit **every** cloned node, not just roots: a row-rooted block sits inside a Section.
- It must run in the **target** locale, outside the `withState(source)` block. The guard counts roots in the active locale; run in the source, it finds content and silently skips.

Localising is additive and cross-page: other pages in that locale placing the same block start rendering too.

## Editor

### Wire format

A placement's node is typed `NodeType::Element` and carries `sharedBlock` (`SharedBlockMeta`: `blockId`, `title`, `usageCount`, `status`, `editLink`). `NodeType::SharedBlock` is only ever a tree **root** type — the library editor's `rootParent`.

`sharedBlockKey` is not sent by the server. `attachDerivedFields()` in `client/src/js/api/endpoints.ts` stamps it on every **descendant** of a placement, never the placement itself; two nodes share a context when it matches. In the library editor the tree is rooted at the block and contains no placement, so no node carries it.

A placement carries children without being a container: `isContainerNode()` is false for it. Any tree walk gated on that alone skips the block's subtree; check `isSharedBlockReferenceNode()` too.

### The read-only frame

A placed block renders read-only in the page editor, because an inline edit would silently change every page placing it. `SharedBlockFrame` provides `PlacementContext`; the editable components consume it to drop drag handles, add/insert buttons, toolbars and title links, and to disable the column size and offset pickers.

The only actions are on the frame's own bar (`SharedPlacementActions`): open/edit go to `sharedBlock.editLink` — the **block's** form in the library, never the root element's — and remove archives the **placement** through the generic delete endpoint.

The library editor stays editable for the same reason it has no `sharedBlockKey`: its tree contains no placement node, so `PlacementContext` is never provided.

## Rendering

`SharedBlockReference::forTemplate()` renders the block's root element directly, with no wrapper, so the markup is identical to the same subtree placed locally and every grid adapter works unchanged. Unlike the effective class, it reads the **current** stage, so live only renders published content.

Every failure — missing block, nothing published, no content in this locale — renders empty and logs a warning. Raw database state must never 500 a page.

Templates must use `$GridZone('<zone>')`, which returns Sections and placements in one `Sort` sequence. `$Sections` is a Section-only relation and silently omits every placement. See [Template integration](../usage/templates.md#gridzone-vs-sections).

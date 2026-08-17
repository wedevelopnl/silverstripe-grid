# The Grid Editor

A tour of what the module puts in front of a CMS user, and what each control does. Read this before the developer guides — the rest of the documentation assumes you know what a Section, Row and Column look like on screen.

Everything below was captured against the Bootstrap preset (a 12-column grid, viewports `xs`…`xxl`). Column counts and viewport names change with the [active adapter](../architecture/grid-adapter.md); nothing else does.

## Where the editor lives

The editor is a form field on the page's **Content** tab, below the standard page fields. It replaces the `Content` HTML editor rather than sitting next to it.

![The grid editor inside the SilverStripe page edit form](../images/cms-context.png)

A page can carry more than one editor — one per [zone](templates.md#multi-zone-pages) — in which case each zone gets its own field with its own section list.

Projects can opt a page type into a **"Use grid on this page"** checkbox, letting editors choose the grid or the plain Content field per page. It is off by default, so the grid is simply always there unless your developers turned the toggle on — see [Per-page editor toggle](templates.md#per-page-editor-toggle).

## Anatomy

![A section containing two rows: a centred 8-of-12 intro column, then a row of three 4-column cards](../images/editor-overview.png)

Reading the screenshot from the outside in:

| Region | What it is |
|--------|-----------|
| **Section** (`What we do`) | Top-level band on the page. Sections are the only thing that can sit at page level. |
| **Row** (`Introduction`, `Service cards`) | A horizontal group inside a section. The header shows the row title and its column count. |
| **Column** (`Centred intro`, `Design`, …) | A slot in the grid, with its own width and offset pickers. |
| **Content block** (the white cards) | The actual content. Each card shows its type icon, title, and a short text summary. |
| **`Add Section` / `Add Row` / `+ Add content`** | Insert a new child at that level. |
| **The `⊕` buttons flanking a row** | Insert a column before or after the ones already there. |

The hierarchy is fixed and enforced on the server: **Section → Row → Column → content**. A section only ever holds rows, a row only ever holds columns, and a column holds any content block. You cannot nest a section inside a column.

Because the levels are fixed, creating a section also creates the row and column inside it, so a new section is immediately usable. The same happens one level down when you add a row.

## Structural changes save immediately

Every action in this guide — adding, moving, resizing, archiving, duplicating — is written to the database the moment you perform it, through the module's own API. They are **not** staged behind the CMS form's *Save* button.

The editor applies each change to the on-screen tree first and reconciles with the server afterwards. If the server rejects the change or the network fails, the tree snaps back to its previous state and an error appears. Nothing is left half-applied.

The *Save* button still governs the ordinary page fields (page name, URL segment, and each block's own fields when you open one for editing).

## Adding content

`+ Add content` inside a column opens the type picker:

![The "Add content element" dialog listing the available block types](../images/element-type-picker.png)

Each tile is one `ContentElement` subclass, showing its `$singular_name`, icon, and description. The module ships a single generic block; projects add their own, and each new subclass appears here automatically with no registration step. See [Building a custom content element](custom-elements.md).

Picking a tile creates the block and drops it into the column. Open it with the pencil (**Edit**) action to fill in its fields.

## Column width and offset

Every column header carries two pickers — width and offset — that write straight to the column's grid settings.

![The width picker open on a column, listing 1 to 12 columns](../images/column-width.png)

- **Width** is how many grid columns the column spans, from 1 to the adapter's column count. The last entry in the list is **hidden**, which drops the column at the current viewport instead of giving it a width.
- **Offset** is how many empty grid columns to leave in front of it. The list stops where `width + offset` would overflow the row, so an invalid combination cannot be chosen. It is disabled on a full-width or hidden column, where an offset has nothing to do.

The `Offset 2` on the `Centred intro` column in the [anatomy screenshot](#anatomy) is how an 8-wide column ends up centred in a 12-column grid.

## Viewports

A column's width, offset, and visibility are stored as a **default** plus a set of **per-viewport overrides**. The default applies everywhere; an override applies only at its own viewport.

The control at the top of the editor picks which viewport you are editing:

![The viewport picker showing six viewports, the default marker, and the reset-overrides section](../images/viewport-picker.png)

- The list is the active adapter's viewports, largest breakpoint last.
- **Default** marks the viewport whose values are stored as the default rather than as an override — Bootstrap's `md` here.
- A **dot** next to a viewport means at least one column in this grid overrides it.
- **Reset overrides** clears overrides again: one entry per viewport that has any, plus **All viewports**. The number beside each entry is how many columns the reset would affect, and confirming it is a separate step.

Switch viewport, change a column's width, and you have created an override for that viewport only. Switch back and the default is untouched.

How a viewport *without* an override resolves is a project-level decision, not an editor one: the default strategy is **isolated** (it falls back to the default), and a project can switch `GridSettingsResolver` to **cascade** so overrides carry forward mobile-first. See [Grid Settings](../architecture/backend.md#grid-settings).

## Moving things

Each section, row, column, and content block has a drag handle (the dotted grip at the left of its header). Drop targets are constrained to the hierarchy, so a column can only land in a row and a block can only land in a column — including into a *different* row or column, on the same page.

Drops persist immediately, with the same optimistic-then-reconcile behaviour described above. Sort order is tracked per parent and, for sections, per zone: reordering one zone never renumbers another.

The full mechanics are in [Drag and Drop](../architecture/drag-and-drop.md).

## Publishing state

Grid content is versioned and publishes with the page. Publishing the page publishes the whole tree beneath it in one action; there is no per-element publish button in the editor.

![The "Get in touch" section showing a Draft badge on one block and unpublished outlines](../images/status-badges.png)

Two distinct signals:

- An **orange outline** means there is unpublished work *at or below* that element. It propagates up, so an outlined section may just be reporting a changed block three levels down.
- A **`Draft` or `Modified` pill** means the outlined element is itself the unpublished one. `Draft` is never published; `Modified` is published but changed since.

Outline with no pill therefore reads as "the change is somewhere below this". Screen readers get the same distinction announced as *"Contains unpublished changes"*, because the outline is a border colour and inaudible.

## Element actions

Every element exposes the same action set. On wide headers it renders as an icon row; on narrow ones — a card in a 4-column slot, or any column header — the whole set folds into the `⋯` menu:

![The overflow menu on a content block: View history, Duplicate, Open in a new tab, Edit, Archive, Duplicate to…](../images/element-actions.png)

| Action | Effect |
|--------|--------|
| **View history** | Opens the element's edit form on its History tab. |
| **Collapse** / **Expand** | Folds the element in the editor. A view preference, not content: it is remembered per grid area in your own browser and changes nothing on the element. |
| **Duplicate** | Copies the element, with its subtree, next to the original. |
| **Open in a new tab** | The element's edit form, in a new browser tab. |
| **Edit** | The element's edit form, in the current tab. |
| **Archive** | Removes the element. Asks for confirmation and names how many child elements go with it. |
| **Duplicate to…** | Copies the element to another page, choosing target page → zone → parent container. |

Permissions are applied per action: **Archive** needs delete permission on the element, **Duplicate** and **Duplicate to…** need create permission. An action the user cannot perform is dropped from the overflow menu entirely, and rendered greyed out in the icon row so the toolbar keeps a stable shape.

The header strip above the canvas also has a **collapse/expand all** button that folds every section at once.

> The strip's other three buttons — *Reset changes*, *Open page*, *Remove all sections* — are rendered disabled on purpose. They are placeholders for actions the design specifies but the editor does not implement yet.

## Version history

Opening a page's **History** tab, or an element's **View history** action, renders the grid read-only at that version: same layout, no drag handles, no add buttons, no pickers. You can still switch viewport, so you can inspect how an old version was configured per breakpoint — the reset-overrides entries are absent, since there is nothing to write to.

## See also

- [Template integration](templates.md) — how what you build here renders on the front end
- [Building a custom content element](custom-elements.md) — adding your own block types to the picker
- [Grid Adapter System](../architecture/grid-adapter.md) — where the column count and viewport names come from
- [Drag and Drop](../architecture/drag-and-drop.md) — the reorder pipeline in detail

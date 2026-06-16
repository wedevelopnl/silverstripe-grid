# Keys for Cache Integration

The grid optionally integrates with [keys-for-cache](https://github.com/silverstripe-terraformers/keys-for-cache) (KFC) to provide reliable, auto-invalidating cache keys for grid elements. KFC is **not** a hard dependency — the module imports no KFC classes and works without it. Install KFC `^3.0` (the SilverStripe 6-compatible release) to enable the integration.

## What activates

`_config/keys-for-cache.yml` is guarded by `Only: moduleexists: silverstripe-terraformers/keys-for-cache`, so its config applies only when KFC is installed. With KFC present it:

- Opts every grid element into a maintained cache key by setting `has_cache_key: true` on `GridElement`. This is inherited by `Section`, `Row`, `Column`, `ContentElement`, and any custom block you subclass from them.
- Declares `cares` downward — `Section` → `Rows`, `Row` → `Columns`, `Column` → `Elements` — so a change to any descendant invalidates its ancestors' cache keys.

KFC applies its `CacheKeyExtension` to `DataObject` globally, so this module imports no KFC classes — the integration is config-only.

## Using the cache key in templates

Every grid element exposes a `$CacheKey`. Wrap whichever level you cache with a `<% cached %>` block. For example, caching each top-level section:

```html
<% loop $Sections %><% cached $CacheKey %>$Me<% end_cached %><% end_loop %>
```

## Media (known limitation)

Media images are **not** part of the cache-key graph. KFC can only `cares` about a relation that has a reciprocal back-relation, and the grid's media (`BlockMediaExtension`'s `MediaImage` / `VideoCustomThumbnail`) are `has_one` relations to the shared `Image` class, which has no reciprocal relation back to the element. Declaring a `cares` dependency on them is therefore not possible.

Consequences:

- Editing media **via the element's CMS form** re-saves the element, which **does** invalidate the element's cache key (through the downward `cares` chain).
- Editing an in-use `Image` record **directly in asset-admin** does **not** auto-invalidate the element's cache key.

A per-element owned media model — one KFC could traverse with a reciprocal relation — is a planned follow-up.

## With Fluent

KFC's cache keys are not locale-aware out of the box. For locale-safe keys, include the locale (and reading mode / user) in the global cache key, in the project's config:

```yaml
SilverStripe\View\SSViewer:
  global_key: '$CurrentReadingMode, $CurrentUser.ID, $CurrentLocale'
```

## Cost

KFC processes its relationship graph synchronously on every write, so very large grids pay a per-write cost. This applies only when KFC is installed.

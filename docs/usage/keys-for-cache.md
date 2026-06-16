# Caching (keys-for-cache)

When [silverstripe-terraformers/keys-for-cache](https://github.com/silverstripe-terraformers/keys-for-cache) is installed, every grid element carries a cache key that changes whenever anything inside it changes. Wrap a section in a `<% cached %>` block with that key and it is rendered once, then served from the cache until an editor changes something in it.

The module does not depend on keys-for-cache and imports none of its classes. Without it, nothing on this page applies.

## Enabling it

```bash
composer require silverstripe-terraformers/keys-for-cache:^3.0
vendor/bin/sake db:build --flush
```

`^3.0` is the release for SilverStripe 6. The grid's configuration for it lives in `_config/keys-for-cache.yml` and applies only when the module is present.

## What gets a key

- **Every grid element**: `has_cache_key` is set on `GridElement`, so `Section`, `Row`, `Column`, `ContentElement`, `SharedBlockReference` and any element you subclass from them all expose `$CacheKey`.
- **Changes travel upwards**: `Section` cares about its rows, `Row` about its columns and `Column` about its elements. A change to a block gives its column, row and section a new key; sections elsewhere on the page, and on other pages, keep theirs.
- **Shared blocks reach every placement**: a placement holds none of its block's content, so the block passes each change on to its placements. Publishing a change inside a shared block gives every placement of it a new key, and through those placements the columns, rows and sections around them.
- **Draft and live are separate**: a draft edit changes the draft key only. The live key changes when the edit is published.

To carry these keys, the configuration adds a `References` has_many to `SharedBlock`, pointing at its placements, and has the block `touch` them through it. It adds no database column and does not appear in the block's CMS form.

## Using the key in a template

Cache each root of a zone:

```html
<% loop $GridZone('main') %><% cached $CacheKey %>$Me<% end_cached %><% end_loop %>
```

Loop `$GridZone`, not `$Sections`. `$Sections` lists Sections only, so every shared block placed at the root of the page would be missing from the cached output (see [Template integration](templates.md)).

You can cache at a lower level instead, in your own `Section` or `Row` template. The key on each level covers everything below it.

## Sections with a form

Don't cache a section that holds a [Form element](userforms.md). The cached copy keeps the security token of the visitor it was rendered for, so other visitors' submissions are rejected, and the on-complete message never replaces the form. Leave such sections out of the cache with keys-for-cache's `if` condition, for example `<% cached $CacheKey if $CacheKey %>` together with an `updateCacheKey()` extension that sets the key to `null` for those sections.

## With Fluent

Cache keys are not locale-aware. Add the locale to the global key so one locale's cache is never served in another:

```yaml
SilverStripe\View\SSViewer:
  global_key: '$CurrentReadingMode, $CurrentUser.ID, $CurrentLocale'
```

The first two parts are SilverStripe's default. Note that an edit in one locale gives the element a new key in every locale. See keys-for-cache's [Fluent support](https://github.com/silverstripe-terraformers/keys-for-cache/blob/master/docs/en/fluent.md).

## Limitations

- **Images edited in the file library**: a block's image and video thumbnail are has_one relations to the shared `Image` class, which has no relation back to the block, so keys-for-cache cannot follow them. Choosing another image in the block's own form saves the block and gives it a new key. Replacing an image that is in use directly in the file library does not, and the block keeps its key until it is saved again.
- **Cost on save**: keys-for-cache walks the relations synchronously on every write, so saving or publishing an element in a very large grid takes longer.

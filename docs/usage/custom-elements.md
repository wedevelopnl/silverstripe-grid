# Building a Custom Content Element

Every real site eventually needs content blocks beyond the generic HTML element shipped as `ContentElement`. This guide walks through adding a custom block — the primary extension point of this module.

## What you'll build

A `TeaserBlock` content element with a title, body, link, and image:

```
Column
  └── TeaserBlock   (your custom content element)
```

You'll cover: the PHP subclass, CMS field scaffolding, the optional editor-card summary, registering an icon, and the render template.

## 1. Subclass `ContentElement`

Content elements extend `WeDevelop\Grid\Model\ContentElement`, which itself extends `GridElement`. Only subclass `GridElement` directly if you need a container or something with no HTML-style body.

```php
<?php

declare(strict_types=1);

namespace App\Grid\Elements;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use WeDevelop\Grid\Model\ContentElement;

class TeaserBlock extends ContentElement
{
    private static string $table_name = 'App_TeaserBlock';

    private static string $singular_name = 'Teaser';
    private static string $plural_name = 'Teasers';
    private static string $class_description = 'A card with a heading, body, image, and call-to-action link.';

    // font-icon-* classes come from SilverStripe's admin icon font — pick one
    // that matches the block's purpose. It shows up in the CMS type picker.
    private static string $icon = 'font-icon-block-promo';

    private static array $db = [
        'Subtitle' => 'Varchar(255)',
        'LinkLabel' => 'Varchar(100)',
        'LinkURL' => 'Varchar(2048)',
    ];

    private static array $has_one = [
        'Image' => Image::class,
    ];

    private static array $owns = ['Image'];

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Subtitle', 'Subtitle'),
            UploadField::create('Image', 'Image')
                ->setFolderName('Uploads/teasers'),
            TextField::create('LinkLabel', 'Link label'),
            TextField::create('LinkURL', 'Link URL'),
        ]);

        return $fields;
    }
}
```

Key points:

- `$singular_name` appears in the CMS block picker and on the editor card as the "type" label.
- `$class_description` is shown under the name in the type picker.
- `$icon` is a SilverStripe admin icon class (e.g. `font-icon-block-promo`, `font-icon-block-content`, `font-icon-block-image`). If you skip it, `font-icon-block-content` is used.
- Inherited fields: `Title`, `TitleTag`, `ShowTitle`, `ExtraClass`, `Style`, `HTML` (from `ContentElement`). `getCMSFields()` on the base class already renders the title group and history tab — call `parent::getCMSFields()` to keep them.

Run `dev/build` after adding the class so the new table is created.

## 2. Register allowed-under rules (optional)

By default, any `ContentElement` subclass can be placed inside any `Column` — the `Column` container uses a blocklist (`disallowed_elements: [Section, Row, Column]`) not an allowlist.

If you need to restrict *where* the block can live, add YAML:

```yaml
# app/_config/grid.yml

# Disallow TeaserBlock under the default Column — only allow it under a
# custom column subclass you've also registered.
WeDevelop\Grid\Model\Column:
  disallowed_elements:
    - App\Grid\Elements\TeaserBlock
```

Most sites don't need this. The default (everything allowed under Column) is the right starting point.

## 3. The editor-card summary

The CMS grid editor shows a short plain-text preview on each element's card. By default it comes from `getSummary()`:

- `GridElement::getSummary()` returns `null` — cards for generic elements show no summary line.
- `ContentElement::getSummary()` returns a 20-word plain-text summary of the `HTML` field.

Override `getSummary()` to customise:

```php
use Override;

#[Override]
public function getSummary(): ?string
{
    $parts = array_filter([
        (string) $this->Subtitle,
        (string) $this->LinkLabel,
    ], static fn(string $part): bool => $part !== '');

    return $parts === [] ? null : implode(' — ', $parts);
}
```

Return `null` or `''` to suppress the summary. The backend drops empty values so the card hides the line entirely.

HTML is not rendered — always return plain text. The grid editor treats it as text content.

## 4. Template

Grid elements render through SilverStripe's standard `forTemplate()` pipeline with a two-pass holder/inner split:

- `{Element}_holder.ss` — outer wrapper (the default `GridElement_holder.ss` just emits `$Element`).
- `{Element}.ss` — inner content.

Create `app/templates/App/Grid/Elements/TeaserBlock.ss`:

```silverstripe
<article class="teaser">
    <% if $Image %>
        <div class="teaser__image">$Image.ScaleWidth(600)</div>
    <% end_if %>

    <div class="teaser__body">
        <% if $ShowTitle && $Title %>
            <$TitleTag class="teaser__title<% if $TitleSizeClass %> $TitleSizeClass<% end_if %>">
                $Title
            </$TitleTag>
        <% end_if %>

        <% if $Subtitle %>
            <p class="teaser__subtitle">$Subtitle</p>
        <% end_if %>

        $HTML

        <% if $LinkURL && $LinkLabel %>
            <a class="teaser__link" href="$LinkURL.ATT">$LinkLabel</a>
        <% end_if %>
    </div>
</article>
```

You'll usually also want a holder template. The default holder (`WeDevelop/Grid/Model/GridElement_holder.ss`) simply emits `$Element`, which is fine for most content elements. Override it when you need a wrapping `<div>` or framework-specific classes — see `Section_holder.ss` for an example.

## 5. How the render chain composes

When a page template loops sections, each element's `forTemplate()` resolves through:

1. `$Me` (on the element) → `forTemplate()` → renders the `*_holder.ss` template
2. The holder template refers to `$Element` → renders the inner `*.ss` template

```silverstripe
<% loop $Sections %>
    $Me   {# renders Section_holder.ss, which loops rows, etc. #}
<% end_loop %>
```

CSS classes are contributed from two places:

- `getHolderClasses()` returns a concatenation of `Style`, `ExtraClass`, and element-specific classes from `provideHolderClasses()`. `Row` adds the grid row classes; `Column` adds width/offset/visibility classes.
- `updateHolderClasses` extension hook lets other modules append classes at runtime.

Override `provideHolderClasses()` on your element if you want to inject classes without using the `ExtraClass` CMS field.

## 6. Checklist

- [ ] PHP class extends `ContentElement` (or `GridElement` for non-HTML blocks)
- [ ] `$table_name`, `$singular_name`, `$icon` set
- [ ] `dev/build` run
- [ ] `getCMSFields()` calls `parent::getCMSFields()` to keep the title group and history tab
- [ ] Optional: override `getSummary()` for a custom editor-card preview
- [ ] Template under `templates/{Vendor}/{Module}/Elements/{ClassName}.ss`
- [ ] Restart Vite / `dev/build?flush=1` after adding templates

## See also

- [Templates guide](templates.md) — how the holder chain composes and how themes override templates
- [Backend Architecture](../architecture/backend.md#cms-integration) — the DI and permission model behind element CRUD
- [Internationalization](i18n.md) — translating element field labels

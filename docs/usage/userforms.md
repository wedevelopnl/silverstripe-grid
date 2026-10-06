# Forms (userforms)

When [silverstripe/userforms](https://github.com/silverstripe/silverstripe-userforms) is installed, the grid offers a **Form** element: a form built in the CMS, placed in a column like any other content element.

The block **is** the form. Its fields, its email recipients and its submissions all belong to the block — there is no separate form page behind it. To use one form on several pages, put it in a [shared block](shared-blocks.md).

## Enabling it

```bash
composer require silverstripe/userforms:^7.1
vendor/bin/sake db:build --flush
```

That is all. There is no configuration: the element appears in the type picker, renders and accepts submissions as soon as userforms is present.

On a site **without** userforms there is no Form element in the type picker or in the Grid elements report, and no form route. The module does declare an inert stand-in class in that case, so `db:build` creates an empty `WeDevelop_Grid_UserFormElement` table (with its `_Live` and `_Versions` tables) and lists the class in the `ClassName` columns. See [Why an empty table](#why-an-empty-table-without-userforms).

## Editing a form

Add a **Form** from the type picker inside a column, then open it. Next to the usual grid fields it has userforms' own tabs:

| Tab | What it holds |
|---|---|
| **Form Fields** | The field editor: text fields, dropdowns, checkboxes, steps, field groups, conditional display rules |
| **Configuration** | Submit button text, the on-complete message, and the grid's **After submitting, go to** option |
| **Recipients** | Email recipients, each with its own conditions and template |
| **Submissions** | Every submission received, with a **Page** column showing where it was sent from |

**After submitting, go to** picks a page to send the visitor to once the form is accepted. Leave it empty and the visitor stays on the page they were on, with the on-complete message shown in place of the form. If the chosen page is later deleted, the form falls back to the on-complete message.

The editor card shows how many input fields the form has (*4 fields*). Steps, field groups and headings hidden from reports are not counted.

## Reusing a form on several pages

Put the form in a shared block and place that block wherever the form is needed. Every placement is the same form: the same fields, the same recipients, one list of submissions.

Each submission records the page it was submitted from. The **Submissions** tab shows it in the **Page** column, and recipient email templates can use it:

```silverstripe
Sent from: $SubmittedForm.HostPage.Title ($SubmittedForm.HostPage.AbsoluteLink)
```

The emails name the page even when the form does not store submissions (**Disable Saving Submissions to Server**).

Visitors who are not logged in can use a shared form. Content inside a shared block is viewable by anyone who may view a page that places the block, while the block record in the library stays limited to CMS users.

## What the visitor sees

The form posts to a route under the page it sits on, so the visitor never leaves that page's URL space:

| Step | URL |
|---|---|
| The page with the form | `/contact` |
| The form posts to | `/contact/grid-form/{id}/Form` |
| After a valid submission | `/contact/grid-form/{id}/finished#uff`, showing the contact page with the on-complete message in place of the form |
| After an invalid submission | back to `/contact`, with the errors and the visitor's input kept |

Only the submitted form changes to its on-complete message. Any other form on the same page still shows its fields.

Reloading the `finished` URL, or opening it directly, sends the visitor back to the page and records nothing.

The route only serves forms that belong to the page: a form in the page's own grid, or one inside a shared block the page places. Any other ID returns 404.

## Templates

The element renders through `templates/WeDevelop/Grid/UserForms/UserFormElement.ss`: the optional element title, then the form. Override it at the same path in your theme.

The fields themselves come from userforms' own templates, so style or override them as you would for a userforms page. To stop userforms loading its default CSS or JavaScript, set its config on the element class:

```yaml
WeDevelop\Grid\UserForms\UserFormElement:
  block_default_userforms_css: true
  block_default_userforms_js: true
```

## Fluent

Copying a page to another locale copies its forms with it, fields and recipients included. From then on each locale has its own form: changing fields in one locale does not touch the other. Submissions are never copied; each stays with the locale's form it was sent to.

## Known limitations

- **Archiving a form leaves its submissions behind.** Archiving a Form element, or deleting or unsharing the shared block that holds it, leaves its submissions attached to the archived form, where the CMS can no longer reach them. Export the submissions first. Deleting a userforms page behaves the same way.
- **A child page called `grid-form` hides the route.** If a page has a child page whose URL segment is `grid-form`, that child is served instead of the form route on the parent page. Rename the child.
- **The userforms page type stays available.** Installing userforms also adds its *User Defined Form* page type. Hiding it is a project decision. For example:

  ```yaml
  SilverStripe\UserForms\Model\UserDefinedForm:
    can_be_root: false
  ```

  Then leave it out of `allowed_children` on your own page types.

## Why an empty table without userforms

SilverStripe's class manifest reads source files without loading them, so it lists the Form element even where userforms is missing. Part of `db:build` and all of `db:defaults` then instantiate every listed data class without checking that it exists, and an undeclared class makes both commands fail ([silverstripe/silverstripe-framework#12030](https://github.com/silverstripe/silverstripe-framework/issues/12030)).

So when userforms is missing, the element's file declares an inert stand-in under the same name instead. It is never offered or creatable and renders nothing, but as a data class it gets its (empty) table. With userforms installed the real element is declared and none of this applies. The stand-in will be removed once the framework fix is released.

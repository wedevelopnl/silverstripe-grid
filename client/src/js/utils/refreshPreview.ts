/**
 * Refresh the CMS preview pane after a grid mutation.
 *
 * SilverStripe's split-mode preview normally refreshes on form submission
 * via the `aftersubmitform` jQuery event. Grid mutations bypass the form
 * — they go through the REST API — so we trigger the event manually.
 *
 * The event fires `_initialiseFromContent()` on the `.cms-preview` entwine
 * widget, which handles mode detection, navigator state, and iframe URL
 * resolution — safer than reloading the iframe directly.
 *
 * A dummy `xhr` satisfies the menu handler's `getResponseHeader()` call;
 * all other handlers ignore the event data.
 */
export function refreshPreview(): void {
  const form = window.jQuery?.('.cms-edit-form')
  if (!form || form.length === 0) {
    return
  }

  form.trigger('aftersubmitform', {
    xhr: { getResponseHeader: () => null },
  })
}

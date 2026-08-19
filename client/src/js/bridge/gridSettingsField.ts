export {}

// Guard: the module may be imported in contexts (tests, SSR, early boot)
// where jQuery/entwine is not yet present. Mirrors the pattern used in
// ./entwine.ts so the file is safely loadable without a live CMS global.
if (typeof window !== 'undefined' && window.jQuery?.entwine !== undefined) {
  window.jQuery.entwine('ss', ($) => {
    $('.ssgrid-grid-settings-field .ssgrid-grid-settings-field-override-toggle').entwine({
      onchange() {
        const row = $(this).closest('tr')
        const enabled = $(this).is(':checked')
        row
          .find('select, input')
          .not('.ssgrid-grid-settings-field-override-toggle')
          .prop('disabled', !enabled)
        // `data-overridden` is a shared contract with grid-settings-field.css.
        if (enabled) {
          row.attr('data-overridden', '')
        } else {
          row.removeAttr('data-overridden')
        }
      },
    })
  })
}

export {}

// Guard: the module may be imported in contexts (tests, SSR, early boot)
// where jQuery/entwine is not yet present. Mirrors the pattern used in
// ./entwine.ts so the file is safely loadable without a live CMS global.
if (typeof window !== 'undefined' && window.jQuery?.entwine !== undefined) {
  window.jQuery.entwine('ss', ($) => {
    $('.ssgrid-grid-settings-field .ssgrid-grid-settings-field__override-toggle').entwine({
      onchange() {
        const row = $(this).closest('tr')
        const enabled = $(this).is(':checked')
        row
          .find('select, input')
          .not('.ssgrid-grid-settings-field__override-toggle')
          .prop('disabled', !enabled)
        row.toggleClass('is-overridden', enabled)
      },
    })
  })
}

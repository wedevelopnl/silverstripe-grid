export {};

window.jQuery.entwine('ss', ($) => {
  $('.grid-settings-field .grid-settings-field__override-toggle').entwine({
    onchange() {
      const row = $(this).closest('tr');
      const enabled = $(this).is(':checked');
      row
        .find('select, input')
        .not('.grid-settings-field__override-toggle')
        .prop('disabled', !enabled);
      row.toggleClass('is-overridden', enabled);
    },
  });
});

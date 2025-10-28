(function ($, Drupal, drupalSettings) {

  Drupal.behaviors.pdsSliderAdminConfirm = {
    attach: function (context) {

      $(context)
        .find('input[name="pds_recipe_slider_banner_remove_selected"]')
        .once('pds-slider-admin-confirm')
        .on('click', function (e) {

          var checkedRows = [];
          $('#pds-slider-form table tbody tr', context).each(function (rowIndex) {
            var $row = $(this);
            var $cb = $row.find('input[type="checkbox"][name$="[remove]"]');
            if ($cb.is(':checked')) {
              var labelVal = $row.find('textarea[name$="[intro]"]').val() || ('row ' + rowIndex);
              checkedRows.push(labelVal);
            }
          });

          var msg;
          if (checkedRows.length === 0) {
            msg = 'No slides are checked. Continue?';
          } else {
            msg = 'Are you sure you want to delete these slides:\n' +
              checkedRows.join(', ') +
              ' ?';
          }

          if (!window.confirm(msg)) {
            e.preventDefault();
            e.stopPropagation();
            return false;
          }
        });
    }
  };

})(jQuery, Drupal, drupalSettings);

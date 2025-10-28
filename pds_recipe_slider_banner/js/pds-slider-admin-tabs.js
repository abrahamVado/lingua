(function ($, Drupal) {
  Drupal.behaviors.pdsSliderAdminTabs = {
    attach: function (context) {
      // Run once per wrapper.
      $('.pds-slider-admin-wrapper', context).once('pds-slider-admin-tabs').each(function () {
        var $wrapper = $(this);
        var $buttons = $wrapper.find('.pds-slider-admin-tabs-nav button');
        var $panels = $wrapper.find('.pds-slider-admin-tabpanel');

        function activateTab(tabId) {
          // deactivate all
          $buttons.removeClass('pds-active').attr('aria-selected', 'false');
          $panels.removeClass('pds-active').attr('hidden', true);

          // activate target
          $buttons.filter('[data-target="' + tabId + '"]')
            .addClass('pds-active')
            .attr('aria-selected', 'true');

          $panels.filter('#' + tabId)
            .addClass('pds-active')
            .attr('hidden', false);
        }

        // click handlers
        $buttons.on('click', function (e) {
          e.preventDefault();
          var tabId = $(this).data('target');
          activateTab(tabId);
        });

        // default: activate first tab if none active
        var $initial = $buttons.filter('.pds-active').first();
        if ($initial.length === 0) {
          $initial = $buttons.first();
        }
        if ($initial.length) {
          activateTab($initial.data('target'));
        }
      });
    }
  };
})(jQuery, Drupal);

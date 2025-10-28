(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.pdsSliderBanner = {
    attach: function (context) {
      // Find the hero slider wrapper.
      var $hero = $('#principalHero', context);

      // Bail if not present or Slick not loaded.
      if (!$hero.length || typeof $.fn.slick !== 'function') {
        return;
      }

      // Prevent double init on the same DOM.
      if ($hero.hasClass('pds-slider-banner--initialized')) {
        return;
      }
      $hero.addClass('pds-slider-banner--initialized');

      // Read runtime config from drupalSettings passed in build().
      var opts = (drupalSettings && drupalSettings.pdsSliderBanner) || {};
      var autoplay       = !!opts.autoplay;
      var autoplaySpeed  = parseInt(opts.autoplaySpeed || 6000, 10);
      var pauseOnHover   = !!opts.pauseOnHover;

      // Init Slick.
      $hero.slick({
        arrows: true,
        prevArrow: $('.principal_hero__arrow--prev', context),
        nextArrow: $('.principal_hero__arrow--next', context),
        dots: true,
        autoplay: autoplay,
        autoplaySpeed: autoplaySpeed,
        pauseOnHover: pauseOnHover,
        speed: 500,
        adaptiveHeight: false,
        accessibility: true
      });

      // Keyboard support on arrows and dots.
      $('.principal_hero__arrow, .slick-dots button', context)
        .attr('tabindex', '0')
        .on('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            $(this).trigger('click');
          }
        });
    }
  };
})(jQuery, Drupal, drupalSettings);

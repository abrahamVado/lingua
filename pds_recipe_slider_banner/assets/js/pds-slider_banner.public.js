/**
 * PDS Hero behavior
 * Requires: core/jquery, core/once, slick-carousel
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.pdsHero = {
    attach(context) {
      const $sliders = $(once('pds-hero', '#principalHero', context));
      if (!$sliders.length) return;

      const $prev = $(context).find('.principal_hero__arrow--prev');
      const $next = $(context).find('.principal_hero__arrow--next');

      // Initialize Slick
      $sliders.slick({
        arrows: true,
        prevArrow: $prev,
        nextArrow: $next,
        dots: true,
        autoplay: true,
        autoplaySpeed: 6000,
        speed: 500,
        pauseOnHover: true,
        adaptiveHeight: false,
        accessibility: true
      });

      // A11y: keyboard on custom controls and dots
      $(context)
        .find('.principal_hero__arrow, .slick-dots button')
        .attr('tabindex', '0')
        .on('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') $(this).trigger('click');
        });
    }
  };
})(jQuery, Drupal, once);

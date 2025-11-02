/**
 * Optional behavior: card click tracking.
 */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.pdsFondos = {
    attach(context) {
      //1.- Identify the card wrappers within the provided context exactly once.
      const cards = once('pds-fondos-card', '.principal-fondo', context);

      //2.- Attach a click handler to each card so future analytics wiring is centralized.
      cards.forEach((card) => {
        card.addEventListener('click', () => {
          //3.- Capture the fondo title; replace the stub with your analytics integration.
          const t = card.querySelector('.principal-fondo-description-area h3')?.textContent?.trim() || '';
          // console.debug('Fondo clicked:', t);
        });
      });
    }
  };
})(Drupal, once);

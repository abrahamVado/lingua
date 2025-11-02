/**
 * Optional behavior: card click tracking.
 */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.pdsFondos = {
    attach(context) {
      const cards = once('pds-fondos-card', '.principal-fondo', context);
      cards.forEach((card) => {
        card.addEventListener('click', () => {
          const t = card.querySelector('.principal-fondo-description-area h3')?.textContent?.trim() || '';
          // Place analytics call if needed.
          // console.debug('Fondo clicked:', t);
        });
      });
    }
  };
})(Drupal, once);

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.pdsAvisosNormativosPublic = {
    attach(context) {
      //1.- Locate each normative card once so repeated AJAX attachments do not duplicate listeners.
      once('pds-avisos-normativos-card', '.principal-avisos .normativa-column', context).forEach((card) => {
        const link = card.querySelector('.button-area a');
        //2.- Skip wiring events when the card does not expose a CTA link.
        if (!link) {
          return;
        }

        //3.- Make the entire card focusable so keyboard users can activate it easily.
        if (!card.hasAttribute('tabindex')) {
          card.setAttribute('tabindex', '0');
        }

        const navigate = () => {
          //4.- Trigger the anchor click programmatically to reuse Drupal's link handling.
          link.click();
        };

        //5.- Activate the link when the card is clicked outside of the existing button area.
        card.addEventListener('click', (event) => {
          if (event.defaultPrevented) {
            return;
          }
          const target = event.target;
          if (target && link.contains(target)) {
            return;
          }
          navigate();
        });

        //6.- Allow Enter or Space to activate the link for accessibility compliance.
        card.addEventListener('keydown', (event) => {
          if (event.defaultPrevented) {
            return;
          }
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            navigate();
          }
        });
      });
    },
  };
})(Drupal, once);

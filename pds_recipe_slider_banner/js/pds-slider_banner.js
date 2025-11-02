/**
 * Behavior: principal slider_banner modal
 * Depends: core/drupal, core/once
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.pdsSliderBanner = {
    attach(context) {
      const grids = once('pds-slider_banner-grid', '.principal-slider_banner__grid', context);
      if (!grids.length) return;

      const modal = document.getElementById('executive-modal');
      if (!modal) return;

      const els = {
        photo: modal.querySelector('.principal-modal__photo'),
        name: modal.querySelector('.principal-modal__name'),
        title: modal.querySelector('.principal-modal__title'),
        cv: modal.querySelector('.principal-modal__cv'),
        linkedin: modal.querySelector('.principal-modal__linkedin'),
        closeBtn: modal.querySelector('.principal-modal__close')
      };
      let lastFocused = null;

      function openModal() {
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('is-open');
        document.documentElement.classList.add('u-noscroll');
        if (els.closeBtn) els.closeBtn.focus();
        document.addEventListener('keydown', onKeyDown);
      }
      function closeModal() {
        modal.setAttribute('aria-hidden', 'true');
        modal.classList.remove('is-open');
        document.documentElement.classList.remove('u-noscroll');
        document.removeEventListener('keydown', onKeyDown);
        try { if (lastFocused) lastFocused.focus(); } catch (e) {}
        // Cleanup
        if (els.photo) { els.photo.removeAttribute('src'); els.photo.removeAttribute('alt'); }
        if (els.name) els.name.textContent = '';
        if (els.title) els.title.textContent = '';
        if (els.cv) els.cv.innerHTML = '';
        if (els.linkedin) els.linkedin.href = '#';
      }
      function onKeyDown(e) {
        if (e.key === 'Escape') closeModal();
      }

      // Delegate clicks per grid
      grids.forEach((grid) => {
        grid.addEventListener('click', function (e) {
          const btn = e.target.closest('.principal-slider_banner__card-btn');
          if (!btn) return;
          lastFocused = btn;

          const card = btn.closest('.principal-slider_banner__card');
          if (!card) return;

          const photo = card.getAttribute('data-photo') || '';
          const name  = card.getAttribute('data-name') || (card.querySelector('.principal-slider_banner__name')?.textContent.trim() || '');
          const title = card.getAttribute('data-title') || '';
          const cvHTML= card.querySelector('.principal-slider_banner__cv')?.innerHTML || '';
          const lk    = card.getAttribute('data-linkedin') || '#';

          if (photo && els.photo) els.photo.src = photo;
          if (els.photo) els.photo.alt = name ? ('Foto de ' + name) : 'Foto';
          if (els.name) els.name.textContent = name;
          if (els.title) els.title.textContent = title;
          if (els.cv) els.cv.innerHTML = cvHTML;
          if (els.linkedin) els.linkedin.href = lk;

          openModal();
        });
      });

      // Close by overlay or button
      modal.addEventListener('click', function (e) {
        if (e.target.hasAttribute('data-close')) closeModal();
      });
    }
  };
})(Drupal, once);

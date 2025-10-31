(function () {
  const grid = document.querySelector('.principal-executives__grid');
  const modal = document.getElementById('executive-modal');

  if (!grid || !modal) {
    return;
  }

  const els = {
    photo: modal.querySelector('.principal-modal__photo'),
    name: modal.querySelector('.principal-modal__name'),
    title: modal.querySelector('.principal-modal__title'),
    cv: modal.querySelector('.principal-modal__cv'),
    linkedin: modal.querySelector('.principal-modal__linkedin'),
    closeBtn: modal.querySelector('.principal-modal__close')
  };

  let lastFocused = null;

  function onKeyDown(e) {
    if (e.key === 'Escape') {
      closeModal();
    }
  }

  function openModal() {
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('is-open');
    document.documentElement.classList.add('u-noscroll');
    if (els.closeBtn) {
      els.closeBtn.focus();
    }
    document.addEventListener('keydown', onKeyDown);
  }

  function closeModal() {
    modal.setAttribute('aria-hidden', 'true');
    modal.classList.remove('is-open');
    document.documentElement.classList.remove('u-noscroll');
    document.removeEventListener('keydown', onKeyDown);

    if (lastFocused) {
      try {
        lastFocused.focus();
      } catch (e) {}
    }

    // cleanup
    if (els.photo) {
      els.photo.removeAttribute('src');
      els.photo.removeAttribute('alt');
    }
    if (els.name) {
      els.name.textContent = '';
    }
    if (els.title) {
      els.title.textContent = '';
    }
    if (els.cv) {
      els.cv.innerHTML = '';
    }
    if (els.linkedin) {
      els.linkedin.href = '#';
    }
  }

  // click handler for cards
  grid.addEventListener('click', function (e) {
    const cardBtn = e.target.closest('.principal-executives__card-btn');
    if (!cardBtn) {
      return;
    }

    lastFocused = cardBtn;
    const card = cardBtn.closest('.principal-executives__card');
    if (!card) {
      return;
    }

    const photo    = card.getAttribute('data-photo') || '';
    const name     = card.getAttribute('data-name') ||
                     (card.querySelector('.principal-executives__name') ?
                      card.querySelector('.principal-executives__name').textContent.trim() :
                      '');
    const title    = card.getAttribute('data-title') || '';
    const cvHTML   = card.querySelector('.principal-executives__cv')
                      ? card.querySelector('.principal-executives__cv').innerHTML
                      : '';
    const linkedin = card.getAttribute('data-linkedin') || '#';

    if (photo && els.photo) {
      els.photo.src = photo;
    }
    if (els.photo) {
      els.photo.alt = name ? ('Foto de ' + name) : 'Foto';
    }
    if (els.name) {
      els.name.textContent = name;
    }
    if (els.title) {
      els.title.textContent = title;
    }
    if (els.cv) {
      els.cv.innerHTML = cvHTML;
    }
    if (els.linkedin) {
      els.linkedin.href = linkedin;
    }

    openModal();
  });

  // close modal on overlay or X button
  modal.addEventListener('click', function (e) {
    if (e.target.hasAttribute('data-close')) {
      closeModal();
    }
  });
})();

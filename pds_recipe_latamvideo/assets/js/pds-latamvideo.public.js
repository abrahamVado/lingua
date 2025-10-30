(() => {
  //1.- Iterate over every LATAM video callout rendered on the page.
  document.querySelectorAll('[data-pds-latamvideo-root]').forEach((component) => {
    //2.- Resolve the dedicated modal inside the component so multiple instances stay isolated.
    const modal = component.querySelector('[data-pds-latamvideo-modal]');
    if (!modal) {
      return;
    }

    //3.- Fetch the container where the iframe must be injected dynamically for playback.
    const modalBody = modal.querySelector('.pds-modal-body');
    if (!modalBody) {
      return;
    }

    //4.- Attach handlers only when play triggers exist to avoid unnecessary listeners.
    const openButtons = component.querySelectorAll('.pds-modal-open');
    if (openButtons.length === 0) {
      return;
    }

    //5.- Helper that mounts the iframe with autoplay enabled and prevents scrolling behind the modal.
    const injectVideo = (videoUrl) => {
      if (typeof videoUrl !== 'string' || videoUrl.trim() === '') {
        return;
      }
      const normalized = videoUrl.includes('?') ? `${videoUrl}&autoplay=1` : `${videoUrl}?autoplay=1`;
      modalBody.innerHTML = `<iframe src="${normalized}" title="YouTube" frameborder="0"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
        allowfullscreen></iframe>`;
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    };

    //6.- Reset the modal and restore scrolling when the user dismisses the overlay.
    const closeModal = () => {
      modal.setAttribute('aria-hidden', 'true');
      modalBody.innerHTML = '';
      document.body.style.overflow = '';
    };

    //7.- Link every trigger button with the modal so each callout can load its own video URL.
    openButtons.forEach((button) => {
      button.addEventListener('click', () => injectVideo(button.dataset.video));
    });

    //8.- Allow backdrop interactions to close the modal seamlessly.
    modal.addEventListener('click', (event) => {
      if (event.target.dataset.close === 'true' || event.target === modal) {
        closeModal();
      }
    });

    //9.- Support the Escape key to improve accessibility and keyboard navigation.
    document.addEventListener('keydown', (event) => {
      if (modal.getAttribute('aria-hidden') === 'false' && event.key === 'Escape') {
        closeModal();
      }
    });
  });
})();

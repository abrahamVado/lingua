/**
 * Modal that injects the embed code from a <template>.
 * Deps: core/drupal, core/once
 */
(function (Drupal, once) {
  'use strict';

  function init(root) {
    //1.- Localizamos los nodos clave para abrir el modal y clonar el embed.
    const openBtn = root.querySelector('.pds-modal-open');
    const template = root.querySelector('.pds-embed-template');
    const modal = root.querySelector('[data-pds-modal]');
    const body = root.querySelector('[data-pds-modal-body]');
    if (!openBtn || !template || !modal || !body) return;

    function open() {
      //2.- Clonamos el template del iframe y lo inyectamos en el modal activo.
      body.innerHTML = '';
      const node = template.content ? template.content.cloneNode(true) : null;
      if (node) body.appendChild(node);
      modal.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('pds-noscroll');
      document.body.style.overflow = 'hidden';
      const closeBtn = modal.querySelector('.pds-modal-close');
      if (closeBtn) closeBtn.focus();
    }

    function close() {
      //3.- Cerramos el modal y limpiamos el contenido para detener la reproducción.
      modal.setAttribute('aria-hidden', 'true');
      body.innerHTML = '';            // remove embed to stop playback
      document.body.style.overflow = '';
      document.documentElement.classList.remove('pds-noscroll');
    }

    openBtn.addEventListener('click', open);
    modal.addEventListener('click', (e) => {
      if (e.target.dataset.close === 'true' || e.target === modal) close();
    });
    document.addEventListener('keydown', (e) => {
      if (modal.getAttribute('aria-hidden') === 'false' && e.key === 'Escape') close();
    });
  }

  Drupal.behaviors.pdsEmbedCallout = {
    attach(context) {
      const roots = once('pds-embed-callout', '.pds-embedCallout', context);
      roots.forEach(init);
    }
  };
})(Drupal, once);

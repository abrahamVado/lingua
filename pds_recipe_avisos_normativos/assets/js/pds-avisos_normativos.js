(function (Drupal, once) {
  Drupal.behaviors.pdsAvisosNormativos = {
    attach(context) {
      // Make the whole card clickable if it contains a single link.
      once('pds-avisos_normativos-click', '.principal-avisos_normativos .normativa-column', context).forEach((card) => {
        const link = card.querySelector('.button-area a[href]');
        if (!link) return;
        card.style.userSelect = 'none';
        card.addEventListener('click', (e) => {
          // Avoid double-activating when clicking the actual link
          if (e.target instanceof HTMLElement && e.target.closest('a')) return;
          link.click();
        });
        card.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); link.click(); }
        });
        card.setAttribute('tabindex', '0');
        card.setAttribute('role', 'link');
        card.setAttribute('aria-label', link.textContent || 'Abrir');
      });
    },
  };
})(Drupal, once);

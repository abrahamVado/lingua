/**
 * PDS Insights listing interactions: search, themes, pagination.
 * Works with Twig: templates/insights/insights.html.twig
 */
(function (Drupal, once) {
  "use strict";

  Drupal.behaviors.pdsInsights = {
    attach(context) {
      // Bind once per section
      once('pds-insights', '.principal-insights', context).forEach((section) => {
        const qInput      = section.querySelector('.search-input');
        const themeBtns   = Array.from(section.querySelectorAll('.theme-tag'));
        const cards       = () => Array.from(section.querySelectorAll('.insight-card'));
        const totalEl     = section.querySelector('[data-total]');
        const entriesSel  = section.querySelector('.entries-select');

        // Pagination controls
        const pageNumsBtn = () => Array.from(section.querySelectorAll('.page-number'));
        const btnFirst    = section.querySelector('.page-first');
        const btnPrev     = section.querySelector('.page-prev');
        const btnNext     = section.querySelector('.page-next');
        const btnLast     = section.querySelector('.page-last');

        // --- Filters ---
        const activeThemes = () =>
          themeBtns.filter(b => b.classList.contains('active'))
                   .map(b => (b.dataset.theme || '').toLowerCase());

        function passesFilters(card) {
          const term  = (qInput?.value || '').trim().toLowerCase();
          const title = (card.querySelector('.insight-card-title')?.textContent || '').toLowerCase();
          const sum   = (card.querySelector('.insight-summary')?.textContent || '').toLowerCase();
          const theme = (card.getAttribute('data-theme') ||
                        card.querySelector('.insight-badge')?.textContent || '').toLowerCase();

          const matchesSearch = !term || title.includes(term) || sum.includes(term);
          const themes = activeThemes();
          const matchesTheme  = themes.length === 0 || themes.includes(theme);
          return matchesSearch && matchesTheme;
        }

        function applyFilters() {
          let visible = 0;
          cards().forEach(card => {
            const ok = passesFilters(card);
            card.style.display = ok ? 'flex' : 'none';
            if (ok) visible++;
          });
          if (totalEl) {
            const total = cards().length;
            totalEl.textContent = `${visible} of ${total} results`;
          }
          updatePagerButtons();
        }

        // --- Events: themes, search, reset, entries ---
        themeBtns.forEach(btn => {
          btn.addEventListener('click', () => {
            btn.classList.toggle('active');
            applyFilters();
          });
        });

        qInput?.addEventListener('input', applyFilters);

        const resetBtn = section.querySelector('.reset-button');
        resetBtn?.addEventListener('click', () => {
          if (qInput) qInput.value = '';
          themeBtns.forEach(b => b.classList.toggle('active', b.dataset.theme === 'global'));
          // Reset to first page if paged
          const first = pageNumsBtn()[0];
          first && first.click();
          applyFilters();
        });

        entriesSel?.addEventListener('change', () => {
          // Hook for server-backed paging size if needed
          applyFilters();
        });

        // --- Pagination (client-side, cosmetic) ---
        function activePageIndex() {
          const active = section.querySelector('.page-number.active');
          return pageNumsBtn().indexOf(active);
        }

        function setActivePage(idx) {
          const pages = pageNumsBtn();
          pages.forEach(p => p.classList.remove('active'));
          if (pages[idx]) pages[idx].classList.add('active');
          updatePagerButtons();
          // Hook: load that page from server if wired. For static demo we just filter.
          applyFilters();
        }

        function updatePagerButtons() {
          const pages = pageNumsBtn();
          const i = activePageIndex();
          if (btnPrev) btnPrev.disabled = i <= 0;
          if (btnNext) btnNext.disabled = i === -1 || i >= pages.length - 1;
        }

        pageNumsBtn().forEach((b, i) => {
          b.addEventListener('click', () => setActivePage(i));
        });

        btnFirst?.addEventListener('click', () => setActivePage(0));
        btnLast?.addEventListener('click', () => setActivePage(pageNumsBtn().length - 1));
        btnPrev?.addEventListener('click', () => {
          const i = activePageIndex();
          if (i > 0) setActivePage(i - 1);
        });
        btnNext?.addEventListener('click', () => {
          const i = activePageIndex();
          if (i < pageNumsBtn().length - 1) setActivePage(i + 1);
        });

        // Init
        updatePagerButtons();
        applyFilters();
      });
    }
  };

})(Drupal, once);

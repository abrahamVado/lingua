/**
 * PDS Insights listing interactions: search, themes, pagination.
 * Works with Twig: templates/pds-insights_search.html.twig
 */
(function (Drupal, once, drupalSettings) {
  "use strict";

  Drupal.behaviors.pdsInsights = {
    attach(context) {
      //1.- Bind once per insights section, capture shared DOM references, and read Drupal settings.
      once('pds-insights', '.principal-insights', context).forEach((section) => {
        const componentId = section.getAttribute('data-pds-insights-search-id') || '';
        const registry = drupalSettings.pdsInsightsSearch || {};
        const settings = registry[componentId] || {};

        const qInput = section.querySelector('.search-input');
        const searchButton = section.querySelector('.search-button');
        const resetButton = section.querySelector('.reset-button');
        const themeBtns = Array.from(section.querySelectorAll('.theme-tag'));
        const cardsContainer = section.querySelector('.insights-cards');
        const totalEl = section.querySelector('[data-total]');
        const entriesSel = section.querySelector('.entries-select');
        const pageNumbers = section.querySelector('.page-numbers');
        const btnFirst = section.querySelector('.page-first');
        const btnPrev = section.querySelector('.page-prev');
        const btnNext = section.querySelector('.page-next');
        const btnLast = section.querySelector('.page-last');

        if (!cardsContainer) {
          return;
        }

        const emptyMessage = cardsContainer.dataset.emptyMessage || Drupal.t('No results found.');
        const iconsPath = cardsContainer.dataset.iconsPath || '/icons';
        const defaultThemes = themeBtns
          .filter((btn) => btn.classList.contains('active'))
          .map((btn) => (btn.dataset.theme || '').toLowerCase());

        const searchUrl = typeof settings.searchUrl === 'string' ? settings.searchUrl : '';
        const defaultLinkText = typeof settings.linkText === 'string' && settings.linkText
          ? settings.linkText
          : Drupal.t('Get our perspective');
        const rawInitialItems = Array.isArray(settings.initialItems) ? settings.initialItems : [];

        //2.- Define helper utilities used by both rendering and network layers.
        const parseLimit = (value) => {
          const parsed = parseInt(value, 10);
          return Number.isFinite(parsed) && parsed > 0 ? parsed : 10;
        };

        const normalizeItem = (item) => {
          const theme = item && typeof item === 'object' ? item.theme || {} : {};
          const themeId = item?.theme_id ?? theme.id ?? '';
          const themeLabel = item?.theme_label ?? theme.label ?? '';

          return {
            id: typeof item?.id === 'number' || typeof item?.id === 'string' ? item.id : null,
            theme_id: typeof themeId === 'string' || typeof themeId === 'number' ? String(themeId).toLowerCase() : '',
            theme_label: typeof themeLabel === 'string' ? themeLabel : '',
            title: typeof item?.title === 'string' ? item.title : '',
            summary: typeof item?.summary === 'string' ? item.summary : '',
            author: typeof item?.author === 'string' ? item.author : '',
            read_time: typeof item?.read_time === 'string' ? item.read_time : '',
            url: typeof item?.url === 'string' && item.url !== '' ? item.url : '#',
            link_text: typeof item?.link_text === 'string' && item.link_text !== '' ? item.link_text : defaultLinkText,
          };
        };

        const makeDebounce = (fn, delay) => {
          let timer = null;
          const debounced = (...args) => {
            if (timer) {
              clearTimeout(timer);
            }
            timer = setTimeout(() => {
              timer = null;
              fn(...args);
            }, delay);
          };
          debounced.cancel = () => {
            if (timer) {
              clearTimeout(timer);
              timer = null;
            }
          };
          return debounced;
        };

        const getActiveThemes = () => themeBtns
          .filter((btn) => btn.classList.contains('active'))
          .map((btn) => (btn.dataset.theme || '').toLowerCase());

        const filterByTheme = (items) => {
          const active = getActiveThemes();
          if (!active.length) {
            return items.slice();
          }
          return items.filter((item) => active.includes((item.theme_id || '').toLowerCase()));
        };

        const initialLimit = entriesSel ? parseLimit(entriesSel.value) : (rawInitialItems.length || 10);
        const normalizedInitial = rawInitialItems.map(normalizeItem);

        const state = {
          componentId,
          initialItems: normalizedInitial,
          results: normalizedInitial.slice(),
          query: '',
          limit: initialLimit,
          page: 0,
          meta: {
            total: normalizedInitial.length,
            pages: Math.max(1, Math.ceil((normalizedInitial.length || 1) / Math.max(initialLimit, 1))),
            page: 0,
          },
        };

        let requestToken = 0;
        const debouncedSearch = makeDebounce((value) => {
          state.page = 0;
          performSearch(value, 0);
        }, 300);

        //3.- Rendering helpers that rebuild the cards list, totals, and pagination controls.
        function renderCards(items) {
          cardsContainer.innerHTML = '';
          if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'insights-empty';
            empty.textContent = emptyMessage;
            cardsContainer.appendChild(empty);
            return;
          }

          items.forEach((item) => {
            const card = document.createElement('div');
            card.className = 'insight-card';
            if (item.theme_id) {
              card.setAttribute('data-theme', item.theme_id);
            }
            else {
              card.removeAttribute('data-theme');
            }

            if (item.theme_label) {
              const badge = document.createElement('span');
              badge.className = `insight-badge ${item.theme_id}`.trim();
              badge.textContent = item.theme_label;
              card.appendChild(badge);
            }

            const title = document.createElement('h3');
            title.className = 'insight-card-title';
            title.textContent = item.title;
            card.appendChild(title);

            if (item.read_time) {
              const meta = document.createElement('div');
              meta.className = 'insight-meta';
              const time = document.createElement('span');
              time.className = 'insight-time';
              time.textContent = `🕒 ${item.read_time}`;
              meta.appendChild(time);
              card.appendChild(meta);
            }

            if (item.summary) {
              const summary = document.createElement('p');
              summary.className = 'insight-summary';
              summary.textContent = item.summary;
              card.appendChild(summary);
            }

            if (item.author) {
              const author = document.createElement('div');
              author.className = 'insight-author';
              const label = document.createElement('span');
              label.textContent = Drupal.t('By');
              author.appendChild(label);
              author.append(` ${item.author}`);
              card.appendChild(author);
            }

            const buttonArea = document.createElement('div');
            buttonArea.className = 'button-area';
            const link = document.createElement('a');
            link.className = 'principal-link principal-link--subtle';
            link.href = item.url || '#';
            link.textContent = item.link_text || defaultLinkText;
            buttonArea.appendChild(link);

            const imgArea = document.createElement('div');
            imgArea.className = 'img-area';
            const arrow = document.createElement('img');
            arrow.src = `${iconsPath}/arrow-right.svg`;
            arrow.alt = '';
            arrow.setAttribute('aria-hidden', 'true');
            imgArea.appendChild(arrow);
            buttonArea.appendChild(imgArea);

            card.appendChild(buttonArea);
            cardsContainer.appendChild(card);
          });
        }

        function renderTotal(visible, total) {
          if (!totalEl) {
            return;
          }
          if (total <= 0) {
            totalEl.textContent = Drupal.t('0 total results');
            return;
          }
          if (visible === total) {
            totalEl.textContent = Drupal.formatPlural(total, '1 total result', '@count total results');
            return;
          }
          totalEl.textContent = Drupal.t('@visible of @total results', {
            '@visible': visible,
            '@total': total,
          });
        }

        function renderPagination(totalPages, currentPage, remote) {
          if (!pageNumbers) {
            return;
          }

          pageNumbers.innerHTML = '';
          const pages = Math.max(1, totalPages);
          const current = Math.min(Math.max(currentPage, 0), pages - 1);
          const maxButtons = 5;
          let start = Math.max(0, current - Math.floor(maxButtons / 2));
          let end = Math.min(pages, start + maxButtons);
          if (end - start < maxButtons) {
            start = Math.max(0, end - maxButtons);
          }

          for (let i = start; i < end; i += 1) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `page-number${i === current ? ' active' : ''}`;
            btn.textContent = String(i + 1);
            btn.addEventListener('click', () => {
              goToPage(i, remote);
            });
            pageNumbers.appendChild(btn);
          }

          if (btnPrev) {
            btnPrev.disabled = current <= 0;
            btnPrev.onclick = () => {
              if (current > 0) {
                goToPage(current - 1, remote);
              }
            };
          }
          if (btnFirst) {
            btnFirst.disabled = current <= 0;
            btnFirst.onclick = () => goToPage(0, remote);
          }
          if (btnNext) {
            btnNext.disabled = current >= pages - 1;
            btnNext.onclick = () => {
              if (current < pages - 1) {
                goToPage(current + 1, remote);
              }
            };
          }
          if (btnLast) {
            btnLast.disabled = current >= pages - 1;
            btnLast.onclick = () => goToPage(pages - 1, remote);
          }
        }

        function render() {
          const source = state.query === '' ? state.initialItems : state.results;
          const filtered = filterByTheme(source);
          const remote = state.query !== '';

          if (!remote) {
            const totalFiltered = filtered.length;
            const pages = Math.max(1, Math.ceil(totalFiltered / Math.max(state.limit, 1)));
            if (state.page >= pages) {
              state.page = pages - 1;
            }
            const startIndex = state.page * state.limit;
            const visible = filtered.slice(startIndex, startIndex + state.limit);
            renderCards(visible);
            renderTotal(visible.length, totalFiltered);
            renderPagination(pages, state.page, false);
            return;
          }

          const meta = state.meta || {};
          const totalRemote = typeof meta.total === 'number' ? meta.total : filtered.length;
          const pagesRemote = typeof meta.pages === 'number' ? meta.pages : 1;
          const currentRemote = typeof meta.page === 'number' ? meta.page : 0;

          renderCards(filtered);
          renderTotal(filtered.length, totalRemote);
          renderPagination(pagesRemote, currentRemote, true);
        }

        //4.- Orchestrate server-backed searches with debouncing and race protection.
        function performSearch(term, page = 0) {
          const query = typeof term === 'string' ? term.trim() : '';
          state.query = query;

          if (query === '' || !searchUrl) {
            state.results = state.initialItems.slice();
            state.meta = {
              total: state.initialItems.length,
              pages: Math.max(1, Math.ceil((state.initialItems.length || 1) / Math.max(state.limit, 1))),
              page: 0,
            };
            state.page = 0;
            section.removeAttribute('aria-busy');
            render();
            return;
          }

          let targetUrl;
          try {
            targetUrl = new URL(searchUrl, window.location.origin);
          }
          catch (error) {
            try {
              targetUrl = new URL(searchUrl, window.location.href);
            }
            catch (innerError) {
              targetUrl = null;
            }
          }

          if (!targetUrl) {
            render();
            return;
          }

          targetUrl.searchParams.set('q', query);
          targetUrl.searchParams.set('limit', String(state.limit));
          targetUrl.searchParams.set('page', String(Math.max(0, page)));

          requestToken += 1;
          const currentToken = requestToken;
          section.setAttribute('aria-busy', 'true');

          fetch(targetUrl.toString(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
          })
            .then((response) => {
              if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
              }
              return response.json();
            })
            .then((payload) => {
              if (currentToken !== requestToken) {
                return;
              }
              const items = Array.isArray(payload?.data) ? payload.data : [];
              state.results = items.map(normalizeItem);
              const meta = payload?.meta || {};
              state.meta = {
                total: typeof meta.total === 'number' ? meta.total : state.results.length,
                pages: typeof meta.pages === 'number' ? meta.pages : 1,
                page: typeof meta.page === 'number' ? meta.page : 0,
              };
              state.page = state.meta.page;
              render();
            })
            .catch(() => {
              if (currentToken !== requestToken) {
                return;
              }
              state.results = [];
              state.meta = { total: 0, pages: 1, page: 0 };
              state.page = 0;
              render();
            })
            .finally(() => {
              if (currentToken === requestToken) {
                section.removeAttribute('aria-busy');
              }
            });
        }

        function goToPage(pageIndex, remote) {
          const nextPage = Math.max(0, pageIndex);
          if (remote && state.query !== '') {
            performSearch(state.query, nextPage);
            return;
          }
          state.page = nextPage;
          render();
        }

        //5.- Wire interactive controls: live search, reset, theme toggles, entries, and pagination.
        qInput?.addEventListener('input', (event) => {
          debouncedSearch(event.target.value || '');
        });

        qInput?.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            debouncedSearch.cancel();
            performSearch(qInput.value || '', 0);
          }
        });

        searchButton?.addEventListener('click', (event) => {
          event.preventDefault();
          debouncedSearch.cancel();
          performSearch(qInput?.value || '', 0);
        });

        resetButton?.addEventListener('click', (event) => {
          event.preventDefault();
          debouncedSearch.cancel();
          if (qInput) {
            qInput.value = '';
          }
          themeBtns.forEach((btn) => {
            const theme = (btn.dataset.theme || '').toLowerCase();
            btn.classList.toggle('active', defaultThemes.includes(theme));
          });
          state.page = 0;
          performSearch('', 0);
        });

        themeBtns.forEach((btn) => {
          btn.addEventListener('click', () => {
            btn.classList.toggle('active');
            state.page = 0;
            render();
          });
        });

        entriesSel?.addEventListener('change', (event) => {
          const nextLimit = parseLimit(event.target.value);
          state.limit = nextLimit;
          state.page = 0;
          if (state.query === '') {
            render();
          }
          else {
            debouncedSearch.cancel();
            performSearch(state.query, 0);
          }
        });

        //6.- Render the admin-provided dataset on first load.
        if (entriesSel) {
          entriesSel.value = String(state.limit);
        }
        render();
      });
    },
  };
})(Drupal, once, drupalSettings);

/**
 * PDS Insights listing interactions: search, themes, pagination.
 * Works with Twig: templates/pds-insights_search.html.twig
 *
 * To disable any automatic search or repaint on first load, add:
 *   data-autosearch="0"
 * on the <section class="principal-insights"> wrapper.
 */
(function (Drupal, once, drupalSettings) {
  "use strict";

  Drupal.behaviors.pdsInsights = {
    attach(context) {
      once('pds-insights', '.principal-insights', context).forEach((section) => {
        // --- Flags and settings ------------------------------------------------
        const id = section.getAttribute('data-pds-insights-search-id') || '';
        const settings = (drupalSettings.pdsInsightsSearch || {})[id] || {};
        console.log('PDS Insights Search settings:', settings);
        // If attribute is "0" => manual. Otherwise auto.
        const manual = false;

        // --- DOM ---------------------------------------------------------------
        const qInput   = section.querySelector('.search-input');
        const btnSearch= section.querySelector('.search-button');
        const btnReset = section.querySelector('.reset-button');
        const themeBtns= Array.from(section.querySelectorAll('.theme-tag'));
        const cards    = section.querySelector('.insights-cards');
        const totalEl  = section.querySelector('[data-total]');
        const entries  = section.querySelector('.entries-select');
        const pageNums = section.querySelector('.page-numbers');
        const btnFirst = section.querySelector('.page-first');
        const btnPrev  = section.querySelector('.page-prev');
        const btnNext  = section.querySelector('.page-next');
        const btnLast  = section.querySelector('.page-last');
        const pagination = section.querySelector('.insights-pagination');

        if (!cards) return;

        // --- Consts ------------------------------------------------------------
        const emptyMsg  = cards.dataset.emptyMessage || Drupal.t('No results found.');
        const iconsPath = cards.dataset.iconsPath || '/icons';
        const searchUrl = typeof settings.searchUrl === 'string' ? settings.searchUrl : '';
        const defaultLinkText = settings.linkText || Drupal.t('Get our perspective');
        const displayMode = typeof settings.displayMode === 'string'
          ? settings.displayMode
          : (section.dataset.displayMode || 'featured');
        const featuredMode = displayMode === 'featured';

        // --- Helpers -----------------------------------------------------------
        const parseLimit = (v) => {
          const n = parseInt(v, 10);
          return Number.isFinite(n) && n > 0 ? n : 10;
        };

        //1.- Toggle the pagination visibility while letting callers decide when the controls should stay exposed.
        const setPaginationVisibility = (shouldShow) => {
          if (!pagination) return;
          pagination.classList.toggle('is-hidden', !shouldShow);
          pagination.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
        };

        const debounce = (fn, ms) => {
          let t = null;
          const d = (...args) => { if (t) clearTimeout(t); t = setTimeout(() => { t = null; fn(...args); }, ms); };
          d.cancel = () => { if (t) { clearTimeout(t); t = null; } };
          return d;
        };

        const getThemesActive = () =>
          themeBtns
            .filter(b => b.classList.contains('active'))
            .map(b => (b.dataset.theme || '').toLowerCase());

        const normalize = (item) => {
          const t = item && typeof item === 'object' ? (item.theme || {}) : {};
          const theme_id = (item?.theme_id ?? t.id ?? '').toString().toLowerCase();
          return {
            id: (typeof item?.id === 'number' || typeof item?.id === 'string') ? item.id : null,
            theme_id,
            theme_label: typeof (item?.theme_label ?? t.label) === 'string' ? (item?.theme_label ?? t.label) : '',
            title: typeof item?.title === 'string' ? item.title : '',
            summary: typeof item?.summary === 'string' ? item.summary : '',
            author: typeof item?.author === 'string' ? item.author : '',
            read_time: typeof item?.read_time === 'string' ? item.read_time : '',
            url: (typeof item?.url === 'string' && item.url) ? item.url : '#',
            link_text: (typeof item?.link_text === 'string' && item.link_text) ? item.link_text : defaultLinkText,
          };
        };

        const readSSR = () => {
          const ssr = Array.from(cards.querySelectorAll('.insight-card'));
          return ssr.map((card) => {
            const theme_id = (card.getAttribute('data-theme') || '').toLowerCase();
            const badge    = card.querySelector('.insight-badge');
            const title    = card.querySelector('.insight-card-title');
            const summary  = card.querySelector('.insight-summary');
            const time     = card.querySelector('.insight-time');
            const authorEl = card.querySelector('.insight-author');
            const link     = card.querySelector('.button-area a');

            let author = '';
            if (authorEl) {
              const lab = authorEl.querySelector('span');
              author = authorEl.textContent || '';
              if (lab) author = author.replace(lab.textContent || '', '').trim();
            }

            return normalize({
              theme_id,
              theme_label: badge ? (badge.textContent || '').trim() : '',
              title: title ? (title.textContent || '').trim() : '',
              summary: summary ? (summary.textContent || '').trim() : '',
              read_time: time ? (time.textContent || '').replace('🕒', '').trim() : '',
              author,
              url: link ? (link.getAttribute('href') || '') : '#',
              link_text: link ? (link.textContent || '').trim() : defaultLinkText,
            });
          });
        };

        const parseList = (attr) => {
          if (!attr) return [];
          try { const v = JSON.parse(attr); return Array.isArray(v) ? v : []; } catch { return []; }
        };

        // Prefer settings.initialItems -> settings.featuredItems -> data-featured-items -> SSR
        const datasetFeatured = parseList(cards.dataset.featuredItems);
        const ssrItems = readSSR();
        const featuredRaw = Array.isArray(settings.featuredItems) && settings.featuredItems.length
          ? settings.featuredItems
          : (datasetFeatured.length ? datasetFeatured : ssrItems);
        const initialRaw = Array.isArray(settings.initialItems) && settings.initialItems.length
          ? settings.initialItems
          : featuredRaw;

        let initial = initialRaw.map(normalize);
        let featuredItems = featuredRaw.map(normalize);

        if (!initial.length) {
          initial = ssrItems.slice();
        }
        if (!initial.length) {
          wireControlsMinimal();
          return;
        }
        if (!featuredItems.length && featuredMode) {
          featuredItems = initial.slice();
        }

        const allInsightsSettings = (typeof settings.allInsights === 'object' && settings.allInsights !== null)
          ? settings.allInsights
          : {};
        const catalogRaw = Array.isArray(allInsightsSettings.items) ? allInsightsSettings.items : [];
        let catalogItems = catalogRaw.map(normalize);
        const nonFeaturedTotalSetting = Number.isFinite(allInsightsSettings.nonFeaturedTotal)
          ? allInsightsSettings.nonFeaturedTotal
          : catalogItems.length;
        const totalSetting = Number.isFinite(allInsightsSettings.total)
          ? allInsightsSettings.total
          : nonFeaturedTotalSetting + featuredItems.length;
        const featuredNodeIds = Array.isArray(settings.featuredNodeIds)
          ? settings.featuredNodeIds
              .map((nid) => parseInt(nid, 10))
              .filter((nid) => Number.isFinite(nid) && nid > 0)
          : [];

        const limitCandidate = allInsightsSettings.limit ?? (initial.length || 6);
        const initLimit = entries
          ? parseLimit(entries.value || limitCandidate)
          : parseLimit(limitCandidate);
        if (entries) {
          entries.value = String(initLimit);
        }

        const catalog = {
          cache: new Map(),
          pending: new Map(),
          limit: initLimit,
          cacheLimit: initLimit,
          nonFeaturedTotal: Math.max(0, nonFeaturedTotalSetting),
          total: Math.max(totalSetting, nonFeaturedTotalSetting + featuredItems.length),
        };
        if (catalogItems.length) {
          catalog.cache.set(0, catalogItems);
        }

        const state = {
          query: '',
          initialItems: initial.slice(),
          results: initial.slice(),
          limit: initLimit,
          page: 0,
          meta: {
            total: featuredMode ? catalog.total : initial.length,
            pages: Math.max(1, Math.ceil((featuredMode ? catalog.total : initial.length) / Math.max(initLimit, 1))),
            page: 0,
          },
          userActed: false,    // only becomes true on Click or Enter
          token: 0,
          featuredItems,
          featuredNodeIds,
          catalog,
        };

        let catalogToken = 0;

        const computeFeaturedMeta = () => {
          const safeLimit = Math.max(1, state.limit);
          const cachedPage = state.catalog.cache.get(0) || [];
          const knownCatalog = Math.max(state.catalog.nonFeaturedTotal, cachedPage.length);
          const featuredCount = state.featuredItems.length;
          const combinedTotal = featuredCount + knownCatalog;
          const totalPages = Math.max(1, Math.ceil(combinedTotal / safeLimit));
          return { total: combinedTotal, pages: totalPages, catalog: knownCatalog, featured: featuredCount };
        };

        const debouncedSearch = debounce((val) => { state.page = 0; search(val, 0); }, 300);

        if (featuredMode && !state.catalog.cache.has(0) && searchUrl) {
          ensureCatalogPage(0).then(() => {
            if (!manual && state.query === '' && featuredMode) {
              renderFeatured();
            }
          });
        }

        const filterByTheme = (items) => {
          const active = getThemesActive();
          if (!active.length) return items.slice();
          return items.filter(i => active.includes((i.theme_id || '').toLowerCase()));
        };

        // --- Rendering ---------------------------------------------------------
        function paintCards(items) {
          cards.innerHTML = '';
          if (!items.length) {
            const p = document.createElement('p');
            p.className = 'insights-empty';
            p.textContent = emptyMsg;
            cards.appendChild(p);
            return;
          }
          items.forEach((it) => {
            const card = document.createElement('div');
            card.className = 'insight-card';
            if (it.theme_id) card.setAttribute('data-theme', it.theme_id);

            if (it.theme_label) {
              const badge = document.createElement('span');
              badge.className = `insight-badge ${it.theme_id}`.trim();
              badge.textContent = it.theme_label;
              card.appendChild(badge);
            }

            const h3 = document.createElement('h3');
            h3.className = 'insight-card-title';
            h3.textContent = it.title;
            card.appendChild(h3);

            if (it.read_time) {
              const meta = document.createElement('div');
              meta.className = 'insight-meta';
              const t = document.createElement('span');
              t.className = 'insight-time';
              t.textContent = `🕒 ${it.read_time}`;
              meta.appendChild(t);
              card.appendChild(meta);
            }

            if (it.summary) {
              const p = document.createElement('p');
              p.className = 'insight-summary';
              p.textContent = it.summary;
              card.appendChild(p);
            }

            if (it.author) {
              const a = document.createElement('div');
              a.className = 'insight-author';
              const lab = document.createElement('span');
              lab.textContent = Drupal.t('By');
              a.appendChild(lab);
              a.append(` ${it.author}`);
              card.appendChild(a);
            }

            const area = document.createElement('div');
            area.className = 'button-area';
            const link = document.createElement('a');
            link.className = 'principal-link principal-link--subtle';
            link.href = it.url || '#';
            link.textContent = it.link_text || defaultLinkText;
            area.appendChild(link);

            const imgA = document.createElement('div');
            imgA.className = 'img-area';
            const img = document.createElement('img');
            img.src = `${iconsPath}/arrow-right.svg`;
            img.alt = '';
            img.setAttribute('aria-hidden', 'true');
            imgA.appendChild(img);
            area.appendChild(imgA);

            card.appendChild(area);
            cards.appendChild(card);
          });
        }

        function paintTotal(visible, total) {
          if (!totalEl) return;
          if (total <= 0) { totalEl.textContent = Drupal.t('0 total results'); return; }
          if (visible === total) {
            totalEl.textContent = Drupal.formatPlural(total, '1 total result', '@count total results');
            return;
          }
          totalEl.textContent = Drupal.t('@visible of @total results', { '@visible': visible, '@total': total });
        }

        function paintPages(totalPages, current, remote) {
          if (!pageNums) return;
          pageNums.innerHTML = '';
          const pages = Math.max(1, totalPages);
          const cur = Math.min(Math.max(current, 0), pages - 1);
          const maxBtns = 5;
          let start = Math.max(0, cur - Math.floor(maxBtns / 2));
          let end = Math.min(pages, start + maxBtns);
          if (end - start < maxBtns) start = Math.max(0, end - maxBtns);

          for (let i = start; i < end; i++) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = `page-number${i === cur ? ' active' : ''}`;
            b.textContent = String(i + 1);
            b.addEventListener('click', () => goPage(i, remote));
            pageNums.appendChild(b);
          }

          if (btnPrev) { btnPrev.disabled = cur <= 0; btnPrev.onclick = () => cur > 0 && goPage(cur - 1, remote); }
          if (btnFirst){ btnFirst.disabled= cur <= 0; btnFirst.onclick = () => goPage(0, remote); }
          if (btnNext) { btnNext.disabled = cur >= pages - 1; btnNext.onclick = () => cur < pages - 1 && goPage(cur + 1, remote); }
          if (btnLast) { btnLast.disabled = cur >= pages - 1; btnLast.onclick = () => goPage(pages - 1, remote); }
        }

        function render() {
          if (state.query !== '') {
            renderRemote();
            return;
          }
          if (featuredMode) {
            renderFeatured();
            return;
          }
          renderLocal();
        }

        function renderRemote() {
          const filtered = filterByTheme(state.results);
          const meta = state.meta || {};
          const total = typeof meta.total === 'number' ? meta.total : filtered.length;
          const pages = typeof meta.pages === 'number' ? meta.pages : 1;
          const current = typeof meta.page === 'number' ? meta.page : 0;
          setPaginationVisibility(pages > 1);
          paintCards(filtered);
          paintTotal(filtered.length, total);
          paintPages(pages, current, true);
        }

        function renderLocal() {
          const filtered = filterByTheme(state.initialItems);
          const total = filtered.length;
          const pages = Math.max(1, Math.ceil(total / Math.max(state.limit, 1)));
          if (state.page >= pages) state.page = pages - 1;
          const start = state.page * state.limit;
          const visible = filtered.slice(start, start + state.limit);
          state.meta = { total, pages, page: state.page };
          setPaginationVisibility(pages > 1);
          paintCards(visible);
          paintTotal(visible.length, total);
          paintPages(pages, state.page, false);
        }

        function combineFeaturedFirstPage() {
          //2.- Prioritize curated entries so the hero listing mirrors the manual selections exactly.
          const curated = [];
          const seen = new Set();
          const add = (item) => {
            if (!item || typeof item !== 'object') return;
            const key = `${item.id ?? ''}|${item.url ?? ''}|${item.title ?? ''}`.toLowerCase();
            if (seen.has(key)) return;
            seen.add(key);
            curated.push(item);
          };
          state.featuredItems.forEach(add);

          //3.- When no featured items exist reuse the automatic catalog so the interface keeps working gracefully.
          const fallback = state.catalog.cache.get(0) || [];
          fallback.forEach(add);
          return curated;
        }

        function ensureCatalogCapacity() {
          if (state.catalog.cacheLimit === state.limit) {
            return;
          }
          state.catalog.cache.clear();
          state.catalog.pending.clear();
          state.catalog.cacheLimit = state.limit;
        }

        function ensureCatalogPage(pageIndex) {
          ensureCatalogCapacity();
          if (state.catalog.cache.has(pageIndex)) {
            return Promise.resolve(state.catalog.cache.get(pageIndex));
          }
          if (state.catalog.pending.has(pageIndex)) {
            return state.catalog.pending.get(pageIndex);
          }
          if (!searchUrl) {
            const empty = [];
            state.catalog.cache.set(pageIndex, empty);
            return Promise.resolve(empty);
          }

          let u;
          try { u = new URL(searchUrl, window.location.origin); }
          catch { try { u = new URL(searchUrl, window.location.href); } catch { u = null; } }
          if (!u) {
            const empty = [];
            state.catalog.cache.set(pageIndex, empty);
            return Promise.resolve(empty);
          }

          u.searchParams.set('limit', String(state.limit));
          u.searchParams.set('page', String(Math.max(0, pageIndex)));
          if (state.featuredNodeIds.length) {
            u.searchParams.set('exclude', state.featuredNodeIds.join(','));
          }

          catalogToken += 1;
          const token = catalogToken;
          section.setAttribute('aria-busy', 'true');

          const request = fetch(u.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then((payload) => {
              if (token !== catalogToken) {
                return state.catalog.cache.get(pageIndex) || [];
              }
              const list = Array.isArray(payload?.data) ? payload.data.map(normalize) : [];
              const meta = payload?.meta || {};
              const total = typeof meta.total === 'number' ? meta.total : list.length;
              state.catalog.cache.set(pageIndex, list);
              state.catalog.nonFeaturedTotal = total;
              state.catalog.total = Math.max(state.featuredItems.length, total + state.featuredItems.length);
              state.catalog.limit = state.limit;
              state.catalog.cacheLimit = state.limit;
              state.catalog.pending.delete(pageIndex);
              return list;
            })
            .catch(() => {
              state.catalog.pending.delete(pageIndex);
              state.catalog.cache.set(pageIndex, []);
              return [];
            })
            .finally(() => {
              if (token === catalogToken) {
                section.removeAttribute('aria-busy');
              }
            });

          state.catalog.pending.set(pageIndex, request);
          return request;
        }

        function renderFeatured() {
          const featuredMeta = computeFeaturedMeta();
          const combinedTotal = Math.max(state.catalog.total, featuredMeta.total);
          const pages = Math.max(featuredMeta.pages, Math.ceil(combinedTotal / Math.max(state.limit, 1)));
          if (state.page >= pages) state.page = pages - 1;
          state.meta = { total: combinedTotal, pages, page: state.page };
          //4.- Keep pagination visible whenever additional catalog pages exist, even before the user leaves the featured tab.
          const shouldShowPagination = (pages > 1) || featuredMeta.catalog > 0;
          setPaginationVisibility(shouldShowPagination);
          paintPages(pages, state.page, false);

          if (state.page === 0) {
            //6.- Warm up the automatic catalog so the next page is ready once visitors leave the curated selection.
            if (!state.catalog.cache.has(0) && featuredMeta.catalog > 0 && searchUrl) {
              ensureCatalogPage(0);
            }            
            const combined = combineFeaturedFirstPage();
            const visible = filterByTheme(combined).slice(0, state.limit);
            paintCards(visible);
            paintTotal(visible.length, combinedTotal);
            return;
          }

          const catalogPage = state.page - 1;
          if (!state.catalog.cache.has(catalogPage)) {
            ensureCatalogPage(catalogPage).then(() => {
              if (state.query === '' && featuredMode) {
                renderFeatured();
              }
            });
            return;
          }

          const dataset = state.catalog.cache.get(catalogPage) || [];
          const visible = filterByTheme(dataset).slice(0, state.limit);
          paintCards(visible);
          paintTotal(visible.length, combinedTotal);
        }

        // --- Networking --------------------------------------------------------
        function search(term, page = 0) {
          const q = (typeof term === 'string' ? term.trim() : '');

          // Block fetches until user explicitly acted AND query >= 3.
          if (!state.userActed || q.length < 3 || !searchUrl) {
            state.query = '';
            state.results = state.initialItems.slice();
            const baseTotal = featuredMode
              ? Math.max(state.catalog.total, state.featuredItems.length + state.catalog.nonFeaturedTotal)
              : state.initialItems.length;
            state.meta = {
              total: baseTotal,
              pages: Math.max(1, Math.ceil(baseTotal / Math.max(state.limit, 1))),
              page: 0,
            };
            state.page = 0;
            // In manual mode, do not repaint SSR unless user already acted
            if (!manual || state.userActed) render();
            return;
          }

          state.query = q;

          let u;
          try { u = new URL(searchUrl, window.location.origin); }
          catch { try { u = new URL(searchUrl, window.location.href); } catch { u = null; } }
          if (!u) { render(); return; }

          u.searchParams.set('q', q);
          u.searchParams.set('limit', String(state.limit));
          u.searchParams.set('page', String(Math.max(0, page)));

          state.token += 1;
          const tok = state.token;
          section.setAttribute('aria-busy', 'true');

          fetch(u.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(payload => {
              if (tok !== state.token) return;
              const list = Array.isArray(payload?.data) ? payload.data : [];
              state.results = list.map(normalize);
              const m = payload?.meta || {};
              state.meta = {
                total: typeof m.total === 'number' ? m.total : state.results.length,
                pages: typeof m.pages === 'number' ? m.pages : 1,
                page: typeof m.page === 'number' ? m.page : 0,
              };
              state.page = state.meta.page;
              render();
            })
            .catch(() => {
              if (tok !== state.token) return;
              state.results = [];
              state.meta = { total: 0, pages: 1, page: 0 };
              state.page = 0;
              render();
            })
            .finally(() => { if (tok === state.token) section.removeAttribute('aria-busy'); });
        }

        function goPage(i, remote) {
          const next = Math.max(0, i);
          if (remote && state.query !== '') { search(state.query, next); return; }
          state.page = next; render();
        }

        // --- Wire controls -----------------------------------------------------
        const markActed = () => { state.userActed = true; };

        // Do NOT mark userActed on raw input. Users must click or press Enter.
        qInput?.addEventListener('input', (e) => {
          debouncedSearch(e.target.value || '');
        });

        qInput?.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            debouncedSearch.cancel();
            markActed();
            search(qInput.value || '', 0);
          }
        });

        btnSearch?.addEventListener('click', (e) => {
          e.preventDefault();
          debouncedSearch.cancel();
          markActed();
          search(qInput?.value || '', 0);
        });

        btnReset?.addEventListener('click', (e) => {
          e.preventDefault();
          debouncedSearch.cancel();
          if (qInput) qInput.value = '';
          // Restore theme default selection as in SSR
          const defaults = getThemesActive();
          themeBtns.forEach((b) => {
            const t = (b.dataset.theme || '').toLowerCase();
            b.classList.toggle('active', defaults.includes(t));
          });
          state.query = '';
          state.page = 0;
          state.results = state.initialItems.slice();
          state.meta = {
            total: state.initialItems.length,
            pages: Math.max(1, Math.ceil(state.initialItems.length / Math.max(state.limit, 1))),
            page: 0,
          };
          // Only repaint if not manual; in manual, SSR is already what we want.
          if (!manual) render();
        });

        themeBtns.forEach((b) => b.addEventListener('click', () => {
          b.classList.toggle('active');
          state.page = 0;
          if (!manual || state.userActed) render();
        }));

        entries?.addEventListener('change', (e) => {
          const n = parseLimit(e.target.value);
          state.limit = n;
          state.page = 0;
          state.catalog.limit = n;
          if (entries) {
            entries.value = String(n);
          }

          if (state.query !== '') {
            debouncedSearch.cancel();
            search(state.query, 0);
            return;
          }

          if (featuredMode) {
            state.catalog.cache.clear();
            state.catalog.pending.clear();
            state.catalog.cacheLimit = null;
            state.catalog.total = Math.max(state.catalog.nonFeaturedTotal + state.featuredItems.length, state.catalog.total);
            if (!manual) render();
            ensureCatalogPage(0).then(() => {
              if (!manual && state.query === '' && featuredMode) {
                renderFeatured();
              }
            });
            return;
          }

          if (!manual) render();
        });

        // --- First paint -------------------------------------------------------
        // Auto mode: render immediately.
        // Manual mode: do nothing; keep SSR cards and counters as-is.
        if (!manual) {
          render();
        }
      });
    },
  };

  // Fallback: minimal wiring when no initial items exist.
  function wireControlsMinimal() {
    // Intentionally no-op; site keeps SSR. Implement if needed for empty states.
  }
})(Drupal, once, drupalSettings);

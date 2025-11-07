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
        // If attribute is "0" => manual. Otherwise auto.
        const manual = false;

        // --- DOM ---------------------------------------------------------------
        const qInput        = section.querySelector('.search-input');
        const btnSearch     = section.querySelector('.search-button');
        const btnReset      = section.querySelector('.reset-button');
        const filterButton  = section.querySelector('.filter-button');
        const filterMenu    = section.querySelector('[data-filter-menu]');
        const filterBackdrop= section.querySelector('[data-filter-backdrop]');
        const applyFilters  = section.querySelector('[data-filter-apply]');
        const clearFilters  = section.querySelector('[data-filter-clear]');
        const themeCheckboxes = Array.from(section.querySelectorAll('[data-theme-checkbox]'));
        const themeTags     = section.querySelector('.theme-tags');
        const cards         = section.querySelector('.insights-cards');
        const totalEl       = section.querySelector('[data-total]');
        const entries       = section.querySelector('.entries-select');
        const pageNums      = section.querySelector('.page-numbers');
        const btnFirst      = section.querySelector('.page-first');
        const btnPrev       = section.querySelector('.page-prev');
        const btnNext       = section.querySelector('.page-next');
        const btnLast       = section.querySelector('.page-last');
        const pagination = section.querySelector('.insights-pagination');

        if (!cards) return;

        let state;

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

        const formatReadTime = (value) => {
          //1.- Provide a localized "min read" suffix without duplicating existing descriptors.
          if (typeof value !== 'string') {
            return '';
          }
          const trimmed = value.trim();
          if (trimmed === '') {
            return '';
          }
          const lower = trimmed.toLowerCase();
          if (lower.includes('min')) {
            return `🕒 ${trimmed}`;
          }
          return `🕒 ${Drupal.t('@count min read', { '@count': trimmed })}`;
        };

        //1.- Toggle the pagination visibility while keeping controls visible unless callers explicitly request to hide them.
        const setPaginationVisibility = (shouldShow) => {
          if (!pagination) return;
          const show = shouldShow !== false;
          pagination.classList.toggle('is-hidden', !show);
          pagination.setAttribute('aria-hidden', show ? 'false' : 'true');
        };

        const debounce = (fn, ms) => {
          let t = null;
          const d = (...args) => { if (t) clearTimeout(t); t = setTimeout(() => { t = null; fn(...args); }, ms); };
          d.cancel = () => { if (t) { clearTimeout(t); t = null; } };
          return d;
        };

        //1.- Build theme lookup tables so checkbox selections can map back to taxonomy IDs and slugs consistently.
        const themeLookups = {
          byTid: new Map(),
          bySlug: new Map(),
        };

        const registerThemeMeta = (tid, slug, label) => {
          const keyTid = tid ? tid.toString() : '';
          const keySlug = slug ? slug.toString().toLowerCase() : '';
          if (keyTid && !themeLookups.byTid.has(keyTid)) {
            themeLookups.byTid.set(keyTid, { slug: keySlug, label });
          }
          if (keySlug && !themeLookups.bySlug.has(keySlug)) {
            themeLookups.bySlug.set(keySlug, { tid: keyTid, label });
          }
        };

        const themesConfig = Array.isArray(settings.themes) ? settings.themes : [];
        themesConfig.forEach((theme) => {
          const tid = (theme?.tid ?? theme?.value ?? '').toString();
          const slug = (theme?.id ?? '').toString().toLowerCase();
          const label = typeof theme?.label === 'string' ? theme.label : (slug || tid);
          if (tid || slug) {
            registerThemeMeta(tid, slug, label);
          }
        });

        themeCheckboxes.forEach((checkbox) => {
          const tid = (checkbox.value || '').toString();
          const slug = (checkbox.dataset.themeId || '').toString().toLowerCase();
          const label = checkbox.nextElementSibling?.textContent?.trim() || slug || tid;
          registerThemeMeta(tid, slug, label);
        });

        const readCheckboxFilters = () => {
          const tids = [];
          const slugs = [];
          themeCheckboxes.forEach((checkbox) => {
            if (!checkbox.checked) {
              return;
            }
            const tid = (checkbox.value || '').toString();
            const slug = (checkbox.dataset.themeId || '').toString().toLowerCase();
            if (tid) {
              tids.push(tid);
            }
            if (slug) {
              slugs.push(slug);
            }
          });
          return {
            tids: Array.from(new Set(tids.map((tid) => tid.toString()))),
            slugs: Array.from(new Set(slugs.map((slug) => slug.toLowerCase()))),
          };
        };

        const syncCheckboxesToState = (filters) => {
          const tidSet = new Set(filters.tids.map((tid) => tid.toString()));
          themeCheckboxes.forEach((checkbox) => {
            const tid = (checkbox.value || '').toString();
            checkbox.checked = tidSet.has(tid);
          });
        };

        const renderThemeTags = (filters) => {
          if (!themeTags) {
            return;
          }
          const tids = Array.isArray(filters?.tids) ? filters.tids : [];
          themeTags.innerHTML = '';
          const isEmpty = tids.length === 0;
          themeTags.classList.toggle('theme-tags--empty', isEmpty);
          themeTags.setAttribute('data-empty', isEmpty ? 'true' : 'false');
          if (isEmpty) {
            return;
          }
          tids.forEach((tid) => {
            const key = tid.toString();
            const meta = themeLookups.byTid.get(key) || {};
            const label = meta.label || key;
            const slug = (meta.slug || key).toString();
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'theme-tag active';
            button.dataset.theme = slug;
            button.dataset.themeTid = key;
            button.setAttribute('aria-label', Drupal.t('Remove @label filter', { '@label': label }));
            button.title = Drupal.t('Remove @label filter', { '@label': label });

            const labelSpan = document.createElement('span');
            labelSpan.className = 'theme-tag__label';
            labelSpan.textContent = label;
            button.appendChild(labelSpan);

            const removeSpan = document.createElement('span');
            removeSpan.className = 'theme-tag__remove';
            removeSpan.setAttribute('aria-hidden', 'true');
            removeSpan.textContent = '×';
            button.appendChild(removeSpan);

            button.addEventListener('click', (event) => {
              event.preventDefault();
              removeThemeTag(key, slug);
            });

            themeTags.appendChild(button);
          });
        };

        function removeThemeTag(tid, slug) {
          if (!state || !state.themeFilters) {
            return;
          }
          const tidKey = tid ? tid.toString() : '';
          const slugKey = slug ? slug.toString().toLowerCase() : '';
          const nextTids = state.themeFilters?.tids?.filter
            ? state.themeFilters.tids.filter((value) => value.toString() !== tidKey)
            : [];
          let nextSlugs = Array.isArray(state.themeFilters?.slugs)
            ? state.themeFilters.slugs.slice()
            : [];
          if (slugKey) {
            nextSlugs = nextSlugs.filter((value) => value.toString().toLowerCase() !== slugKey);
          }
          applyThemeFilters({ tids: nextTids, slugs: nextSlugs });
          handleFiltersApplied();
        }

        const initialThemeFilters = readCheckboxFilters();

        const createItemKey = (item) => {
          const id = (typeof item?.id === 'number' || typeof item?.id === 'string') ? item.id : '';
          const url = typeof item?.url === 'string' ? item.url : '';
          const title = typeof item?.title === 'string' ? item.title : '';
          return `${id}|${url}|${title}`.toLowerCase();
        };

        const normalize = (item) => {
          const t = item && typeof item === 'object' ? (item.theme || {}) : {};
          const rawThemeId = item?.theme_id ?? t.id ?? '';
          const theme_id = rawThemeId === null || rawThemeId === undefined
            ? ''
            : rawThemeId.toString().toLowerCase();
          const themeTidRaw = t && Object.prototype.hasOwnProperty.call(t, 'id') ? t.id : (item?.theme_tid ?? null);
          const theme_tid = themeTidRaw === null || themeTidRaw === undefined ? '' : themeTidRaw.toString();
          const rawTaxonomies = Array.isArray(item?.taxonomies) ? item.taxonomies : [];
          const taxonomies = rawTaxonomies
            .map((value) => (typeof value === 'string' ? value.trim() : ''))
            .filter((value) => value !== '');
          return {
            id: (typeof item?.id === 'number' || typeof item?.id === 'string') ? item.id : null,
            theme_id,
            theme_label: typeof (item?.theme_label ?? t.label) === 'string' ? (item?.theme_label ?? t.label) : '',
            theme_tid,
            title: typeof item?.title === 'string' ? item.title : '',
            summary: typeof item?.summary === 'string' ? item.summary : '',
            author: typeof item?.author === 'string' ? item.author : '',
            read_time: typeof item?.read_time === 'string' ? item.read_time : '',
            url: (typeof item?.url === 'string' && item.url) ? item.url : '#',
            link_text: (typeof item?.link_text === 'string' && item.link_text) ? item.link_text : defaultLinkText,
            taxonomies,
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

            let readTime = '';
            if (time) {
              const suffix = Drupal.t('min read');
              const raw = (time.textContent || '').replace('🕒', '').trim();
              if (raw.toLowerCase().endsWith(suffix.toLowerCase())) {
                readTime = raw.slice(0, Math.max(0, raw.length - suffix.length)).trim();
              }
              else {
                readTime = raw;
              }
            }

            let taxonomies = [];
            const datasetTax = card.getAttribute('data-taxonomies');
            if (datasetTax) {
              try {
                const parsed = JSON.parse(datasetTax);
                if (Array.isArray(parsed)) {
                  taxonomies = parsed
                    .map((value) => (typeof value === 'string' ? value.trim() : ''))
                    .filter((value) => value !== '');
                }
              }
              catch (error) {
                //1.- Silently ignore malformed attributes so the SSR fallback remains resilient.
              }
            }
            if (!taxonomies.length && badge) {
              taxonomies = Array.from(badge.querySelectorAll('.insight-badge__item'))
                .map((chip) => (chip.textContent || '').trim())
                .filter((value) => value !== '');
            }

            const themeLabel = taxonomies.length
              ? taxonomies[0]
              : (badge ? (badge.textContent || '').trim() : '');

            return normalize({
              theme_id,
              theme_label: themeLabel,
              title: title ? (title.textContent || '').trim() : '',
              summary: summary ? (summary.textContent || '').trim() : '',
              read_time: readTime,
              author,
              url: link ? (link.getAttribute('href') || '') : '#',
              link_text: link ? (link.textContent || '').trim() : defaultLinkText,
              taxonomies,
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
        const featuredKeys = new Set(featuredItems.map((item) => createItemKey(item)));

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

        state = {
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
          featuredKeys,
          featuredNodeIds,
          catalog,
          themeFilters: {
            tids: initialThemeFilters.tids.slice(),
            slugs: initialThemeFilters.slugs.slice(),
          },
          firstPageFeaturedVisible: 0,
          firstPageRemoteVisible: 0,
        };

        //3.- Reflect any preselected filters so the checkbox grid and tag summary stay synchronized from the first render.
        syncCheckboxesToState(state.themeFilters);
        renderThemeTags(state.themeFilters);

        let catalogToken = 0;
        let featuredRenderToken = 0;

        const resetCatalogCaches = () => {
          //4.- Flush cached catalog pages so backend requests honor the latest taxonomy filters.
          state.catalog.cache.clear();
          state.catalog.pending.clear();
          state.catalog.cacheLimit = null;
          state.catalog.nonFeaturedTotal = 0;
          state.catalog.total = filterByTheme(state.featuredItems).length;
          state.firstPageFeaturedVisible = 0;
          state.firstPageRemoteVisible = 0;
        };

        const applyThemeFilters = (nextFilters) => {
          const tids = Array.from(new Set((nextFilters?.tids || []).map((tid) => tid.toString())));
          const slugs = Array.from(new Set((nextFilters?.slugs || []).map((slug) => slug.toString().toLowerCase())));
          state.themeFilters = { tids, slugs };
          syncCheckboxesToState(state.themeFilters);
          renderThemeTags(state.themeFilters);
          resetCatalogCaches();
        };

        const handleFiltersApplied = () => {
          //5.- Restart pagination and trigger the appropriate rendering path whenever filters change.
          state.page = 0;
          if (state.query !== '') {
            debouncedSearch.cancel();
            search(qInput?.value || '', 0);
            return;
          }
          if (!manual) {
            render();
            if (featuredMode && searchUrl) {
              ensureCatalogPage(0).then(() => {
                if (!manual && state.query === '' && featuredMode) {
                  renderFeatured();
                }
              });
            }
          }
        };

        const setMenuVisibility = (open) => {
          const expanded = open ? 'true' : 'false';
          filterButton?.setAttribute('aria-expanded', expanded);
          filterButton?.classList.toggle('is-active', open);
          if (filterMenu) {
            if (open) {
              filterMenu.removeAttribute('hidden');
            }
            else {
              filterMenu.setAttribute('hidden', '');
            }
            filterMenu.classList.toggle('is-active', open);
          }
          if (filterBackdrop) {
            if (open) {
              filterBackdrop.removeAttribute('hidden');
            }
            else {
              filterBackdrop.setAttribute('hidden', '');
            }
            filterBackdrop.classList.toggle('is-active', open);
          }
        };

        const openFilterMenu = () => setMenuVisibility(true);
        const closeFilterMenu = () => setMenuVisibility(false);

        const applyFiltersFromCheckboxes = () => {
          applyThemeFilters(readCheckboxFilters());
          handleFiltersApplied();
          closeFilterMenu();
        };

        const clearAllFilters = () => {
          themeCheckboxes.forEach((checkbox) => {
            checkbox.checked = false;
          });
          applyThemeFilters({ tids: [], slugs: [] });
          handleFiltersApplied();
          closeFilterMenu();
        };

        const applyThemeParams = (urlObj) => {
          if (!urlObj) {
            return;
          }
          const tids = state.themeFilters?.tids || [];
          if (tids.length) {
            urlObj.searchParams.set('themes', tids.join(','));
          }
          else {
            urlObj.searchParams.delete('themes');
          }
        };

        const computeFeaturedMeta = () => {
          const safeLimit = Math.max(1, state.limit);
          const cachedPage = state.catalog.cache.get(0) || [];
          const filteredCatalog = filterByTheme(cachedPage);
          const remoteTotal = Math.max(0, state.catalog.nonFeaturedTotal);
          const featuredFiltered = filterByTheme(state.featuredItems);
          const featuredCount = featuredFiltered.length;
          const catalogCount = remoteTotal || filteredCatalog.length;
          const combinedTotal = featuredCount + catalogCount;
          const fallbackTotal = featuredCount + filteredCatalog.length;
          const total = Math.max(combinedTotal, fallbackTotal);
          const totalPages = Math.max(1, Math.ceil(Math.max(total, 1) / safeLimit));
          return { total, pages: totalPages, catalog: catalogCount, featured: featuredCount };
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
          const tids = state.themeFilters?.tids || [];
          const slugs = state.themeFilters?.slugs || [];
          if (!tids.length && !slugs.length) {
            return items.slice();
          }
          //2.- Compile every relevant identifier so either taxonomy ID or slug matches can keep items visible.
          const tokens = new Set();
          tids.forEach((tid) => {
            if (!tid) {
              return;
            }
            const key = tid.toString();
            tokens.add(key.toLowerCase());
            const meta = themeLookups.byTid.get(key);
            if (meta?.slug) {
              tokens.add(meta.slug.toString().toLowerCase());
            }
          });
          slugs.forEach((slug) => {
            if (!slug) {
              return;
            }
            const key = slug.toString().toLowerCase();
            tokens.add(key);
            const meta = themeLookups.bySlug.get(key);
            if (meta?.tid) {
              tokens.add(meta.tid.toString().toLowerCase());
            }
          });
          return items.filter((item) => {
            const themeId = (item.theme_id || '').toString().toLowerCase();
            const themeTid = (item.theme_tid || '').toString().toLowerCase();
            if (themeId && tokens.has(themeId)) {
              return true;
            }
            if (themeTid && tokens.has(themeTid)) {
              return true;
            }
            return false;
          });
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
            const badgeValues = Array.isArray(it.taxonomies) ? it.taxonomies : [];
            const cleanedBadges = badgeValues
              .map((label) => (typeof label === 'string' ? label.trim() : ''))
              .filter((label) => label !== '');
            const displayBadges = cleanedBadges.length
              ? cleanedBadges
              : (typeof it.theme_label === 'string' && it.theme_label.trim() !== '' ? [it.theme_label.trim()] : []);
            card.setAttribute('data-taxonomies', JSON.stringify(displayBadges));

            if (displayBadges.length) {
              const badge = document.createElement('div');
              badge.className = `insight-badge ${it.theme_id}`.trim();
              displayBadges.forEach((label) => {
                const chip = document.createElement('span');
                chip.className = 'insight-badge__item';
                chip.textContent = label;
                badge.appendChild(chip);
              });
              card.appendChild(badge);
            }

            const h3 = document.createElement('h3');
            h3.className = 'insight-card-title';
            h3.textContent = it.title;
            card.appendChild(h3);

            if (it.read_time) {
              const label = formatReadTime(it.read_time);
              if (label) {
              const meta = document.createElement('div');
              meta.className = 'insight-meta';
              const t = document.createElement('span');
              t.className = 'insight-time';
                t.textContent = label;
              meta.appendChild(t);
              card.appendChild(meta);
              }
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
          const totalRaw = typeof meta.total === 'number' ? meta.total : filtered.length;
          const total = Math.max(0, totalRaw);
          const pagesRaw = typeof meta.pages === 'number' ? meta.pages : 1;
          const pages = Math.max(1, pagesRaw);
          const currentRaw = typeof meta.page === 'number' ? meta.page : 0;
          const current = Math.min(Math.max(currentRaw, 0), pages - 1);
          setPaginationVisibility(total > 0);
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
          setPaginationVisibility(total > 0);
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
            const key = createItemKey(item);
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
          applyThemeParams(u);

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
              const filteredFeaturedCount = filterByTheme(state.featuredItems).length;
              state.catalog.total = filteredFeaturedCount + total;
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

        function ensureCatalogRange(startIndex, amount) {
          //2.- Assemble contiguous catalog segments so follow-up pages continue right after curated highlights.
          ensureCatalogCapacity();
          const perPage = Math.max(1, state.limit);
          const safeStart = Math.max(0, startIndex);
          const safeAmount = Math.max(0, amount);
          if (safeAmount === 0) {
            return Promise.resolve([]);
          }

          const startPage = Math.floor(safeStart / perPage);
          const endIndex = safeStart + safeAmount - 1;
          const endPage = Math.floor(endIndex / perPage);
          const requests = [];

          for (let page = startPage; page <= endPage; page++) {
            requests.push(ensureCatalogPage(page));
          }

          if (requests.length === 0) {
            return Promise.resolve([]);
          }

          return Promise.all(requests).then(() => {
            const collected = [];
            for (let page = startPage; page <= endPage; page++) {
              const dataset = state.catalog.cache.get(page) || [];
              dataset.forEach((item, indexInPage) => {
                const globalIndex = (page * perPage) + indexInPage;
                if (globalIndex >= safeStart && globalIndex < safeStart + safeAmount) {
                  collected.push(item);
                }
              });
            }
            return collected;
          });
        }

        function renderFeatured() {
          const featuredMeta = computeFeaturedMeta();
          const combinedTotal = Math.max(state.catalog.total, featuredMeta.total);
          const pages = Math.max(featuredMeta.pages, Math.ceil(combinedTotal / Math.max(state.limit, 1)));
          if (state.page >= pages) state.page = pages - 1;
          state.catalog.total = combinedTotal;
          state.meta = { total: combinedTotal, pages, page: state.page };
          const shouldShowPagination = combinedTotal > 0 || featuredMeta.catalog > 0;
          setPaginationVisibility(shouldShowPagination);
          paintPages(pages, state.page, false);

          const combined = combineFeaturedFirstPage();
          const filteredCombined = filterByTheme(combined);
          const firstSlice = filteredCombined.slice(0, state.limit);
          //3.- Capture the number of curated entries rendered on page one so subsequent pages skip them cleanly.
          const featuredVisibleFirst = firstSlice.reduce((count, item) => {
            const key = createItemKey(item);
            return state.featuredKeys.has(key) ? count + 1 : count;
          }, 0);
          const remoteVisibleFirst = Math.max(0, firstSlice.length - featuredVisibleFirst);
          state.firstPageFeaturedVisible = featuredVisibleFirst;
          state.firstPageRemoteVisible = remoteVisibleFirst;

          if (state.page === 0) {
            if (!state.catalog.cache.has(0) && featuredMeta.catalog > 0 && searchUrl) {
              ensureCatalogPage(0);
            }
            paintCards(firstSlice);
            paintTotal(firstSlice.length, combinedTotal);
            return;
          }

          const remoteStart = remoteVisibleFirst + ((state.page - 1) * state.limit);
          const token = ++featuredRenderToken;
          section.setAttribute('aria-busy', 'true');

          ensureCatalogRange(remoteStart, state.limit)
            .then((range) => {
              if (featuredRenderToken !== token || state.query !== '' || !featuredMode) {
                return;
              }
              const filteredRange = filterByTheme(range);
              const visible = filteredRange.slice(0, state.limit);
              paintCards(visible);
              paintTotal(visible.length, combinedTotal);
            })
            .catch(() => {
              if (featuredRenderToken !== token || state.query !== '' || !featuredMode) {
                return;
              }
              paintCards([]);
              paintTotal(0, combinedTotal);
            })
            .finally(() => {
              if (featuredRenderToken === token) {
                section.removeAttribute('aria-busy');
              }
            });
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
          if (state.featuredNodeIds.length) {
            u.searchParams.set('exclude', state.featuredNodeIds.join(','));
          }

          state.token += 1;
          applyThemeParams(u);
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
          state.query = '';
          state.page = 0;
          state.results = state.initialItems.slice();
          state.meta = {
            total: state.initialItems.length,
            pages: Math.max(1, Math.ceil(state.initialItems.length / Math.max(state.limit, 1))),
            page: 0,
          };
          applyThemeFilters({ tids: [], slugs: [] });
          handleFiltersApplied();
          closeFilterMenu();
        });

        filterButton?.addEventListener('click', (event) => {
          event.preventDefault();
          const isExpanded = filterButton.getAttribute('aria-expanded') === 'true';
          if (isExpanded) {
            closeFilterMenu();
          }
          else {
            openFilterMenu();
          }
        });

        filterBackdrop?.addEventListener('click', (event) => {
          event.preventDefault();
          closeFilterMenu();
        });

        filterMenu?.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            event.preventDefault();
            closeFilterMenu();
            filterButton?.focus();
          }
        });

        applyFilters?.addEventListener('click', (event) => {
          event.preventDefault();
          applyFiltersFromCheckboxes();
        });

        clearFilters?.addEventListener('click', (event) => {
          event.preventDefault();
          clearAllFilters();
        });

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
            resetCatalogCaches();
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

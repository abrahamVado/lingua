(function (Drupal, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.pdsRecipeInsightsSearchAdminSearch = {
    attach(context) {
      const wrappers = once('pds-recipe-insights-search-admin', '[data-pds-insights-search-admin="wrapper"]', context);
      wrappers.forEach((wrapper) => {
        //1.- Gather configuration values and all relevant DOM nodes.
        const settings = drupalSettings.pdsRecipeInsightsSearchAdmin || {};
        const searchUrl = settings.searchUrl || '';
        if (!searchUrl) {
          return;
        }

        const selectors = {
          input: '[data-pds-insights-search-admin="input"]',
          button: '[data-pds-insights-search-admin="button"]',
          results: '[data-pds-insights-search-admin="results"]',
          selection: '[data-pds-insights-search-admin="selection"]',
          title: '[data-pds-insights-search-admin-selected="title"]',
          summary: '[data-pds-insights-search-admin-selected="summary"]',
          url: '[data-pds-insights-search-admin-selected="url"]',
          source: '[data-pds-insights-search-admin-selected="source"]',
        };

        const input = wrapper.querySelector(selectors.input);
        const button = wrapper.querySelector(selectors.button);
        const resultsContainer = wrapper.querySelector(selectors.results);
        const selectionContainer = wrapper.querySelector(selectors.selection);
        const hiddenTitle = wrapper.querySelector(selectors.title);
        const hiddenSummary = wrapper.querySelector(selectors.summary);
        const hiddenUrl = wrapper.querySelector(selectors.url);
        const hiddenSource = wrapper.querySelector(selectors.source);

        if (!input || !button || !resultsContainer || !selectionContainer || !hiddenTitle || !hiddenSummary || !hiddenUrl || !hiddenSource) {
          return;
        }

        const strings = Object.assign({
          resultsEmpty: Drupal.t('No results found.'),
          error: Drupal.t('Unable to complete the search.'),
          loading: Drupal.t('Searching…'),
          add: Drupal.t('Select'),
          selected: Drupal.t('Selected insight: @title'),
          selectionEmpty: Drupal.t('No insight selected.'),
          minimum: Drupal.t('Enter at least three characters to search.'),
        }, settings.strings || {});

        let abortController = null;
        const supportsAbort = typeof AbortController !== 'undefined';

        //2.- Helper to clear the current selection stored in hidden fields.
        const clearSelection = () => {
          hiddenTitle.value = '';
          hiddenSummary.value = '';
          hiddenUrl.value = '';
          hiddenSource.value = '';
          selectionContainer.textContent = strings.selectionEmpty;
        };

        //3.- Helper to render a status message inside the results list.
        const renderStatus = (message) => {
          resultsContainer.innerHTML = '';
          const div = document.createElement('div');
          div.className = 'pds-recipe-insights-search-admin__status';
          div.textContent = message;
          resultsContainer.appendChild(div);
        };

        //4.- Helper that writes the selected item into hidden inputs.
        const setSelection = (item) => {
          hiddenTitle.value = item.title || '';
          hiddenSummary.value = item.summary || '';
          hiddenUrl.value = item.url || '';
          hiddenSource.value = item.id ? String(item.id) : '';

          selectionContainer.innerHTML = '';
          const wrapperEl = document.createElement('div');
          wrapperEl.className = 'pds-recipe-insights-search-admin__selected';

          const titleEl = document.createElement('strong');
          titleEl.textContent = (strings.selected || '').replace('@title', item.title || '');
          wrapperEl.appendChild(titleEl);

          if (item.summary) {
            const summaryEl = document.createElement('p');
            summaryEl.textContent = item.summary;
            wrapperEl.appendChild(summaryEl);
          }

          if (item.url) {
            const linkEl = document.createElement('a');
            linkEl.href = item.url;
            linkEl.target = '_blank';
            linkEl.rel = 'noopener noreferrer';
            linkEl.textContent = item.url;
            wrapperEl.appendChild(linkEl);
          }

          selectionContainer.appendChild(wrapperEl);
        };

        //5.- Generate markup for each search result and bind selection action.
        const renderResults = (items) => {
          resultsContainer.innerHTML = '';

          if (!Array.isArray(items) || items.length === 0) {
            renderStatus(strings.resultsEmpty);
            return;
          }

          const list = document.createElement('ul');
          list.className = 'pds-recipe-insights-search-admin__list';

          items.forEach((item) => {
            const listItem = document.createElement('li');
            listItem.className = 'pds-recipe-insights-search-admin__item';

            const info = document.createElement('div');
            info.className = 'pds-recipe-insights-search-admin__info';

            const title = document.createElement('h3');
            title.className = 'pds-recipe-insights-search-admin__title';
            title.textContent = item.title || '';
            info.appendChild(title);

            if (item.summary) {
              const summary = document.createElement('p');
              summary.className = 'pds-recipe-insights-search-admin__summary';
              summary.textContent = item.summary;
              info.appendChild(summary);
            }

            const actions = document.createElement('div');
            actions.className = 'pds-recipe-insights-search-admin__actions';

            const selectButton = document.createElement('button');
            selectButton.type = 'button';
            selectButton.className = 'button button--small';
            selectButton.textContent = strings.add;
            selectButton.addEventListener('click', () => {
              setSelection(item);
            });

            actions.appendChild(selectButton);
            listItem.appendChild(info);
            listItem.appendChild(actions);
            list.appendChild(listItem);
          });

          resultsContainer.appendChild(list);
        };

        //6.- Execute the remote search using the provided endpoint.
        const performSearch = () => {
          const query = input.value.trim();
          if (query.length < 3) {
            clearSelection();
            renderStatus(strings.minimum);
            return;
          }

          if (supportsAbort && abortController) {
            abortController.abort();
          }
          abortController = supportsAbort ? new AbortController() : null;

          renderStatus(strings.loading);
          const url = new URL(searchUrl, window.location.origin);
          url.searchParams.set('q', query);
          url.searchParams.set('limit', '10');

          const fetchOptions = {
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
            },
          };
          if (supportsAbort && abortController) {
            fetchOptions.signal = abortController.signal;
          }

          fetch(url.toString(), fetchOptions)
            .then((response) => {
              if (!response.ok) {
                throw new Error('HTTP ' + response.status);
              }
              return response.json();
            })
            .then((json) => {
              if (!json || typeof json !== 'object') {
                throw new Error('Invalid payload');
              }
              const items = Array.isArray(json.data) ? json.data : [];
              renderResults(items);
            })
            .catch((error) => {
              if (error.name === 'AbortError') {
                return;
              }
              clearSelection();
              renderStatus(strings.error);
              console.error('Insights search admin error:', error);
            });
        };

        //7.- Wire up UI interactions for both button click and Enter presses.
        button.addEventListener('click', (event) => {
          event.preventDefault();
          performSearch();
        });

        input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            performSearch();
          }
        });

        clearSelection();
        renderStatus(strings.minimum);
      });
    },
  };
})(Drupal, once, drupalSettings);

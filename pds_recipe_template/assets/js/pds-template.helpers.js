/**
 * PDS Template – tiny helper bundle.
 * Provides: CSRF+fetch wrapper, DOM finders, state utils, error UI, file-widget utils.
 *
 * Global namespace: window.PDSTemplate
 */
(function (window, Drupal) {
  'use strict';

  var __pdsCsrfTokenPromise = null;
  var UPDATE_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

  function getCsrfToken() {
    if (!__pdsCsrfTokenPromise) {
      __pdsCsrfTokenPromise = fetch(Drupal.url('session/token'), {
        credentials: 'same-origin'
      })
        .then(function (r) { if (!r.ok) throw new Error('CSRF token fetch failed'); return r.text(); })
        .catch(function () { return ''; });
    }
    return __pdsCsrfTokenPromise;
  }

  /**
   * Wrapper around fetch that:
   *  - Forces same-origin credentials
   *  - Adds X-Requested-With
   *  - Adds X-CSRF-Token to non-GET/HEAD
   *  - Does NOT auto-JSON decode (call .json() yourself)
   */
  function fetchJSON(url, options) {
    options = options || {};
    var method = (options.method || 'GET').toUpperCase();
    var headers = options.headers || {};
    headers['Accept'] = headers['Accept'] || 'application/json';
    headers['X-Requested-With'] = headers['X-Requested-With'] || 'XMLHttpRequest';
    options.credentials = 'same-origin';

    if (method !== 'GET' && method !== 'HEAD') {
      return getCsrfToken().then(function (token) {
        headers['X-CSRF-Token'] = token;
        options.headers = headers;
        return fetch(url, options);
      });
    }
    options.headers = headers;
    return fetch(url, options);
  }

  function deleteRowViaAjax(root, row) {
    //1.- Resolve the numeric identifier that the backend expects.
    var rowId = null;
    if (row && typeof row.id === 'number') rowId = row.id;
    else if (row && typeof row.id === 'string' && row.id.trim() !== '') {
      var parsed = parseInt(row.id, 10);
      if (!isNaN(parsed)) rowId = parsed;
    }
    if (rowId === null) {
      return Promise.resolve({ success: false, message: 'Missing row identifier.' });
    }
    if (typeof window.fetch !== 'function') {
      // Legacy browsers → treat as success (or customize)
      return Promise.resolve({ success: true });
    }

    var template = root.getAttribute('data-pds-template-delete-row-url');
    if (!template) {
      // No endpoint attached → back-compat no-op
      return Promise.resolve({ success: true });
    }

    //2.- Replace placeholder or fall back to trimming the last path segment.
    var rowIdString = String(rowId);
    var url = template.indexOf(UPDATE_PLACEHOLDER) !== -1
      ? template.replace(UPDATE_PLACEHOLDER, rowIdString)
      : (function () {
        var pieces = template.split('?');
        var base = pieces[0].replace(/\/[^\/]*$/, '');
        var query = pieces.length > 1 ? '?' + pieces.slice(1).join('?') : '';
        return base + '/' + encodeURIComponent(rowIdString) + query;
      })();

    // Carry recipe type so backend can resolve group reliably.
    var recipeType = root.getAttribute('data-pds-template-recipe-type');
    if (recipeType && url.indexOf('type=') === -1) {
      url += (url.indexOf('?') === -1 ? '?' : '&') + 'type=' + encodeURIComponent(recipeType);
    }

    // First try DELETE (with CSRF), then transparently fall back to POST if blocked by proxy/WAF.
    return fetchJSON(url, { method: 'DELETE' })
      .then(function (res) {
        if (!res.ok && (res.status === 405 || res.status === 403)) {
          // Retry once with POST for environments that disallow DELETE.
          return fetchJSON(url, { method: 'POST' });
        }
        return res;
      })
      .then(function (res) {
        if (!res || !res.ok) throw new Error('Request failed');
        return res.json();
      })
      .then(function (json) {
        if (json && json.status === 'ok') {
          return { success: true, deleted: json.deleted || 0 };
        }
        return { success: false, message: (json && json.message) || 'Unable to delete row.' };
      })
      .catch(function () {
        return { success: false, message: 'Unable to delete row. Please try again.' };
      });
  }

  // ---- DOM helpers ----
  function findField(root, opts) {
    var el;
    if (opts.ds && (el = root.querySelector('[data-drupal-selector="' + opts.ds + '"]'))) return el;
    if (opts.id && (el = root.querySelector('#' + opts.id))) return el;
    if (opts.nameEnd) {
      var list = root.querySelectorAll('input[name$="' + opts.nameEnd + '"], textarea[name$="' + opts.nameEnd + '"]');
      if (list.length) return list[0];
    }
    return null;
  }
  function sel(root, ds, idFallback) {
    var el = ds ? root.querySelector('[data-drupal-selector="' + ds + '"]') : null;
    if (!el && idFallback) el = root.querySelector('#' + idFallback);
    return el;
  }
  function selAll(root, css) {
    return Array.prototype.slice.call(root.querySelectorAll(css));
  }
  function escapeHtml(str) {
    if (str === undefined || str === null) return '';
    return String(str)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  // ---- Error UI helpers ----
  function ensureErrorContainer(root) {
    var c = root.querySelector('[data-pds-template-error]');
    if (c) return c;
    c = document.createElement('div');
    c.setAttribute('data-pds-template-error','1');
    c.className = 'pds-template-admin__errors';
    c.setAttribute('role','alert');
    c.style.display = 'none';
    root.firstChild ? root.insertBefore(c, root.firstChild) : root.appendChild(c);
    return c;
  }
  function clearError(root) {
    var c = ensureErrorContainer(root);
    c.textContent = '';
    c.style.display = 'none';
  }
  function showError(root, message) {
    var c = ensureErrorContainer(root);
    c.textContent = message || 'Unable to save row. Please try again.';
    c.style.display = '';
  }

  // ---- State helpers ----
  function readState(root) {
    var el = findField(root, { ds:'pds-template-cards-state', id:'pds-template-cards-state', nameEnd:'[cards_state]' });
    if (!el) return [];
    try { return el.value ? JSON.parse(el.value) : []; } catch (e) { return []; }
  }
  function writeState(root, arr) {
    var el = findField(root, { ds:'pds-template-cards-state', id:'pds-template-cards-state', nameEnd:'[cards_state]' });
    if (el) el.value = JSON.stringify(arr);
  }
  function readEditIndex(root) {
    var el = findField(root, { ds:'pds-template-edit-index', id:'pds-template-edit-index', nameEnd:'[edit_index]' });
    if (!el) return -1;
    var v = parseInt(el.value, 10);
    return isNaN(v) ? -1 : v;
  }
  function writeEditIndex(root, idx) {
    var el = findField(root, { ds:'pds-template-edit-index', id:'pds-template-edit-index', nameEnd:'[edit_index]' });
    if (el) el.value = String(idx);
  }
  function applyGroupIdToDom(root, groupId) {
    if (!groupId && groupId !== 0) return;
    root.setAttribute('data-pds-template-group-id', String(groupId));
    root._pdsTemplateGroupId = groupId;
    var hidden = findField(root, { ds:'pds-template-group-id', id:'pds-template-group-id', nameEnd:'[group_id]' });
    if (hidden) hidden.value = String(groupId);
  }

  // ---- Managed file helpers ----
  function initFileWidgetTemplate(root) {
    var w = root.querySelector('.js-form-managed-file.form-managed-file.is-single');
    if (!w) return;
    if (!w._pdsPristineHTML) {
      w._pdsPristineHTML = w.innerHTML;
      w._pdsPristineClasses = w.className;
    }
  }
  function resetFileWidgetToPristine(root) {
    var w = root.querySelector('.js-form-managed-file.form-managed-file.is-single');
    if (!w) return;
    if (w._pdsPristineHTML && w._pdsPristineClasses) {
      w.innerHTML = w._pdsPristineHTML;
      w.className = w._pdsPristineClasses;
      w.classList.add('no-value');
      w.classList.remove('has-value');
      var hid = w.querySelector('input[type="hidden"][name$="[image][fids]"]');
      if (hid) hid.value = '';
    } else {
      var hid2 = w.querySelector('input[type="hidden"][name$="[image][fids]"]');
      if (hid2) hid2.value = '';
      w.classList.add('no-value');
      w.classList.remove('has-value');
      w.classList.remove('no-upload');
    }
    if (typeof Drupal !== 'undefined' && typeof Drupal.attachBehaviors === 'function') {
      if (typeof drupalSettings !== 'undefined') { Drupal.attachBehaviors(w, drupalSettings); }
      else { Drupal.attachBehaviors(w); }
    }
  }
  function getImageFid(root) {
    var w = root.querySelector('#pds-template-image,[id^="pds-template-image"],[data-drupal-selector$="image"]');
    if (!w) return null;
    var possible = w.querySelectorAll('input[type="hidden"]');
    for (var i = 0; i < possible.length; i++) {
      var v = possible[i].value;
      if (v && /^[0-9]+$/.test(v)) return v;
    }
    return null;
  }

  // ---- Misc helpers ----
  function normalizeTimelineEntries(source) {
    if (!Array.isArray(source) || !source.length) return [];
    var out = [];
    source.forEach(function (e, pos) {
      if (!e || typeof e !== 'object') return;
      var label = (typeof e.label !== 'undefined' ? String(e.label) : '').trim();
      if (!label) return;
      var year = typeof e.year === 'number' ? e.year : parseInt(e.year, 10);
      year = isNaN(year) ? 0 : year;
      var weight = typeof e.weight === 'number' ? e.weight : parseInt(e.weight, 10);
      weight = isNaN(weight) ? pos : weight;
      out.push({ year: year, label: label, weight: weight });
    });
    out.sort(function (a,b){ return (a.weight||0)-(b.weight||0); });
    out.forEach(function (it,i){ it.weight = i; });
    return out;
  }

  // Public API
  window.PDSTemplate = {
    getCsrfToken: getCsrfToken,
    fetchJSON: fetchJSON,

    deleteRowViaAjax: deleteRowViaAjax,

    findField: findField,
    sel: sel,
    selAll: selAll,
    escapeHtml: escapeHtml,

    ensureErrorContainer: ensureErrorContainer,
    clearError: clearError,
    showError: showError,

    readState: readState,
    writeState: writeState,
    readEditIndex: readEditIndex,
    writeEditIndex: writeEditIndex,
    applyGroupIdToDom: applyGroupIdToDom,

    initFileWidgetTemplate: initFileWidgetTemplate,
    resetFileWidgetToPristine: resetFileWidgetToPristine,
    getImageFid: getImageFid,

    normalizeTimelineEntries: normalizeTimelineEntries
  };

})(window, Drupal);

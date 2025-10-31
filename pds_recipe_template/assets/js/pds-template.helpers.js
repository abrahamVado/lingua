/**
 * PDS Template – tiny helper bundle.
 * Provides: CSRF+fetch wrapper, DOM finders, state utils, error UI, file-widget utils.
 *
 * Global namespace: window.PDSTemplate
 */
(function (window, Drupal) {
  'use strict';

  var __pdsCsrfTokenPromise = null;

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

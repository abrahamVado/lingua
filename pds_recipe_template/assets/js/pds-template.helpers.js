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

  function resolveNumericId(candidate) {
    //1.- Accept numbers that are already valid identifiers.
    if (typeof candidate === 'number' && !isNaN(candidate)) {
      return candidate;
    }
    //2.- Attempt to parse string representations into integers.
    if (typeof candidate === 'string') {
      var trimmed = candidate.trim();
      if (trimmed === '') return null;
      var parsed = parseInt(trimmed, 10);
      if (!isNaN(parsed)) {
        return parsed;
      }
    }
    //3.- Fallback to null when the input cannot be coerced.
    return null;
  }

  function normalizeRowIdentifiers(row) {
    //1.- Ensure we always work with an object clone to avoid side effects.
    if (!row || typeof row !== 'object') {
      return {};
    }
    var normalized = Object.assign({}, row);
    //2.- Normalize the primary id to a numeric value when available.
    var numericId = resolveNumericId(normalized.id);
    if (numericId !== null) {
      normalized.id = numericId;
    } else if (typeof normalized.id === 'string') {
      var trimmedId = normalized.id.trim();
      if (trimmedId) {
        normalized.id = trimmedId;
      } else {
        delete normalized.id;
      }
    } else if (normalized.id !== undefined) {
      delete normalized.id;
    }
    //3.- Trim uuid fallbacks so stale whitespace does not leak through.
    if (typeof normalized.uuid === 'string') {
      var trimmedUuid = normalized.uuid.trim();
      if (trimmedUuid) {
        normalized.uuid = trimmedUuid;
      } else {
        delete normalized.uuid;
      }
    } else if (normalized.uuid !== undefined) {
      delete normalized.uuid;
    }
    //4.- Return the sanitized snapshot for downstream consumers.
    return normalized;
  }

  function resolveRowIdentifier(row) {
    //1.- Normalize the row payload before attempting any resolution.
    var normalized = normalizeRowIdentifiers(row);
    //2.- Prefer numeric identifiers whenever possible.
    if (typeof normalized.id === 'number') {
      return { type: 'id', value: normalized.id, key: 'id:' + normalized.id, row: normalized };
    }
    //3.- Fall back to UUIDs only when an id is completely unavailable.
    if (typeof normalized.uuid === 'string' && normalized.uuid !== '') {
      return { type: 'uuid', value: normalized.uuid, key: 'uuid:' + normalized.uuid, row: normalized };
    }
    //4.- Provide the normalized row even if no identifier was resolved.
    return { type: null, value: null, key: '', row: normalized };
  }

  function buildRowActionUrl(template, identifier) {
    //1.- Guard against missing templates so callers can short-circuit gracefully.
    if (!template || typeof template !== 'string') {
      return '';
    }
    //2.- Convert the identifier into a string representation for transport.
    var idString = identifier === 0 || identifier ? String(identifier) : '';
    if (!idString) {
      return template;
    }
    //3.- Replace placeholders when the backend provided a template stub.
    if (template.indexOf(UPDATE_PLACEHOLDER) !== -1) {
      return template.replace(UPDATE_PLACEHOLDER, idString);
    }
    //4.- Fallback by rewriting the trailing segment with the identifier.
    var parts = template.split('?');
    var base = parts[0].replace(/\/[^\/]*$/, '');
    var query = parts.length > 1 ? '?' + parts.slice(1).join('?') : '';
    return base + '/' + encodeURIComponent(idString) + query;
  }

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
    //1.- Resolve the preferred identifier, defaulting to numeric ids.
    var resolution = resolveRowIdentifier(row);
    if (!resolution.value && resolution.value !== 0) {
      return Promise.resolve({ success: false, message: 'Missing row identifier.' });
    }
    //2.- Continue using the resolved identifier when rewriting the endpoint URL.
    if (typeof window.fetch !== 'function') {
      // Legacy browsers → treat as success (or customize)
      return Promise.resolve({ success: true });
    }

    var template = root.getAttribute('data-pds-template-delete-row-url');
    if (!template) {
      // No endpoint attached → back-compat no-op
      return Promise.resolve({ success: true });
    }

    //3.- Replace placeholder or fall back to trimming the last path segment.
    var url = buildRowActionUrl(template, resolution.value);

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
    var ensureUrl = root.getAttribute('data-pds-template-ensure-group-url');
    if (ensureUrl && typeof ensureUrl === 'string') {
      var rewritten = ensureUrl.replace(/(ensure-group\/)(\d+)/, '$1' + groupId);
      if (rewritten !== ensureUrl) {
        root.setAttribute('data-pds-template-ensure-group-url', rewritten);
      }
    }
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

    normalizeTimelineEntries: normalizeTimelineEntries,

    // Identifier helpers shared across admin bundles.
    resolveNumericId: resolveNumericId,
    normalizeRowIdentifiers: normalizeRowIdentifiers,
    resolveRowIdentifier: resolveRowIdentifier,
    buildRowActionUrl: buildRowActionUrl
  };

})(window, Drupal);

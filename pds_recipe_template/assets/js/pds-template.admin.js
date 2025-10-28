(function (Drupal, once) {
  'use strict';

  /* global drupalSettings */

  //
  // DOM helpers
  //

  // Generic finder for problem Drupal markup.
  // Tries data-drupal-selector, then id, then name suffix.
  function findField(root, opts) {
    // opts.ds      expected data-drupal-selector fragment, e.g. 'pds-template-cards-state'
    // opts.id      expected id, e.g. 'pds-template-cards-state'
    // opts.nameEnd required tail of name attr, e.g. '[cards_state]' or '[edit_index]'

    var el;

    if (opts.ds) {
      el = root.querySelector('[data-drupal-selector="' + opts.ds + '"]');
      if (el) return el;
    }

    if (opts.id) {
      el = root.querySelector('#' + opts.id);
      if (el) return el;
    }

    if (opts.nameEnd) {
      var list = root.querySelectorAll(
        'input[name$="' + opts.nameEnd + '"], textarea[name$="' + opts.nameEnd + '"]'
      );
      if (list.length) return list[0];
    }

    return null;
  }

  function sel(root, drupalSelector, idFallback) {
    var el = null;
    if (drupalSelector) {
      el = root.querySelector('[data-drupal-selector="' + drupalSelector + '"]');
    }
    if (!el && idFallback) {
      el = root.querySelector('#' + idFallback);
    }
    return el;
  }

  function selAll(root, css) {
    return Array.prototype.slice.call(root.querySelectorAll(css));
  }

  function escapeHtml(str) {
    if (str === undefined || str === null) {
      return '';
    }
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  //
  // State helpers
  //

  function readState(root) {
    var el = findField(root, {
      ds: 'pds-template-cards-state',
      id: 'pds-template-cards-state',
      nameEnd: '[cards_state]'
    });
    if (!el) {
      return [];
    }
    try {
      return el.value ? JSON.parse(el.value) : [];
    } catch (e) {
      return [];
    }
  }

  function writeState(root, arr) {
    var el = findField(root, {
      ds: 'pds-template-cards-state',
      id: 'pds-template-cards-state',
      nameEnd: '[cards_state]'
    });
    if (el) {
      el.value = JSON.stringify(arr);
    }
  }

  function readEditIndex(root) {
    var el = findField(root, {
      ds: 'pds-template-edit-index',
      id: 'pds-template-edit-index',
      nameEnd: '[edit_index]'
    });
    if (!el) {
      return -1;
    }
    var v = parseInt(el.value, 10);
    return isNaN(v) ? -1 : v;
  }

  function writeEditIndex(root, idx) {
    var el = findField(root, {
      ds: 'pds-template-edit-index',
      id: 'pds-template-edit-index',
      nameEnd: '[edit_index]'
    });
    if (el) {
      el.value = String(idx);
    }
  }

  function applyGroupIdToDom(root, groupId) {
    //1.- Persist the resolved id on the root wrapper for quick subsequent checks.
    if (!groupId && groupId !== 0) {
      return;
    }
    root.setAttribute('data-pds-template-group-id', String(groupId));
    root._pdsTemplateGroupId = groupId;

    //2.- Mirror the value into the hidden field so Drupal receives it on submit.
    var hidden = findField(root, {
      ds: 'pds-template-group-id',
      id: 'pds-template-group-id',
      nameEnd: '[group_id]'
    });
    if (hidden) {
      hidden.value = String(groupId);
    }
  }

  function ensureGroupExists(root) {
    //1.- Abort when fetch is unavailable or the backend endpoint was not provided.
    if (typeof window.fetch !== 'function') {
      return Promise.resolve(null);
    }

    var url = root.getAttribute('data-pds-template-ensure-group-url');
    if (!url) {
      return Promise.resolve(null);
    }

    //2.- Skip duplicate requests once a successful confirmation already ran.
    if (root._pdsGroupEnsured) {
      return Promise.resolve(root._pdsTemplateGroupId || null);
    }

    //3.- Perform the POST call so the group record is guaranteed before saving.
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({})
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Request failed');
        }
        return response.json();
      })
      .then(function (json) {
        if (json && typeof json.group_id === 'number') {
          applyGroupIdToDom(root, json.group_id);
          root._pdsGroupEnsured = true;
          return json.group_id;
        }
        return null;
      })
      .catch(function () {
        return null;
      });
  }

  function getImageFid(root) {
    // Wrapper from managed_file. Drupal mutates the id so we match prefix.
    var wrapper = root.querySelector('#pds-template-image,[id^="pds-template-image"],[data-drupal-selector$="image"]');
    if (!wrapper) {
      return null;
    }
    var possible = wrapper.querySelectorAll('input[type="hidden"]');
    for (var i = 0; i < possible.length; i++) {
      var val = possible[i].value;
      if (val && /^[0-9]+$/.test(val)) {
        return val;
      }
    }
    return null;
  }

  //
  // Form input helpers
  //
  function triggerManagedFileRemove(root) {
    // The wrapper ID is unstable, so match by class and by the data-drupal-selector on inner elements.
    // We specifically look for the "is-single" managed_file in panel A.
    var wrapper = root.querySelector(
      '.js-form-managed-file.form-managed-file.is-single'
    );
    if (!wrapper) {
      return;
    }

    // In the uploaded state you showed, Drupal sets:
    //  - class="remove-button button js-form-submit form-submit"
    //  - data-drupal-selector="...-remove-button"
    // We target that.
    var removeBtn = wrapper.querySelector(
      '.remove-button.button.js-form-submit.form-submit,' +
      '[data-drupal-selector$="-remove-button"]'
    );

    if (!removeBtn) {
      return;
    }

    // Build a "real" click event so Drupal's drupal-ajax binding runs.
    var evt = new MouseEvent('click', {
      bubbles: true,
      cancelable: true,
      view: window
    });

    removeBtn.dispatchEvent(evt);
  }




function clearInputs(root) {
  var f;
  f = sel(root, 'pds-template-header', 'pds-template-header'); if (f) f.value = '';
  f = sel(root, 'pds-template-subheader', 'pds-template-subheader'); if (f) f.value = '';
  f = sel(root, 'pds-template-description', 'pds-template-description'); if (f) f.value = '';
  f = sel(root, 'pds-template-link', 'pds-template-link'); if (f) f.value = '';
}



  function loadInputsFromRow(root, row) {
    var f;
    f = sel(root, 'pds-template-header', 'pds-template-header'); if (f) f.value = row.header || '';
    f = sel(root, 'pds-template-subheader', 'pds-template-subheader'); if (f) f.value = row.subheader || '';
    f = sel(root, 'pds-template-description', 'pds-template-description'); if (f) f.value = row.description || '';
    f = sel(root, 'pds-template-link', 'pds-template-link'); if (f) f.value = row.link || '';
  }

  function buildRowFromInputs(root, existingRow) {
    existingRow = existingRow || {};

    var headerEl = sel(root, 'pds-template-header', 'pds-template-header');
    var subEl    = sel(root, 'pds-template-subheader', 'pds-template-subheader');
    var descEl   = sel(root, 'pds-template-description', 'pds-template-description');
    var linkEl   = sel(root, 'pds-template-link', 'pds-template-link');

    var header      = headerEl ? headerEl.value : '';
    var subheader   = subEl ? subEl.value : '';
    var description = descEl ? descEl.value : '';
    var link        = linkEl ? linkEl.value : '';
    var fid         = getImageFid(root);

    var latValue = (typeof existingRow.latitud !== 'undefined') ? existingRow.latitud : null;
    var lngValue = (typeof existingRow.longitud !== 'undefined') ? existingRow.longitud : null;

    var baseRow = {
      header: header,
      subheader: subheader,
      description: description,
      link: link,
      image_fid: fid || existingRow.image_fid || null,
      image_url: existingRow.image_url || existingRow.desktop_img || existingRow.mobile_img || '',
      desktop_img: existingRow.desktop_img || existingRow.image_url || '',
      mobile_img: existingRow.mobile_img || existingRow.image_url || '',
      latitud: latValue,
      longitud: lngValue
    };

    if (fid) {
      //1.- Reset URLs when a fresh upload has been selected so the resolver can repopulate them.
      baseRow.image_url = '';
      baseRow.desktop_img = '';
      baseRow.mobile_img = '';
    }

    return baseRow;
  }

  function resolveRowViaAjax(root, row) {
    //1.- Always return a promise so callers can await the outcome uniformly.
    return new Promise(function (resolve) {
      if (!row) {
        resolve(row);
        return;
      }

      if (typeof window.fetch !== 'function') {
        //2.- Older browsers simply skip the pre-save promotion and keep the temp state.
        resolve(row);
        return;
      }

      var url = root.getAttribute('data-pds-template-resolve-row-url');
      if (!url) {
        //3.- Without a backend endpoint we leave the payload untouched for backwards compatibility.
        resolve(row);
        return;
      }

      //4.- Skip the request when there is no fid and the URLs are already stable.
      if (!row.image_fid) {
        resolve(row);
        return;
      }

      fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ row: row })
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Request failed');
          }
          return response.json();
        })
        .then(function (json) {
          if (!json || json.status !== 'ok') {
            resolve(row);
            return;
          }

          if (Object.prototype.hasOwnProperty.call(json, 'image_fid')) {
            row.image_fid = json.image_fid;
          }
          if (json.image_url) {
            row.image_url = json.image_url;
          }
          if (json.desktop_img) {
            row.desktop_img = json.desktop_img;
          }
          if (json.mobile_img) {
            row.mobile_img = json.mobile_img;
          }

          if (!row.image_url && json.desktop_img) {
            //6.- Guarantee backward compatibility fields even when the backend omits image_url.
            row.image_url = json.desktop_img;
          }

          resolve(row);
        })
        .catch(function () {
          //5.- Network issues should not block the UX; keep the previous state intact.
          resolve(row);
        });
    });
  }

  //
  // Preview table
  //

  function renderPreviewTable(root) {
    var wrapper = root.querySelector('#pds-template-preview-list');
    if (!wrapper) {
      return;
    }

    var rows = readState(root);

    if (!rows.length) {
      wrapper.innerHTML =
        '<table class="pds-template-table">' +
          '<thead>' +
            '<tr>' +
              '<th class="pds-template-table__col-thumb">Image</th>' +
              '<th>Header</th>' +
              '<th>Subheader</th>' +
              '<th>Link</th>' +
              '<th>Actions</th>' +
            '</tr>' +
          '</thead>' +
          '<tbody>' +
            '<tr>' +
              '<td colspan="5"><em>No rows yet.</em></td>' +
            '</tr>' +
          '</tbody>' +
        '</table>';
      return;
    }

    var html = '';
    html += '<table class="pds-template-table">';
    html +=   '<thead>';
    html +=     '<tr>';
    html +=       '<th class="pds-template-table__col-thumb">Image</th>';
    html +=       '<th>Header</th>';
    html +=       '<th>Subheader</th>';
    html +=       '<th>Link</th>';
    html +=       '<th>Actions</th>';
    html +=     '</tr>';
    html +=   '</thead>';
    html +=   '<tbody>';

    for (var i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      //1.- Resolve the thumbnail URL while respecting both upload and remote sources.
      var thumb = r.desktop_img || r.image_url || '';

      html += '<tr data-row-index="' + i + '">';
      html +=   '<td class="pds-template-table__thumb">' +
        (thumb ? '<img src="' + escapeHtml(thumb) + '" alt="" />' : '') +
      '</td>';
      html +=   '<td>' + escapeHtml(r.header || '') + '</td>';
      html +=   '<td>' + escapeHtml(r.subheader || '') + '</td>';
      html +=   '<td>' + escapeHtml(r.link || '') + '</td>';
      html +=   '<td>';
      html +=     '<button type="button" class="pds-template-row-edit">Edit</button> ';
      html +=     '<button type="button" class="pds-template-row-del">Delete</button>';
      html +=   '</td>';
      html += '</tr>';
    }

    html +=   '</tbody>';
    html += '</table>';

    wrapper.innerHTML = html;

    bindRowButtons(root, wrapper);
  }

  function bindRowButtons(root, wrapper) {
    selAll(wrapper, '.pds-template-row-edit').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var idx = parseInt(this.closest('tr').getAttribute('data-row-index'), 10);
        startEditRow(root, idx);
      });
    });

    selAll(wrapper, '.pds-template-row-del').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var idx = parseInt(this.closest('tr').getAttribute('data-row-index'), 10);
        deleteRow(root, idx);
      });
    });
  }

  //
  // Row ops
  //

  function startEditRow(root, idx) {
    var rows = readState(root);
    if (idx < 0 || idx >= rows.length) {
      return;
    }

    loadInputsFromRow(root, rows[idx]);
    writeEditIndex(root, idx);

    var btn = sel(root, 'pds-template-add-card', 'pds-template-add-card');
    if (btn) {
      btn.textContent = 'Save changes';
    }

    activateTab(root, 'panel-a');
  }

  function deleteRow(root, idx) {
    var rows = readState(root);
    if (idx < 0 || idx >= rows.length) {
      return;
    }

    rows.splice(idx, 1);
    writeState(root, rows);

    writeEditIndex(root, -1);

    var btn = sel(root, 'pds-template-add-card', 'pds-template-add-card');
    if (btn) {
      btn.textContent = 'Add row';
    }

    renderPreviewTable(root);
  }

function commitRow(root) {
  var rows = readState(root);
  var idx = readEditIndex(root);
  var existingRow = (idx >= 0 && idx < rows.length) ? rows[idx] : null;
  var newRow = buildRowFromInputs(root, existingRow);

  // Require header to avoid empty rows.
  if (!newRow.header || !newRow.header.trim()) {
    return Promise.resolve(false);
  }

  return resolveRowViaAjax(root, newRow).then(function (resolvedRow) {
    var currentRows = readState(root);
    if (idx >= 0 && idx < currentRows.length) {
      currentRows[idx] = resolvedRow;
    } else {
      currentRows.push(resolvedRow);
    }

    writeState(root, currentRows);
    writeEditIndex(root, -1);

    var btn = sel(root, 'pds-template-add-card', 'pds-template-add-card');
    if (btn) {
      btn.textContent = 'Add row';
    }

    // Clear text inputs.
    clearInputs(root);

    // Reset the managed_file widget to pristine so next row can upload a new image.
    resetFileWidgetToPristine(root);

    // Refresh preview table and show Tab B.
    renderPreviewTable(root);
    activateTab(root, 'panel-b');

    return true;
  });
}

function handleAddOrUpdateRow(root) {
  //1.- Capture counts before and after so we know when the first row arrives.
  var beforeCount = readState(root).length;

  commitRow(root).then(function (didCommit) {
    if (!didCommit) {
      return;
    }

    var afterCount = readState(root).length;

    if (beforeCount === 0 && afterCount > 0) {
      //2.- As soon as the first row exists we ask the backend to ensure the group.
      ensureGroupExists(root);
    }
  });
}


function initFileWidgetTemplate(root) {
  //1.- Locate the managed_file wrapper for panel A so we can snapshot its pristine markup.
  var wrapper = root.querySelector(
    '.js-form-managed-file.form-managed-file.is-single'
  );
  if (!wrapper) {
    return;
  }

  //2.- Cache the clean upload state only once so later restores always return to a valid baseline.
  if (!wrapper._pdsPristineHTML) {

    // Store the full innerHTML and the classList snapshot.
    wrapper._pdsPristineHTML = wrapper.innerHTML;
    wrapper._pdsPristineClasses = wrapper.className;
  }
}

function resetFileWidgetToPristine(root) {
  //1.- Locate the managed_file wrapper that needs to be cleared.
  var wrapper = root.querySelector(
    '.js-form-managed-file.form-managed-file.is-single'
  );
  if (!wrapper) {
    return;
  }

  //2.- Restore the previously captured pristine markup when available.
  if (wrapper._pdsPristineHTML && wrapper._pdsPristineClasses) {
    wrapper.innerHTML = wrapper._pdsPristineHTML;

    //3.- Reinstate the wrapper classes and force the "no-value" state to keep UI consistent.
    wrapper.className = wrapper._pdsPristineClasses;

    //4.- Clear any stray fid leftovers from previous uploads.
    // Safety: make sure no-value not has-value.
    wrapper.classList.add('no-value');
    wrapper.classList.remove('has-value');

    // Wipe any hidden fid again just in case.
    var fidHidden = wrapper.querySelector(
      'input[type="hidden"][name$="[image][fids]"]'
    );
    if (fidHidden) {
      fidHidden.value = '';
    }
  } else {
    //2.- Fallback: when no snapshot exists, perform a best-effort manual reset.
    // just clear fid and strip has-value/no-upload so Drupal shows file input
    var fidHidden2 = wrapper.querySelector(
      'input[type="hidden"][name$="[image][fids]"]'
    );
    if (fidHidden2) {
      fidHidden2.value = '';
    }
    wrapper.classList.add('no-value');
    wrapper.classList.remove('has-value');
    wrapper.classList.remove('no-upload');
  }

  //5.- Re-run Drupal behaviors so AJAX bindings return to the rebuilt widget.
  if (typeof Drupal !== 'undefined' && typeof Drupal.attachBehaviors === 'function') {
    if (typeof drupalSettings !== 'undefined') {
      Drupal.attachBehaviors(wrapper, drupalSettings);
    } else {
      Drupal.attachBehaviors(wrapper);
    }
  }
}


function resetManagedFileManually(wrapper) {
  // 1. wipe hidden fid so next row does not inherit
  var fidHidden = wrapper.querySelector('input[type="hidden"][name$="[image][fids]"]');
  if (fidHidden) {
    fidHidden.value = '';
  }

  // 2. replace <input type="file"> with a fresh clone to clear filename
  var fileInput = wrapper.querySelector('input[type="file"]');
  if (fileInput && fileInput.parentNode) {
    var clone = fileInput.cloneNode(true);
    fileInput.parentNode.replaceChild(clone, fileInput);
    // unhide upload UI in case theme hid it
    var main = clone.closest('.form-managed-file__main');
    if (main) {
      main.classList.remove('is-hidden');
    }
    clone.style.display = '';
    clone.disabled = false;
  }

  // 3. hide preview/meta block from last upload if it exists
  var previewRegion = wrapper.querySelector('.file, .file--image, .form-managed-file__meta');
  if (previewRegion) {
    previewRegion.style.display = 'none';
  }

  // 4. normalize classes so widget looks empty
  wrapper.classList.add('no-value');
  wrapper.classList.remove('has-value');
}

// Try AJAX "Remove". If found, click it. Otherwise fall back to manual reset.
function rebuildManagedFileEmpty(root) {
  //1.- Locate the managed_file wrapper that needs to be rebuilt from scratch.
  var wrapper = root.querySelector(
    '#pds-template-image,' +
    '[id^="pds-template-image"],' +
    '[data-drupal-selector$="panel-a-image"],' +
    '.form-managed-file.is-single'
  );
  if (!wrapper) {
    return;
  }

  //2.- Gather the original dynamic attributes so Drupal recognizes the widget on submit.

  // Hidden fid input. We keep its name attr because Drupal expects that exact name on submit.
  var fidHidden = wrapper.querySelector('input[type="hidden"][name$="[image][fids]"]');

  var fidName = '';
  if (fidHidden) {
    fidName = fidHidden.getAttribute('name');
  } else {
    // If it's gone, guess by looking for [image][fids] pattern anywhere.
    var anyHidden = wrapper.querySelector('input[type="hidden"][name*="[image][fids]"]');
    if (anyHidden) {
      fidName = anyHidden.getAttribute('name');
    }
  }

  //3.- Recover or reconstruct the file input markup needed for the AJAX upload control.
  // File input. We grab its name/id/data-drupal-selector so Drupal upload still works for next file.
  // Note: After upload Drupal often *removes* the file input. If it's gone we can't read these.
  // In that case we try to recover from previous clone we inserted. If still not found we bail.
  var fileInput = wrapper.querySelector('input[type="file"]');

  // If fileInput is missing because Drupal replaced it with preview-only mode,
  // look for the last known markup sibling in DOM of wrapper via dataset cache.
  // We store a backup snapshot on first run.
  if (!fileInput && wrapper._pdsFilePrototype) {
    fileInput = wrapper._pdsFilePrototype.cloneNode(true);
  }

  // If still nothing we give up because we cannot guess Drupal's field name.
  if (!fileInput) {
    return;
  }

  // Cache prototype for future runs (first time we see a real input).
  if (!wrapper._pdsFilePrototype) {
    wrapper._pdsFilePrototype = fileInput.cloneNode(true);
  }

  var fileNameAttr = fileInput.getAttribute('name') || '';
  var fileIdAttr = fileInput.getAttribute('id') || '';
  var fileDSAttr = fileInput.getAttribute('data-drupal-selector') || '';
  var fileClasses = fileInput.getAttribute('class') || '';
  var fileDataOnce = fileInput.getAttribute('data-once') || '';

  // We also try to recover the Upload button attributes so AJAX upload still works.
  var uploadBtn = wrapper.querySelector('input[type="submit"][value*="Upload"], input[type="submit"][value*="upload"]');
  var uploadName = '';
  var uploadId = '';
  var uploadDS = '';
  var uploadClasses = '';
  var uploadDataOnce = '';
  if (uploadBtn) {
    uploadName = uploadBtn.getAttribute('name') || '';
    uploadId = uploadBtn.getAttribute('id') || '';
    uploadDS = uploadBtn.getAttribute('data-drupal-selector') || '';
    uploadClasses = uploadBtn.getAttribute('class') || '';
    uploadDataOnce = uploadBtn.getAttribute('data-once') || '';
  }

  //4.- Rebuild the wrapper's inner HTML to mimic Drupal's empty managed_file state.

  var html = '';
  html += '<div class="form-managed-file__main">';
  html +=   '<input type="file"'
         +   (fileIdAttr ? ' id="' + fileIdAttr + '"' : '')
         +   (fileDSAttr ? ' data-drupal-selector="' + fileDSAttr + '"' : '')
         +   (fileNameAttr ? ' name="' + fileNameAttr + '"' : '')
         +   ' class="' + fileClasses + '"'
         +   (fileDataOnce ? ' data-once="' + fileDataOnce + '"' : '')
         + '>';
  if (uploadName || uploadId) {
    html += '<input type="submit"'
         +   (uploadId ? ' id="' + uploadId + '"' : '')
         +   (uploadDS ? ' data-drupal-selector="' + uploadDS + '"' : '')
         +   (uploadName ? ' name="' + uploadName + '"' : '')
         +   ' value="Upload"'
         +   ' class="' + uploadClasses + '"'
         +   (uploadDataOnce ? ' data-once="' + uploadDataOnce + '"' : '')
         + '>';
  }
  html += '</div>';

  if (fidName) {
    html += '<input type="hidden" name="' + fidName + '" value="">';
  }

  wrapper.innerHTML = html;

  //5.- Force the visual state to "no file" so the UI renders correctly.
  wrapper.classList.add('no-value');
  wrapper.classList.remove('has-value');

  //6.- Re-run Drupal behaviors so the recreated markup gains the expected AJAX wiring.
  if (typeof Drupal !== 'undefined' && typeof Drupal.attachBehaviors === 'function') {
    if (typeof drupalSettings !== 'undefined') {
      Drupal.attachBehaviors(wrapper, drupalSettings);
    } else {
      Drupal.attachBehaviors(wrapper);
    }
  }
}

  //
  // Tabs
  //

  function activateTab(root, panelId) {
    selAll(root, '[data-pds-template-tab-target]').forEach(function (btn) {
      var match = (btn.getAttribute('data-pds-template-tab-target') === panelId);
      if (match) {
        btn.classList.add('is-active');
      } else {
        btn.classList.remove('is-active');
      }
    });

    selAll(root, '.js-pds-template-panel').forEach(function (panel) {
      var match = (panel.getAttribute('data-pds-template-panel-id') === panelId);
      if (match) {
        panel.classList.add('is-active');
      } else {
        panel.classList.remove('is-active');
      }
    });
  }

  function initTabs(root) {
    selAll(root, '[data-pds-template-tab-target]').forEach(function (btn) {
      btn.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var targetId = btn.getAttribute('data-pds-template-tab-target');
        activateTab(root, targetId);
      });
    });

    // Default to first tab.
    var firstBtn = root.querySelector('[data-pds-template-tab-target]');
    if (firstBtn) {
      activateTab(root, firstBtn.getAttribute('data-pds-template-tab-target'));
    }
  }

  //
  // Behavior attach
  //

  Drupal.behaviors.pdsTemplateAdmin = {
    attach: function (context) {
      once('pdsTemplateAdminRoot', '.js-pds-template-admin', context).forEach(function (root) {

        // Tabs
        initTabs(root);

        // Initial preview from hidden state
        renderPreviewTable(root);

        //1.- Snapshot the pristine managed file widget so we can fully reset it after commits.
        initFileWidgetTemplate(root);

        //2.- Cache any precomputed group id exposed by PHP so fetch handler can reuse it.
        var existingGroupIdAttr = root.getAttribute('data-pds-template-group-id');
        if (existingGroupIdAttr) {
          var parsedExistingId = parseInt(existingGroupIdAttr, 10);
          if (!isNaN(parsedExistingId)) {
            root._pdsTemplateGroupId = parsedExistingId;
          }
        }

        // Add row / Save changes button.
        // Accept <a ... data-drupal-selector="pds-template-add-card"> or <div ... same attrs>.
        var addBtn =
          root.querySelector('[data-drupal-selector="pds-template-add-card"], #pds-template-add-card');

        if (addBtn) {
          // click
          addBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            handleAddOrUpdateRow(root);
          });
          // keyboard support for div/span with role="button"
          addBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              e.stopPropagation();
              handleAddOrUpdateRow(root);
            }
          });
        }

      });
    }
  };

})(Drupal, once);

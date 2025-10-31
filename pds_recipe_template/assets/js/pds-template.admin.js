(function (Drupal, once, PDSTemplate) {
  'use strict';

  /* global drupalSettings */

  var UPDATE_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

  //
  // Error helpers (from helper)
  //
  var clearError = PDSTemplate.clearError;
  var showError  = PDSTemplate.showError;

  //
  // DOM helpers (from helper)
  //
  var findField = PDSTemplate.findField;
  var sel       = PDSTemplate.sel;
  var selAll    = PDSTemplate.selAll;
  var escapeHtml = PDSTemplate.escapeHtml;

  //
  // State helpers (from helper)
  //
  var readState      = PDSTemplate.readState;
  var writeState     = PDSTemplate.writeState;
  var readEditIndex  = PDSTemplate.readEditIndex;
  var writeEditIndex = PDSTemplate.writeEditIndex;
  var applyGroupIdToDom = PDSTemplate.applyGroupIdToDom;

  //
  // Managed file helpers (from helper)
  //
  var initFileWidgetTemplate     = PDSTemplate.initFileWidgetTemplate;
  var resetFileWidgetToPristine  = PDSTemplate.resetFileWidgetToPristine;
  var getImageFid                = PDSTemplate.getImageFid;

  //
  // CSRF + fetch wrapper (from helper)
  //
  var fetchJSON = PDSTemplate.fetchJSON;

  //
  // Misc helpers
  //
  var normalizeTimelineEntries = PDSTemplate.normalizeTimelineEntries;

  //
  // Small utilities (local)
  //
  function clearInputs(root) {
    var f;
    f = sel(root, 'pds-template-header', 'pds-template-header');       if (f) f.value = '';
    f = sel(root, 'pds-template-subheader', 'pds-template-subheader'); if (f) f.value = '';
    f = sel(root, 'pds-template-description', 'pds-template-description'); if (f) f.value = '';
    f = sel(root, 'pds-template-link', 'pds-template-link');           if (f) f.value = '';
  }

  function loadInputsFromRow(root, row) {
    var f;
    f = sel(root, 'pds-template-header', 'pds-template-header');            if (f) f.value = row.header || '';
    f = sel(root, 'pds-template-subheader', 'pds-template-subheader');      if (f) f.value = row.subheader || '';
    f = sel(root, 'pds-template-description', 'pds-template-description');  if (f) f.value = row.description || '';
    f = sel(root, 'pds-template-link', 'pds-template-link');                if (f) f.value = row.link || '';
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
      baseRow.image_url = '';
      baseRow.desktop_img = '';
      baseRow.mobile_img = '';
    }

    if (typeof existingRow.id === 'number' || (typeof existingRow.id === 'string' && existingRow.id !== '')) {
      baseRow.id = existingRow.id;
    }
    if (typeof existingRow.uuid === 'string' && existingRow.uuid !== '') {
      baseRow.uuid = existingRow.uuid;
    }
    if (typeof existingRow.weight === 'number') {
      baseRow.weight = existingRow.weight;
    }

    return baseRow;
  }

  //
  // Backend helpers (ensure group / resolve / create / update / list)
  //
  function ensureGroupExists(root) {
    if (typeof window.fetch !== 'function') return Promise.resolve(null);

    var url = root.getAttribute('data-pds-template-ensure-group-url');
    if (!url) return Promise.resolve(null);

    if (root._pdsGroupEnsured) return Promise.resolve(root._pdsTemplateGroupId || null);

    return fetchJSON(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    })
      .then(function (response) {
        if (!response.ok) throw new Error('Request failed');
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
      .catch(function () { return null; });
  }

  function resolveRowViaAjax(root, row) {
    return new Promise(function (resolve) {
      if (!row || typeof window.fetch !== 'function') { resolve(row); return; }

      var url = root.getAttribute('data-pds-template-resolve-row-url');
      if (!url || !row.image_fid) { resolve(row); return; }

      fetchJSON(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ row: row })
      })
        .then(function (resp) { if (!resp.ok) throw new Error('Request failed'); return resp.json(); })
        .then(function (json) {
          if (!json || json.status !== 'ok') { resolve(row); return; }
          if (Object.prototype.hasOwnProperty.call(json, 'image_fid')) row.image_fid = json.image_fid;
          if (json.image_url)   row.image_url   = json.image_url;
          if (json.desktop_img) row.desktop_img = json.desktop_img;
          if (json.mobile_img)  row.mobile_img  = json.mobile_img;
          if (!row.image_url && json.desktop_img) row.image_url = json.desktop_img;
          resolve(row);
        })
        .catch(function () { resolve(row); });
    });
  }

  function createRowViaAjax(root, row) {
    if (!row) return Promise.resolve({ success: false, row: row, message: 'Missing row payload.' });
    if (typeof window.fetch !== 'function') return Promise.resolve({ success: true, row: row });

    var url = root.getAttribute('data-pds-template-create-row-url');
    if (!url) return Promise.resolve({ success: true, row: row });

    var payload = { row: row, weight: readState(root).length };
    var recipeType = root.getAttribute('data-pds-template-recipe-type');
    if (recipeType) payload.recipe_type = recipeType;

    return fetchJSON(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (resp) { if (!resp.ok) throw new Error('Request failed'); return resp.json(); })
      .then(function (json) {
        if (!json || json.status !== 'ok') {
          return { success: false, row: row, message: (json && json.message) ? json.message : 'Unable to create row.' };
        }
        var finalRow = Object.assign({}, row);
        if (json.row && typeof json.row === 'object') finalRow = Object.assign(finalRow, json.row);
        if (typeof json.id === 'number') finalRow.id = json.id;
        if (json.uuid) finalRow.uuid = json.uuid;
        if (typeof json.weight === 'number') finalRow.weight = json.weight;
        return { success: true, row: finalRow };
      })
      .catch(function () {
        return { success: false, row: row, message: 'Unable to create row. Please try again.' };
      });
  }

  function updateRowViaAjax(root, row, idx) {
    if (!row || !row.uuid) return Promise.resolve({ success: false, row: row, message: 'Missing row identifier.' });
    if (typeof window.fetch !== 'function') return Promise.resolve({ success: true, row: row });

    var template = root.getAttribute('data-pds-template-update-row-url');
    if (!template) return Promise.resolve({ success: true, row: row });

    var url = template.indexOf(UPDATE_PLACEHOLDER) !== -1
      ? template.replace(UPDATE_PLACEHOLDER, row.uuid)
      : template.replace(/\/$/, '') + '/' + row.uuid;

    var payload = { row: row };
    if (typeof idx === 'number' && idx >= 0) payload.weight = idx;
    else if (typeof row.weight === 'number') payload.weight = row.weight;

    var recipeType = root.getAttribute('data-pds-template-recipe-type');
    if (recipeType) payload.recipe_type = recipeType;

    return fetchJSON(url, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (resp) { if (!resp.ok) throw new Error('Request failed'); return resp.json(); })
      .then(function (json) {
        if (!json || json.status !== 'ok') {
          return { success: false, row: row, message: (json && json.message) ? json.message : 'Unable to update row.' };
        }
        var finalRow = Object.assign({}, row);
        if (typeof json.id === 'number') finalRow.id = json.id;
        if (json.uuid) finalRow.uuid = json.uuid;
        if (typeof json.weight === 'number') finalRow.weight = json.weight;
        if (json.row && typeof json.row === 'object') finalRow = Object.assign(finalRow, json.row);
        return { success: true, row: finalRow };
      })
      .catch(function () {
        return { success: false, row: row, message: 'Unable to update row. Please try again.' };
      });
  }

  function persistRowViaAjax(root, row, idx) {
    if (idx >= 0) return updateRowViaAjax(root, row, idx);
    return createRowViaAjax(root, row);
  }

  //
  // Preview table
  //
  function getPreviewWrapper(root) {
    var wrapper = root.querySelector('[data-pds-template-preview-root]');
    if (wrapper) return wrapper;
    return root.querySelector('#pds-template-preview-list');
  }

  function setPreviewState(wrapper, state, message) {
    if (!wrapper || !wrapper.hasAttribute('data-pds-template-preview-root')) return;
    var states = ['loading', 'empty', 'error', 'content'];
    states.forEach(function (name) {
      var region = wrapper.querySelector('[data-pds-template-preview-state="' + name + '"]');
      if (!region) return;
      if (name === state) {
        region.removeAttribute('hidden');
        if (name === 'error' && typeof message === 'string' && message !== '') {
          region.textContent = message;
        }
      } else {
        region.setAttribute('hidden', 'hidden');
      }
    });
  }

  function bindRowButtons(root, wrapper) {
    selAll(wrapper, '.pds-template-row-edit').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        var idx = parseInt(this.closest('tr').getAttribute('data-row-index'), 10);
        startEditRow(root, idx);
      });
    });

    selAll(wrapper, '.pds-template-row-del').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        var idx = parseInt(this.closest('tr').getAttribute('data-row-index'), 10);
        deleteRow(root, idx);
      });
    });
  }

  function renderPreviewTable(root) {
    var wrapper = getPreviewWrapper(root);
    if (!wrapper) return;

    var hasStates = wrapper.hasAttribute('data-pds-template-preview-root');
    var content = hasStates ? wrapper.querySelector('[data-pds-template-preview-state="content"]') : wrapper;
    if (!content) return;

    var rows = readState(root);
    if (!Array.isArray(rows)) rows = [];

    if (!rows.length) {
      if (!root._pdsPreviewAutoLoaded) {
        var listingUrl = root.getAttribute('data-pds-template-list-rows-url');
        if (listingUrl) {
          root._pdsPreviewAutoLoaded = true;
          refreshPreviewFromServer(root);
          return;
        }
      }
      if (hasStates) {
        content.innerHTML = '';
        setPreviewState(wrapper, 'empty');
      } else {
        content.innerHTML =
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
              '<tr><td colspan="5"><em>No rows yet.</em></td></tr>' +
            '</tbody>' +
          '</table>';
      }
      return;
    }

    var html = '';
    html += '<table class="pds-template-table">';
    html +=   '<thead><tr>';
    html +=     '<th>ID</th>';
    html +=     '<th class="pds-template-table__col-thumb">Image</th>';
    html +=     '<th>Header</th>';
    html +=     '<th>Subheader</th>';
    html +=     '<th>Link</th>';
    html +=     '<th>Actions</th>';
    html +=   '</tr></thead><tbody>';

    for (var i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      var thumb = r.desktop_img || r.thumbnail || r.image_url || r.mobile_img || '';
      var identifier = '';
      if (typeof r.id === 'number') identifier = String(r.id);
      else if (r.id && typeof r.id !== 'object') identifier = String(r.id);
      else if (typeof r.uuid === 'string') identifier = r.uuid;

      html += '<tr data-row-index="' + i + '">';
      html +=   '<td>' + escapeHtml(identifier) + '</td>';
      html +=   '<td class="pds-template-table__thumb">' + (thumb ? '<img src="' + escapeHtml(thumb) + '" alt="" />' : '') + '</td>';
      html +=   '<td>' + escapeHtml(r.header || '') + '</td>';
      html +=   '<td>' + escapeHtml(r.subheader || '') + '</td>';
      html +=   '<td>' + escapeHtml(r.link || '') + '</td>';
      html +=   '<td><button type="button" class="pds-template-row-edit">Edit</button> ' +
                 '<button type="button" class="pds-template-row-del">Delete</button></td>';
      html += '</tr>';
    }

    html += '</tbody></table>';

    content.innerHTML = html;
    if (hasStates) {
      setPreviewState(wrapper, 'content');
      bindRowButtons(root, content);
    } else {
      bindRowButtons(root, wrapper);
    }
  }

  function updateTimelineDatasetSnapshot(root, rows) {
    if (typeof drupalSettings === 'undefined' || !drupalSettings || !drupalSettings.pdsRecipeTemplate) return;
    var instanceUuid = root.getAttribute('data-pds-template-block-uuid');
    if (!instanceUuid) return;

    if (!drupalSettings.pdsRecipeTemplate.masters) {
      drupalSettings.pdsRecipeTemplate.masters = {};
    }
    if (!drupalSettings.pdsRecipeTemplate.masters[instanceUuid]) {
      drupalSettings.pdsRecipeTemplate.masters[instanceUuid] = { metadata: {}, datasets: {} };
    }

    var master = drupalSettings.pdsRecipeTemplate.masters[instanceUuid];
    if (!master.datasets) master.datasets = {};
    master.datasets.timeline = {};

    rows.forEach(function (row) {
      if (!row || typeof row !== 'object') return;

      var key = '';
      if (typeof row.uuid === 'string' && row.uuid) key = row.uuid;
      else if (typeof row.id === 'number') key = 'id:' + row.id;
      else if (typeof row.id === 'string' && row.id.trim() !== '') key = 'id:' + row.id.trim();
      if (!key) return;

      master.datasets.timeline[key] = {
        id: (typeof row.id === 'number' ? row.id : (typeof row.id === 'string' && row.id.trim() !== '' ? row.id : null)),
        uuid: typeof row.uuid === 'string' ? row.uuid : '',
        timeline: Array.isArray(row.timeline) ? row.timeline : []
      };
    });
  }

  function refreshPreviewFromServer(root) {
    if (typeof window.fetch !== 'function') { renderPreviewTable(root); return Promise.resolve(null); }
    var url = root.getAttribute('data-pds-template-list-rows-url');
    if (!url) { renderPreviewTable(root); return Promise.resolve(null); }

    var wrapper = getPreviewWrapper(root);
    if (wrapper && wrapper.hasAttribute('data-pds-template-preview-root')) {
      setPreviewState(wrapper, 'loading');
    }

    var requestToken = (root._pdsPreviewRequestToken || 0) + 1;
    root._pdsPreviewRequestToken = requestToken;

    return fetchJSON(url, {
      method: 'GET',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (resp) {
        if (!resp.ok) throw new Error('Request failed');
        return resp.json();
      })
      .then(function (json) {
        if (root._pdsPreviewRequestToken !== requestToken) return;

        if (!json || json.status !== 'ok' || !Array.isArray(json.rows)) {
          var message = (json && json.message) ? json.message : 'Unable to load preview.';
          if (wrapper && wrapper.hasAttribute('data-pds-template-preview-root')) {
            setPreviewState(wrapper, 'error', message);
          }
          return;
        }

        if (typeof json.group_id === 'number') {
          applyGroupIdToDom(root, json.group_id);
        }

        var normalized = json.rows.map(function (row) {
          var safeRow = row && typeof row === 'object' ? row : {};
          var desktop = typeof safeRow.desktop_img === 'string' ? safeRow.desktop_img : '';
          var imageUrl = typeof safeRow.image_url === 'string' ? safeRow.image_url : '';
          var mobile = typeof safeRow.mobile_img === 'string' ? safeRow.mobile_img : '';
          var thumbnail = typeof safeRow.thumbnail === 'string' ? safeRow.thumbnail : '';
          var resolvedThumb = desktop || thumbnail || imageUrl || mobile || '';

          var normalizedRow = {
            header: typeof safeRow.header === 'string' ? safeRow.header : '',
            subheader: typeof safeRow.subheader === 'string' ? safeRow.subheader : '',
            description: typeof safeRow.description === 'string' ? safeRow.description : '',
            link: typeof safeRow.link === 'string' ? safeRow.link : '',
            desktop_img: desktop || imageUrl || '',
            mobile_img: mobile,
            image_url: imageUrl || desktop || mobile || '',
            latitud: typeof safeRow.latitud === 'number' ? safeRow.latitud : null,
            longitud: typeof safeRow.longitud === 'number' ? safeRow.longitud : null,
            weight: typeof safeRow.weight === 'number' ? safeRow.weight : null,
            thumbnail: resolvedThumb
          };

          if (typeof safeRow.id === 'number') {
            normalizedRow.id = safeRow.id;
          } else if (typeof safeRow.id === 'string' && safeRow.id !== '') {
            var parsedId = parseInt(safeRow.id, 10);
            normalizedRow.id = isNaN(parsedId) ? safeRow.id : parsedId;
          }

          if (typeof safeRow.uuid === 'string') normalizedRow.uuid = safeRow.uuid;
          if (typeof safeRow.image_fid === 'number' || typeof safeRow.image_fid === 'string') {
            normalizedRow.image_fid = safeRow.image_fid;
          }

          var normalizedTimeline = normalizeTimelineEntries(safeRow.timeline);
          normalizedRow.timeline = normalizedTimeline;

          return normalizedRow;
        });

        writeState(root, normalized);
        updateTimelineDatasetSnapshot(root, normalized);
        renderPreviewTable(root);
      })
      .catch(function () {
        if (root._pdsPreviewRequestToken !== requestToken) return;
        if (wrapper && wrapper.hasAttribute('data-pds-template-preview-root')) {
          setPreviewState(wrapper, 'error', 'Unable to load preview.');
        }
      });
  }

  //
  // Row ops
  //
  function activateTab(root, panelId) {
    selAll(root, '[data-pds-template-tab-target]').forEach(function (btn) {
      var match = (btn.getAttribute('data-pds-template-tab-target') === panelId);
      if (match) btn.classList.add('is-active');
      else btn.classList.remove('is-active');
    });

    selAll(root, '.js-pds-template-panel').forEach(function (panel) {
      var match = (panel.getAttribute('data-pds-template-panel-id') === panelId);
      if (match) panel.classList.add('is-active');
      else panel.classList.remove('is-active');
    });

    if (panelId === 'panel-b') {
      refreshPreviewFromServer(root);
    }
  }

  function startEditRow(root, idx) {
    var rows = readState(root);
    if (idx < 0 || idx >= rows.length) return;

    loadInputsFromRow(root, rows[idx]);
    writeEditIndex(root, idx);

    var btn = sel(root, 'pds-template-add-card', 'pds-template-add-card');
    if (btn) btn.textContent = 'Save changes';

    activateTab(root, 'panel-a');
  }

  function deleteRow(root, idx) {
    var rows = readState(root);
    if (idx < 0 || idx >= rows.length) return;

    var row = rows[idx] || {};
    if (!row.uuid) {
      showError(root, 'This row cannot be deleted because it has no UUID.');
      return;
    }

    // Debounce: ignore if a delete is already in flight for this UUID.
    root._pdsDeleting = root._pdsDeleting || {};
    if (root._pdsDeleting[row.uuid]) return;
    root._pdsDeleting[row.uuid] = true;

    clearError(root);

    // Cache wrapper/state once.
    var wrapper = getPreviewWrapper(root);
    var hasStates = !!(wrapper && wrapper.hasAttribute && wrapper.hasAttribute('data-pds-template-preview-root'));
    if (hasStates) setPreviewState(wrapper, 'loading');

    // Optionally disable the clicked button so users see immediate feedback.
    var tr = root.querySelector('tr[data-row-index="' + idx + '"]');
    var delBtn = tr ? tr.querySelector('.pds-template-row-del') : null;
    if (delBtn) delBtn.disabled = true;

    deleteRowViaAjax(root, row)
      .then(function (result) {
        // Normalize result; treat 404/410 style replies as success.
        var ok = !!(result && (result.success || result.status === 404 || result.status === 410));
        if (!ok) {
          if (hasStates) setPreviewState(wrapper, 'content');
          showError(root, (result && result.message) || 'Unable to delete row.');
          return;
        }

        // Only now mutate local state.
        rows.splice(idx, 1);
        writeState(root, rows);

        // Exit edit mode if we were editing that row.
        var active = readEditIndex(root);
        if (active === idx) {
          writeEditIndex(root, -1);
          var btn = sel(root, 'pds-template-add-card', 'pds-template-add-card');
          if (btn) btn.textContent = 'Add row';
          clearInputs(root);
          resetFileWidgetToPristine(root);
        }

        renderPreviewTable(root);
        if (hasStates) setPreviewState(wrapper, 'content');
      })
      .catch(function () {
        if (hasStates) setPreviewState(wrapper, 'content');
        showError(root, 'Unable to delete row.');
      })
      .finally(function () {
        // Re-enable UI & clear in-flight flag.
        if (delBtn) delBtn.disabled = false;
        delete root._pdsDeleting[row.uuid];
      });
  }



  function persistRow(root, idx) {
    var rows = readState(root);
    var existingRow = idx >= 0 && idx < rows.length ? rows[idx] : null;
    var newRow = buildRowFromInputs(root, existingRow);

    if (!newRow.header || !newRow.header.trim()) return Promise.resolve(false);

    clearError(root);

    var latestResolvedRow = null;

    return resolveRowViaAjax(root, newRow)
      .then(function (resolvedRow) {
        latestResolvedRow = resolvedRow;
        return persistRowViaAjax(root, resolvedRow, idx);
      })
      .then(function (result) {
        if (!result || !result.success) {
          var message = result && result.message ? result.message : 'Unable to save row. Please try again.';
          showError(root, message);
          return false;
        }

        var finalRow = result.row || latestResolvedRow;
        var currentRows = readState(root);
        if (idx >= 0 && idx < currentRows.length) currentRows[idx] = finalRow;
        else currentRows.push(finalRow);

        writeState(root, currentRows);
        writeEditIndex(root, -1);

        var btn = sel(root, 'pds-template-add-card', 'pds-template-add-card');
        if (btn) btn.textContent = 'Add row';

        clearInputs(root);
        resetFileWidgetToPristine(root);

        renderPreviewTable(root);
        activateTab(root, 'panel-b');

        return true;
      })
      .catch(function () {
        showError(root, 'Unable to save row. Please try again.');
        return false;
      });
  }

  function hasPendingSubmitEdits(root) {
    if (readEditIndex(root) >= 0) return true;

    var headerEl = sel(root, 'pds-template-header', 'pds-template-header');
    var subEl = sel(root, 'pds-template-subheader', 'pds-template-subheader');
    var descEl = sel(root, 'pds-template-description', 'pds-template-description');
    var linkEl = sel(root, 'pds-template-link', 'pds-template-link');

    var headerVal = headerEl && typeof headerEl.value === 'string' ? headerEl.value.trim() : '';
    var subVal = subEl && typeof subEl.value === 'string' ? subEl.value.trim() : '';
    var descVal = descEl && typeof descEl.value === 'string' ? descEl.value.trim() : '';
    var linkVal = linkEl && typeof linkEl.value === 'string' ? linkEl.value.trim() : '';

    if (headerVal !== '' || subVal !== '' || descVal !== '' || linkVal !== '') return true;
    return !!getImageFid(root);
  }

  function commitPendingEditsBeforeSubmit(root) {
    var beforeCount = readState(root).length;
    return persistRow(root, readEditIndex(root)).then(function (didCommit) {
      if (!didCommit) return false;
      var afterCount = readState(root).length;
      if (beforeCount === 0 && afterCount > 0) {
        return ensureGroupExists(root).then(function () { return true; });
      }
      return true;
    });
  }

  function handleAddOrUpdateRow(root) {
    var beforeCount = readState(root).length;
    persistRow(root, readEditIndex(root)).then(function (didCommit) {
      if (!didCommit) return;
      var afterCount = readState(root).length;
      if (beforeCount === 0 && afterCount > 0) {
        ensureGroupExists(root);
      }
    });
  }

  //
  // Tabs init
  //
  function initTabs(root) {
    selAll(root, '[data-pds-template-tab-target]').forEach(function (btn) {
      btn.addEventListener('click', function (event) {
        event.preventDefault(); event.stopPropagation();
        var targetId = btn.getAttribute('data-pds-template-tab-target');
        activateTab(root, targetId);
      });
    });
    var firstBtn = root.querySelector('[data-pds-template-tab-target]');
    if (firstBtn) activateTab(root, firstBtn.getAttribute('data-pds-template-tab-target'));
  }

  //
  // Behavior attach
  //
  Drupal.behaviors.pdsTemplateAdmin = {
    attach: function (context) {
      once('pdsTemplateAdminRoot', '.js-pds-template-admin', context).forEach(function (root) {
        // Tabs + initial preview
        initTabs(root);
        renderPreviewTable(root);

        // First-load hydration if textarea is empty
        var initialRows = readState(root);
        var hasInitialRows = Array.isArray(initialRows) && initialRows.length > 0;
        if (!hasInitialRows && root.getAttribute('data-pds-template-list-rows-url')) {
          if (!root._pdsPreviewAutoHydrated) {
            root._pdsPreviewAutoHydrated = true;
            refreshPreviewFromServer(root);
          }
        }

        // Snapshot pristine managed_file widget
        initFileWidgetTemplate(root);

        // Pre-supplied group id (from PHP)
        var existingGroupIdAttr = root.getAttribute('data-pds-template-group-id');
        if (existingGroupIdAttr) {
          var parsedExistingId = parseInt(existingGroupIdAttr, 10);
          if (!isNaN(parsedExistingId)) root._pdsTemplateGroupId = parsedExistingId;
        }

        // Add/Save button
        var addBtn = root.querySelector('[data-drupal-selector="pds-template-add-card"], #pds-template-add-card');
        if (addBtn) {
          addBtn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); handleAddOrUpdateRow(root); });
          addBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); handleAddOrUpdateRow(root); }
          });
        }

        // Intercept form submit to auto-commit pending edits
        var hostForm = root.closest('form');
        if (hostForm) {
          function cacheSubmitter(target) {
            if (!target) return;
            var submitter = null;
            if (typeof target.closest === 'function') {
              submitter = target.closest('input[type="submit"], button[type="submit"], .form-submit');
            }
            if (!submitter && target.matches && target.matches('input[type="submit"], button[type="submit"], .form-submit')) {
              submitter = target;
            }
            if (submitter) root._pdsTemplateSubmitter = submitter;
          }

          hostForm.addEventListener('click', function (e) { cacheSubmitter(e.target); }, true);
          hostForm.addEventListener('pointerdown', function (e) { cacheSubmitter(e.target); }, true);
          hostForm.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') cacheSubmitter(e.target);
          }, true);

          hostForm.addEventListener('submit', function (event) {
            if (root._pdsTemplateBypassSubmit) { root._pdsTemplateBypassSubmit = false; return; }
            if (!hasPendingSubmitEdits(root)) return;

            event.preventDefault();
            event.stopPropagation();

            var submitter = event.submitter || root._pdsTemplateSubmitter || null;
            if (!submitter) {
              submitter = hostForm.querySelector('[data-drupal-selector="edit-actions-submit"], input[type="submit"], button[type="submit"], .form-submit');
            }

            commitPendingEditsBeforeSubmit(root).then(function (didCommit) {
              if (!didCommit) return;

              root._pdsTemplateBypassSubmit = true;
              root._pdsTemplateSubmitter = null;

              if (typeof hostForm.requestSubmit === 'function') {
                if (submitter) { hostForm.requestSubmit(submitter); return; }
                hostForm.requestSubmit(); return;
              }
              if (submitter && typeof submitter.click === 'function') { submitter.click(); return; }
              hostForm.submit();
            });
          });
        }

      });
    }
  };

})(Drupal, once, window.PDSTemplate);

(function (Drupal, once, drupalSettings) {
  'use strict';

  //1.- Local helper que resuelve el contenedor de vista previa generado por el template base.
  function resolvePreviewContent(root) {
    var wrapper = root.querySelector('[data-pds-template-preview-state="content"]');
    if (wrapper) {
      return wrapper;
    }
    return root.querySelector('#pds-template-preview-list');
  }

  //2.- Lector del snapshot JSON almacenado en el textarea oculto por el template.
  function readState(root) {
    var field = root.querySelector('[data-drupal-selector="pds-template-cards-state"]');
    if (!field) {
      return [];
    }
    try {
      return field.value ? JSON.parse(field.value) : [];
    } catch (error) {
      return [];
    }
  }

  //3.- Escritor del snapshot JSON para que Layout Builder detecte los cambios en la configuración.
  function writeState(root, rows) {
    var field = root.querySelector('[data-drupal-selector="pds-template-cards-state"]');
    if (!field) {
      return;
    }
    try {
      field.value = JSON.stringify(rows || []);
    } catch (error) {
      //4.- Si la serialización falla dejamos el valor previo intacto para no corromper la configuración.
    }
  }

  //3.1.- Calcula la llave estable del renglón usando UUID o ID numérico para correlacionar acciones posteriores.
  function buildRowKey(row) {
    if (!row || typeof row !== 'object') {
      return '';
    }
    if (row.uuid && typeof row.uuid === 'string') {
      return row.uuid;
    }
    if (typeof row.id === 'number') {
      return 'id:' + row.id;
    }
    if (typeof row.id === 'string' && row.id.trim() !== '') {
      return 'id:' + row.id.trim();
    }
    return '';
  }

  //5.- Preparamos el modal reutilizable que hospedará el editor de hitos cronológicos.
  function ensureModal(root) {
    var modal = document.querySelector('.pds-timeline-admin-modal');
    if (modal) {
      return modal;
    }

    modal = document.createElement('div');
    modal.className = 'pds-timeline-admin-modal';
    modal.setAttribute('hidden', 'hidden');
    modal.tabIndex = -1;
    modal.innerHTML = '' +
      '<div class="pds-timeline-admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pdsTimelineAdminTitle">' +
        '<h2 id="pdsTimelineAdminTitle">' + Drupal.t('Timeline entries') + '</h2>' +
        '<div class="pds-timeline-admin-modal__message" data-pds-timeline-message hidden></div>' +
        '<div class="pds-timeline-admin-modal__list" data-pds-timeline-list></div>' +
        '<button type="button" class="pds-timeline-admin-add" data-pds-timeline-add>+' + Drupal.t('Add milestone') + '</button>' +
        '<div class="pds-timeline-admin-modal__actions">' +
          '<button type="button" class="pds-timeline-admin-cancel" data-pds-timeline-cancel>' + Drupal.t('Cancel') + '</button>' +
          '<button type="button" class="pds-timeline-admin-save" data-pds-timeline-save>' + Drupal.t('Save timeline') + '</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(modal);
    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        event.preventDefault();
        closeModal(modal);
        return;
      }
      if (event.target.matches('[data-pds-timeline-cancel]')) {
        event.preventDefault();
        closeModal(modal);
      }
      if (event.target.matches('[data-pds-timeline-add]')) {
        event.preventDefault();
        appendRowEditor(modal.querySelector('[data-pds-timeline-list]'), { year: '', label: '' });
      }
      if (event.target.matches('[data-pds-timeline-save]')) {
        event.preventDefault();
        submitModal(modal);
      }
      if (event.target.classList.contains('pds-timeline-admin-row__remove')) {
        event.preventDefault();
        var row = event.target.closest('.pds-timeline-admin-row');
        if (row) {
          row.remove();
        }
      }
    });

    modal.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeModal(modal);
      }
    });

    return modal;
  }

  //6.- Construimos una fila editable para año y etiqueta del hito.
  function appendRowEditor(container, entry) {
    if (!container) {
      return;
    }
    var row = document.createElement('div');
    row.className = 'pds-timeline-admin-row';
    row.innerHTML = '' +
      '<label class="visually-hidden">' + Drupal.t('Year') + '</label>' +
      '<input type="number" min="0" step="1" value="' + (entry && entry.year !== undefined ? entry.year : '') + '" />' +
      '<label class="visually-hidden">' + Drupal.t('Description') + '</label>' +
      '<input type="text" value="' + (entry && entry.label ? entry.label : '') + '" />' +
      '<button type="button" class="pds-timeline-admin-row__remove" aria-label="' + Drupal.t('Remove milestone') + '">&times;</button>';
    container.appendChild(row);
  }

  //7.- Limpia el mensaje de error/suceso mostrado en el modal.
  function clearModalMessage(modal) {
    var message = modal.querySelector('[data-pds-timeline-message]');
    if (!message) {
      return;
    }
    message.textContent = '';
    message.setAttribute('hidden', 'hidden');
  }

  //8.- Muestra mensajes informativos en el modal.
  function showModalMessage(modal, text) {
    var message = modal.querySelector('[data-pds-timeline-message]');
    if (!message) {
      return;
    }
    message.textContent = text;
    message.removeAttribute('hidden');
  }

  //3.2.- Localiza el renglón activo apoyándose primero en el índice y luego en su llave estable.
  function resolveRowSnapshot(root, index, key) {
    var rows = readState(root);
    if (index >= 0 && index < rows.length) {
      return { rows: rows, row: rows[index], index: index };
    }

    var normalizedKey = typeof key === 'string' ? key.trim() : '';
    if (normalizedKey) {
      for (var i = 0; i < rows.length; i++) {
        var candidateKey = buildRowKey(rows[i]);
        if (candidateKey && candidateKey === normalizedKey) {
          return { rows: rows, row: rows[i], index: i };
        }
      }
    }

    return { rows: rows, row: null, index: -1 };
  }

  //3.3.- Recupera el dataset de timeline expuesto por drupalSettings para el bloque activo.
  function readTimelineDataset(root) {
    if (!drupalSettings || !drupalSettings.pdsRecipeTemplate) {
      return null;
    }

    var instanceUuid = root.getAttribute('data-pds-template-block-uuid');
    if (!instanceUuid) {
      return null;
    }

    var masters = drupalSettings.pdsRecipeTemplate.masters;
    if (!masters || !masters[instanceUuid] || !masters[instanceUuid].datasets) {
      return null;
    }

    var dataset = masters[instanceUuid].datasets.timeline;
    if (!dataset || typeof dataset !== 'object') {
      return null;
    }

    return dataset;
  }

  //3.4.- Genera una copia normalizada de los hitos almacenados en drupalSettings.
  function cloneTimelineEntries(source) {
    if (!Array.isArray(source) || source.length === 0) {
      return [];
    }

    return source.map(function (entry, position) {
      var label = '';
      if (entry && typeof entry.label === 'string') {
        label = entry.label;
      } else if (entry && typeof entry.label !== 'undefined') {
        label = String(entry.label || '');
      }

      var year = 0;
      if (entry && typeof entry.year === 'number') {
        year = entry.year;
      } else if (entry && typeof entry.year !== 'undefined') {
        var parsedYear = parseInt(entry.year, 10);
        year = isNaN(parsedYear) ? 0 : parsedYear;
      }

      var weight = position;
      if (entry && typeof entry.weight === 'number') {
        weight = entry.weight;
      } else if (entry && typeof entry.weight !== 'undefined') {
        var parsedWeight = parseInt(entry.weight, 10);
        weight = isNaN(parsedWeight) ? position : parsedWeight;
      }

      return {
        year: year,
        label: label,
        weight: weight
      };
    });
  }

  //3.5.- Rellena el snapshot del renglón con el dataset público cuando el estado local está vacío.
  function mergeTimelineFromSettings(root, snapshot, key) {
    if (!snapshot || !snapshot.row || snapshot.index < 0) {
      return snapshot;
    }

    if (Array.isArray(snapshot.row.timeline) && snapshot.row.timeline.length > 0) {
      return snapshot;
    }

    var dataset = readTimelineDataset(root);
    if (!dataset) {
      return snapshot;
    }

    var datasetKey = typeof key === 'string' && key.trim() !== '' ? key.trim() : buildRowKey(snapshot.row);
    if (!datasetKey || !dataset[datasetKey]) {
      return snapshot;
    }

    var timelineSource = dataset[datasetKey].timeline;
    var cloned = cloneTimelineEntries(timelineSource);
    if (cloned.length === 0) {
      return snapshot;
    }

    snapshot.row.timeline = cloned;
    snapshot.rows[snapshot.index] = snapshot.row;
    writeState(root, snapshot.rows);

    return snapshot;
  }

  //9.- Abre el modal con los datos del índice solicitado.
  function openModal(root, index, key) {
    var snapshot = resolveRowSnapshot(root, index, key);
    snapshot = mergeTimelineFromSettings(root, snapshot, key);
    if (!snapshot.row) {
      var fallbackModal = ensureModal(root);
      clearModalMessage(fallbackModal);
      var missingList = fallbackModal.querySelector('[data-pds-timeline-list]');
      if (missingList) {
        missingList.innerHTML = '';
      }
      showModalMessage(fallbackModal, Drupal.t('Timeline data is unavailable for this item. Please save the row and try again.'));
      fallbackModal.removeAttribute('hidden');
      fallbackModal.focus();
      return;
    }

    var modal = ensureModal(root);
    clearModalMessage(modal);
    modal.dataset.rootId = root.getAttribute('data-pds-template-block-uuid') || '';
    modal.dataset.rowIndex = String(snapshot.index);
    modal.dataset.recipeType = root.getAttribute('data-pds-template-recipe-type') || 'pds_recipe_timeline';
    modal.dataset.updateUrl = root.getAttribute('data-pds-template-update-row-url') || '';
    //3.6.- Persist the root reference so subsequent saves survive DOM replacements triggered by AJAX renders.
    modal._pdsTimelineRoot = root;

    var list = modal.querySelector('[data-pds-timeline-list]');
    if (list) {
      list.innerHTML = '';
      var source = Array.isArray(snapshot.row.timeline) ? snapshot.row.timeline : [];
      if (source.length === 0) {
        appendRowEditor(list, { year: '', label: '' });
      } else {
        source.forEach(function (entry) {
          appendRowEditor(list, entry);
        });
      }
    }

    clearModalMessage(modal);
    modal.removeAttribute('hidden');
    modal.focus();
  }

  //10.- Cierra el modal y elimina estados temporales.
  function closeModal(modal) {
    modal.setAttribute('hidden', 'hidden');
    delete modal.dataset.rowIndex;
    //10.1.- Clear the cached root reference so follow-up openings refresh the context automatically.
    modal._pdsTimelineRoot = null;
  }

  //11.- Recolecta los hitos escritos en el modal y devuelve un array ordenado.
  function collectEntries(modal) {
    var entries = [];
    modal.querySelectorAll('.pds-timeline-admin-row').forEach(function (row, position) {
      var inputs = row.querySelectorAll('input');
      if (inputs.length < 2) {
        return;
      }
      var rawYear = inputs[0].value;
      var rawLabel = inputs[1].value;
      var label = typeof rawLabel === 'string' ? rawLabel.trim() : '';
      var year = parseInt(rawYear, 10);
      if (!label) {
        return;
      }
      if (isNaN(year)) {
        year = 0;
      }
      entries.push({
        year: year,
        label: label,
        weight: position
      });
    });
    return entries;
  }

  //12.- Actualiza el dataset de drupalSettings para que otros scripts compartan el nuevo estado.
  function syncTimelineDataset(root, row) {
    if (!drupalSettings || !drupalSettings.pdsRecipeTemplate) {
      return;
    }
    var instanceUuid = root.getAttribute('data-pds-template-block-uuid');
    if (!instanceUuid) {
      return;
    }
    if (!drupalSettings.pdsRecipeTemplate.masters) {
      drupalSettings.pdsRecipeTemplate.masters = {};
    }
    if (!drupalSettings.pdsRecipeTemplate.masters[instanceUuid]) {
      drupalSettings.pdsRecipeTemplate.masters[instanceUuid] = { datasets: {} };
    }
    var master = drupalSettings.pdsRecipeTemplate.masters[instanceUuid];
    if (!master.datasets) {
      master.datasets = {};
    }
    if (!master.datasets.timeline) {
      master.datasets.timeline = {};
    }

    var key = '';
    if (row.uuid) {
      key = row.uuid;
    } else if (typeof row.id === 'number') {
      key = 'id:' + row.id;
    }
    if (!key) {
      return;
    }

    master.datasets.timeline[key] = {
      id: typeof row.id === 'number' ? row.id : null,
      uuid: row.uuid || '',
      timeline: Array.isArray(row.timeline) ? row.timeline : []
    };
  }

  //13.- Serializa y envía el timeline al backend usando el endpoint de actualización del template base.
  function persistTimeline(modal) {
    var storedRoot = modal._pdsTimelineRoot || null;
    var rootId = modal.dataset.rootId || '';
    var index = parseInt(modal.dataset.rowIndex || '-1', 10);
    var updateUrlTemplate = modal.dataset.updateUrl || '';
    var recipeType = modal.dataset.recipeType || '';

    //13.1.- Resolve the active admin root element even after dynamic rerenders replaced the original node.
    var root = storedRoot && storedRoot.isConnected ? storedRoot : null;
    if (!root && rootId) {
      root = document.querySelector('.js-pds-template-admin[data-pds-template-block-uuid="' + rootId + '"]');
    }
    if (!root) {
      return Promise.reject(new Error(Drupal.t('Unable to locate the recipe form. Refresh the page and try again.')));
    }

    //13.2.- Fallback to attributes on the freshly resolved root when the modal cache lacks the latest values.
    if (!updateUrlTemplate) {
      updateUrlTemplate = root.getAttribute('data-pds-template-update-row-url') || '';
    }
    if (!recipeType) {
      recipeType = root.getAttribute('data-pds-template-recipe-type') || 'pds_recipe_timeline';
    }

    //13.3.- Abort gracefully when the admin UI cannot determine the requested timeline row.
    if (isNaN(index) || index < 0) {
      return Promise.reject(new Error(Drupal.t('Timeline row is unavailable. Save the block and try again.')));
    }
    if (!updateUrlTemplate) {
      return Promise.reject(new Error(Drupal.t('Timeline endpoint is unavailable. Save the block and try again.')));
    }

    var rows = readState(root);
    if (index < 0 || index >= rows.length) {
      return Promise.reject(new Error('Row not found.'));
    }

    var row = rows[index] || {};
    if (!row.uuid) {
      return Promise.reject(new Error('Row UUID is required to persist timeline.'));
    }

    var entries = collectEntries(modal);
    var updateUrl = updateUrlTemplate.replace('00000000-0000-0000-0000-000000000000', row.uuid);

    var payloadRow = {
      header: row.header || '',
      subheader: row.subheader || '',
      description: row.description || '',
      link: row.link || '',
      desktop_img: row.desktop_img || '',
      mobile_img: row.mobile_img || '',
      image_url: row.image_url || '',
      latitud: typeof row.latitud === 'number' ? row.latitud : null,
      longitud: typeof row.longitud === 'number' ? row.longitud : null,
      timeline: entries
    };

    if (typeof row.image_fid !== 'undefined') {
      payloadRow.image_fid = row.image_fid;
    }

    var payload = {
      row: payloadRow,
      recipe_type: recipeType,
      weight: typeof row.weight === 'number' ? row.weight : index
    };

    return fetch(updateUrl, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Request failed');
      }
      return response.json();
    }).then(function (json) {
      if (!json || json.status !== 'ok') {
        throw new Error(json && json.message ? json.message : 'Unable to save timeline.');
      }

      var finalRow = Object.assign({}, row);
      if (json.row && typeof json.row === 'object') {
        finalRow = Object.assign(finalRow, json.row);
      }
      if (typeof json.id === 'number') {
        finalRow.id = json.id;
      }
      if (json.uuid) {
        finalRow.uuid = json.uuid;
      }
      if (typeof json.weight === 'number') {
        finalRow.weight = json.weight;
      }

      var storedTimeline = [];
      if (json.row && Array.isArray(json.row.timeline)) {
        storedTimeline = cloneTimelineEntries(json.row.timeline);
      } else {
        storedTimeline = entries;
      }

      finalRow.timeline = storedTimeline;
      rows[index] = finalRow;
      writeState(root, rows);
      syncTimelineDataset(root, finalRow);

      return finalRow;
    });
  }

  //14.- Ejecuta la persistencia del modal y muestra feedback visual.
  function submitModal(modal) {
    clearModalMessage(modal);
    modal.classList.add('is-saving');
    persistTimeline(modal)
      .then(function () {
        closeModal(modal);
        if (typeof Drupal !== 'undefined' && Drupal.announce) {
          Drupal.announce(Drupal.t('Timeline saved.'));
        }
      })
      .catch(function (error) {
        showModalMessage(modal, error.message || Drupal.t('Unable to save timeline.'));
      })
      .finally(function () {
        modal.classList.remove('is-saving');
      });
  }

  //15.- Inserta el botón "Timeline" dentro de la tabla de vista previa.
  function annotatePreview(root) {
    var container = resolvePreviewContent(root);
    if (!container) {
      return;
    }
    var rows = readState(root);
    container.querySelectorAll('tr[data-row-index]').forEach(function (rowEl) {
      var idx = parseInt(rowEl.getAttribute('data-row-index'), 10);
      if (isNaN(idx)) {
        return;
      }
      var actionsCell = rowEl.querySelector('td:last-child');
      if (!actionsCell) {
        return;
      }
      if (actionsCell.querySelector('.pds-timeline-admin-manage')) {
        return;
      }
      var manage = document.createElement('button');
      manage.type = 'button';
      manage.className = 'pds-timeline-admin-manage';
      manage.textContent = Drupal.t('Timeline');
      manage.setAttribute('data-pds-timeline-index', String(idx));
      var key = buildRowKey(rows[idx]);
      if (key) {
        manage.setAttribute('data-pds-timeline-key', key);
        rowEl.setAttribute('data-pds-timeline-key', key);
      }
      actionsCell.appendChild(manage);
    });
  }

  //16.- Observa cambios en la tabla para reinyectar botones tras renders AJAX.
  function observePreview(root) {
    var container = resolvePreviewContent(root);
    if (!container || container._pdsTimelineObserver) {
      return;
    }
    var observer = new MutationObserver(function () {
      annotatePreview(root);
    });
    observer.observe(container, { childList: true, subtree: true });
    container._pdsTimelineObserver = observer;
  }

  //17.- Inicializa listeners para aperturar el modal desde los botones agregados.
  function bindManageClick(root) {
    if (root._pdsTimelineManageBound) {
      return;
    }
    root.addEventListener('click', function (event) {
      var button = event.target.closest('.pds-timeline-admin-manage');
      if (!button) {
        return;
      }
      event.preventDefault();
      var idx = parseInt(button.getAttribute('data-pds-timeline-index'), 10);
      var key = button.getAttribute('data-pds-timeline-key') || '';
      if (isNaN(idx)) {
        idx = -1;
      }
      openModal(root, idx, key);
    });
    root._pdsTimelineManageBound = true;
  }

  Drupal.behaviors.pdsTimelineAdmin = {
    attach: function (context) {
      once('pdsTimelineAdminRoot', '.js-pds-template-admin', context).forEach(function (root) {
        annotatePreview(root);
        observePreview(root);
        bindManageClick(root);
      });
    }
  };

})(Drupal, once, drupalSettings);

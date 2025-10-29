(function (Drupal, once, drupalSettings) {
  'use strict';

  //3.5.- Define el marcador de UUID usado por los endpoints para que los reemplazos funcionen incluso sin plantillas.
  var UPDATE_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

  //3.5.1.- Iterador seguro que soporta NodeList y HTMLCollection incluso en navegadores sin forEach nativo.
  function forEachElement(collection, callback) {
    if (!collection) {
      return;
    }
    var length = collection.length || 0;
    for (var index = 0; index < length; index++) {
      callback(collection[index], index);
    }
  }

  //3.8.- Validador ligero de UUID para descartar cadenas inválidas antes de usar el identificador.
  function isValidUuid(value) {
    if (typeof value !== 'string') {
      return false;
    }
    var trimmed = value.trim();
    if (trimmed === '') {
      return false;
    }
    return /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/.test(trimmed);
  }

  //3.9.- Normaliza valores de identificador para producir variantes numéricas y textuales reutilizables.
  function normalizeRowId(value) {
    //1.- Acepta enteros positivos directamente para mantener compatibilidad con el backend.
    if (typeof value === 'number' && !isNaN(value) && value > 0) {
      return { number: value, string: String(value) };
    }

    //2.- Desglosa cadenas eliminando espacios y el prefijo histórico `id:` cuando exista.
    if (typeof value === 'string') {
      var trimmed = value.trim();
      if (trimmed === '') {
        return { number: NaN, string: '' };
      }
      if (trimmed.length > 3 && trimmed.slice(0, 3).toLowerCase() === 'id:') {
        trimmed = trimmed.slice(3).trim();
      }
      if (trimmed === '') {
        return { number: NaN, string: '' };
      }
      if (/^\d+$/.test(trimmed)) {
        var parsed = parseInt(trimmed, 10);
        return { number: parsed, string: String(parsed) };
      }

      return { number: NaN, string: trimmed };
    }

    //3.- Convierte otros escalares en cadena para reutilizar la lógica previa y descartar valores falsy.
    if (value && typeof value.valueOf === 'function') {
      return normalizeRowId(String(value.valueOf()));
    }

    return { number: NaN, string: '' };
  }

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
      var trimmedUuid = row.uuid.trim();
      if (trimmedUuid !== '') {
        return trimmedUuid;
      }
    }

    var normalized = normalizeRowId(row.id);
    if (!isNaN(normalized.number)) {
      return 'id:' + normalized.number;
    }
    if (normalized.string) {
      return 'id:' + normalized.string;
    }

    return '';
  }

  //3.6.- Escapa selectores de atributos para soportar UUIDs con caracteres especiales en todos los navegadores.
  function escapeAttributeSelector(value) {
    if (typeof value !== 'string') {
      return '';
    }
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
      return CSS.escape(value);
    }
    var sanitized = value.replace(/\\/g, '\\\\');
    sanitized = sanitized.replace(/"/g, '\\"');
    return sanitized;
  }

  //3.7.- Rehidrata la referencia al formulario incluso cuando el DOM se reemplaza vía AJAX.
  function resolveAdminRoot(modal) {
    if (!modal) {
      return null;
    }

    //1.- Prioriza la referencia almacenada durante la apertura del modal cuando aún está en el DOM.
    var stored = modal._pdsTimelineRoot || null;
    if (stored && stored.isConnected) {
      return stored;
    }

    //2.- Busca el contenedor directamente con el UUID del bloque cuando está disponible.
    var rootId = modal.dataset.rootId || '';
    if (rootId) {
      var escapedId = escapeAttributeSelector(rootId);
      if (escapedId) {
        var direct = document.querySelector('.js-pds-template-admin[data-pds-template-block-uuid="' + escapedId + '"]');
        if (direct) {
          return direct;
        }
      }
    }

    //3.- Filtra candidatos por el tipo de receta para elegir el formulario activo correcto.
    var recipeType = modal.dataset.recipeType || '';
    var candidates = Array.prototype.slice.call(document.querySelectorAll('.js-pds-template-admin'));
    if (candidates.length === 0) {
      return null;
    }

    var matchesRecipe = candidates.filter(function (candidate) {
      var candidateType = candidate.getAttribute('data-pds-template-recipe-type') || '';
      return recipeType && candidateType === recipeType;
    });
    if (matchesRecipe.length === 1) {
      return matchesRecipe[0];
    }

    //4.- Fallback al primer formulario con UUID cuando múltiples coincidencias comparten la misma receta.
    if (matchesRecipe.length > 1) {
      for (var i = 0; i < matchesRecipe.length; i++) {
        if (matchesRecipe[i].getAttribute('data-pds-template-block-uuid')) {
          return matchesRecipe[i];
        }
      }
    }

    //5.- Como último recurso toma el único formulario disponible para no bloquear la acción.
    if (candidates.length === 1) {
      return candidates[0];
    }

    for (var j = 0; j < candidates.length; j++) {
      if (candidates[j].getAttribute('data-pds-template-block-uuid')) {
        return candidates[j];
      }
    }

    return candidates[0];
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
    forEachElement(modal.querySelectorAll('.pds-timeline-admin-row'), function (row, position) {
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
      if (label.length > 512) {
        //11.1.- Reducimos la descripción al límite del esquema para prevenir rechazos del backend.
        label = label.slice(0, 512);
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
    var uuid = row.uuid && typeof row.uuid === 'string' ? row.uuid.trim() : '';
    var normalizedId = normalizeRowId(row.id);
    if (uuid && isValidUuid(uuid)) {
      key = uuid;
    }
    if (!key) {
      if (!isNaN(normalizedId.number)) {
        key = 'id:' + normalizedId.number;
      }
      else if (normalizedId.string) {
        key = 'id:' + normalizedId.string;
      }
    }
    if (!key) {
      return;
    }

    master.datasets.timeline[key] = {
      id: !isNaN(normalizedId.number)
        ? normalizedId.number
        : (normalizedId.string ? normalizedId.string : null),
      uuid: uuid && isValidUuid(uuid) ? uuid : '',
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
    if (!root) {
      root = resolveAdminRoot(modal);
    }
    if (!root) {
      return Promise.reject(new Error(Drupal.t('Unable to locate the recipe form. Refresh the page and try again.')));
    }

    //13.1.1.- Refresca el UUID y conserva la referencia para guardados subsecuentes dentro de la misma sesión.
    var refreshedId = root.getAttribute('data-pds-template-block-uuid') || '';
    if (refreshedId) {
      modal.dataset.rootId = refreshedId;
    }
    modal._pdsTimelineRoot = root;

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
    var rowUuid = typeof row.uuid === 'string' ? row.uuid.trim() : '';
    if (rowUuid && !isValidUuid(rowUuid)) {
      rowUuid = '';
      row.uuid = '';
      rows[index].uuid = '';
    }
    var rowIdRawString = typeof row.id === 'string' ? row.id.trim() : '';
    var normalizedRowId = normalizeRowId(row.id);
    var rowIdNumber = normalizedRowId.number;
    var rowIdString = normalizedRowId.string;

    //13.3.1.- Resolve the canonical identifier by falling back to the numeric id when UUIDs are absent.
    if (!rowUuid) {
      var dataset = readTimelineDataset(root);
      if (dataset) {
        var datasetKey = buildRowKey(row);
        if (datasetKey && dataset[datasetKey] && typeof dataset[datasetKey].uuid === 'string') {
          var datasetUuid = dataset[datasetKey].uuid.trim();
          if (datasetUuid && isValidUuid(datasetUuid)) {
            rowUuid = datasetUuid;
          }
        }
        if (!rowUuid && (rowIdString || !isNaN(rowIdNumber))) {
          var numericCandidate = !isNaN(rowIdNumber) ? rowIdNumber : null;
          var stringCandidate = rowIdString || (numericCandidate !== null ? String(numericCandidate) : '');
          Object.keys(dataset).some(function (candidateKey) {
            var candidate = dataset[candidateKey];
            if (!candidate || typeof candidate !== 'object') {
              return false;
            }
            var candidateUuid = typeof candidate.uuid === 'string' ? candidate.uuid.trim() : '';
            if (!candidateUuid || !isValidUuid(candidateUuid)) {
              return false;
            }
            var normalizedCandidate = normalizeRowId(candidate.id);
            var candidateIdNumber = normalizedCandidate.number;
            var candidateComparableId = normalizedCandidate.string;
            if (stringCandidate && candidateComparableId && candidateComparableId === stringCandidate) {
              rowUuid = candidateUuid;
              return true;
            }
            if (numericCandidate !== null && !isNaN(candidateIdNumber) && candidateIdNumber === numericCandidate) {
              rowUuid = candidateUuid;
              return true;
            }
            return false;
          });
        }
      }
    }

    if (rowUuid && isValidUuid(rowUuid)) {
      row.uuid = rowUuid;
      rows[index].uuid = rowUuid;
    }

    if (rowUuid === 'undefined' || rowUuid === 'null') {
      //13.3.0.- Limpia UUIDs serializados como cadenas literales provenientes de estados viejos.
      rowUuid = '';
      row.uuid = '';
      rows[index].uuid = '';
    }

    if (!rowUuid && !rowIdString && (isNaN(rowIdNumber) || rowIdNumber <= 0)) {
      return Promise.reject(new Error('Row identifier is required to persist timeline.'));
    }

    var entries = collectEntries(modal);
    //13.4.- Construimos una lista priorizada de identificadores para reintentar cuando el UUID almacenado falle.
    var identifierCandidates = [];
    if (rowUuid && isValidUuid(rowUuid)) {
      identifierCandidates.push({ type: 'uuid', value: rowUuid });
    }
    if (!isNaN(rowIdNumber) && rowIdNumber > 0) {
      identifierCandidates.push({ type: 'id', value: String(rowIdNumber), numeric: rowIdNumber });
    }
    else if (rowIdString && rowIdString !== '') {
      identifierCandidates.push({ type: 'id', value: rowIdString });
    }

    if (identifierCandidates.length === 0) {
      return Promise.reject(new Error('Timeline endpoint is missing the row identifier.'));
    }

    var lastError = null;

    if (updateUrlTemplate && updateUrlTemplate.indexOf('type=') === -1 && recipeType) {
      //13.2.1.- Reanexamos el tipo de receta al endpoint cuando el atributo se hidrata sin el query string.
      var joiner = updateUrlTemplate.indexOf('?') === -1 ? '?' : '&';
      updateUrlTemplate += joiner + 'type=' + encodeURIComponent(recipeType);
    }

    return attemptPersistence(0);

    function attemptPersistence(position) {
      if (position >= identifierCandidates.length) {
        return Promise.reject(lastError || new Error('Unable to save timeline.'));
      }

      var candidate = identifierCandidates[position];

      return dispatchPersistence(candidate).catch(function (error) {
        lastError = error;
        var fallbackMessage = error && error.message ? error.message.toLowerCase() : '';
        var shouldRetryWithId = candidate.type === 'uuid'
          && position + 1 < identifierCandidates.length
          && (error && (
            error.status === 404
            || error.status === 400
            || error.status === 403
            || error.status === 409
            || error.status === 422
            || fallbackMessage.indexOf('uuid') !== -1
            || fallbackMessage.indexOf('identifier') !== -1
            || fallbackMessage.indexOf('row not found') !== -1
            || fallbackMessage.indexOf('row does not belong') !== -1
          ));
        if (shouldRetryWithId) {
          //13.4.2.- Clear the cached UUID so the subsequent retry builds the request around the numeric identifier instead.
          rowUuid = '';
          row.uuid = '';
          rows[index].uuid = '';
          return attemptPersistence(position + 1);
        }
        throw error;
      });
    }

    function dispatchPersistence(candidate) {
      var targetUrl = buildUpdateUrl(updateUrlTemplate, candidate.value);
      if (targetUrl.indexOf('type=') === -1 && recipeType) {
        //13.4.3.- Aseguramos que cada intento conserve el tipo de receta para alcanzar el decorador de timeline.
        var glue = targetUrl.indexOf('?') === -1 ? '?' : '&';
        targetUrl += glue + 'type=' + encodeURIComponent(recipeType);
      }
      var payload = buildPersistencePayload(candidate);

      return sendPersistenceRequest(targetUrl, payload).then(function (result) {
        var status = result.status;
        var ok = result.ok;
        var body = result.body;
        var json = null;
        if (body) {
          try {
            json = JSON.parse(body);
          } catch (parseError) {
            //13.4.1.- Ignoramos el fallo de parseo para construir mensajes descriptivos abajo.
          }
        }
        if (!ok) {
          var message = json && json.message ? json.message : 'Unable to save timeline.';
          var error = new Error(message);
          error.status = status;
          error.responseJson = json;
          throw error;
        }
        if (!json || json.status !== 'ok') {
          var fallbackMessage = json && json.message ? json.message : 'Unable to save timeline.';
          var rejection = new Error(fallbackMessage);
          rejection.status = status;
          rejection.responseJson = json;
          throw rejection;
        }
        return handleSuccessfulResponse(json);
      });
    }

    function sendPersistenceRequest(url, payload) {
      //13.4.2.- Estandarizamos la solicitud PATCH en JSON y aplicamos un respaldo con XMLHttpRequest cuando fetch no existe.
      var requestBody = JSON.stringify(payload);
      if (typeof window.fetch === 'function') {
        return window.fetch(url, {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin',
          body: requestBody
        }).then(function (response) {
          return response.text().then(function (body) {
            return {
              status: response.status,
              ok: response.ok,
              body: body
            };
          });
        });
      }

      return new Promise(function (resolve, reject) {
        try {
          var xhr = new XMLHttpRequest();
          xhr.open('PATCH', url, true);
          xhr.withCredentials = true;
          xhr.setRequestHeader('Content-Type', 'application/json');
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
              return;
            }
            var responseOk = xhr.status >= 200 && xhr.status < 300;
            resolve({
              status: xhr.status,
              ok: responseOk,
              body: xhr.responseText || ''
            });
          };
          xhr.onerror = function () {
            reject(new Error('Network request failed.'));
          };
          xhr.send(requestBody);
        } catch (networkError) {
          reject(networkError);
        }
      });
    }

    function buildUpdateUrl(template, identifier) {
      if (template.indexOf(UPDATE_PLACEHOLDER) !== -1) {
        return template.replace(UPDATE_PLACEHOLDER, identifier);
      }
      var queryIndex = template.indexOf('?');
      if (queryIndex !== -1) {
        var baseUrl = template.slice(0, queryIndex).replace(/\/$/, '');
        var query = template.slice(queryIndex);
        return baseUrl + '/' + identifier + query;
      }
      return template.replace(/\/$/, '') + '/' + identifier;
    }

    function buildPersistencePayload(candidate) {
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

      var candidateId = null;
      if (candidate.type === 'id') {
        if (typeof candidate.numeric === 'number' && !isNaN(candidate.numeric)) {
          candidateId = candidate.numeric;
        }
        else if (candidate.value) {
          candidateId = candidate.value;
        }
      }
      else if (!isNaN(rowIdNumber) && rowIdNumber > 0) {
        candidateId = rowIdNumber;
      }
      else if (rowIdString) {
        candidateId = rowIdString;
      }

      if (candidateId !== null && candidateId !== '') {
        payloadRow.id = candidateId;
      }
      else if (typeof row.id !== 'undefined') {
        payloadRow.id = row.id;
      }

      if (candidate.type === 'uuid' && candidate.value) {
        payloadRow.uuid = candidate.value;
      }
      else if (typeof payloadRow.uuid !== 'undefined') {
        delete payloadRow.uuid;
      }

      var payload = {
        row: payloadRow,
        recipe_type: recipeType,
        weight: typeof row.weight === 'number' ? row.weight : index
      };

      var payloadRowIdValue = null;
      if (candidate.type === 'id') {
        payloadRowIdValue = candidateId;
      }
      else if (!isNaN(rowIdNumber) && rowIdNumber > 0) {
        payloadRowIdValue = rowIdNumber;
      }
      else if (rowIdString) {
        payloadRowIdValue = rowIdString;
      }
      else if (rowIdRawString) {
        payloadRowIdValue = rowIdRawString;
      }

      if (payloadRowIdValue !== null && payloadRowIdValue !== '') {
        payload.row_id = payloadRowIdValue;
      }

      return payload;
    }

    function handleSuccessfulResponse(json) {
      var finalRow = Object.assign({}, row);
      if (json.row && typeof json.row === 'object') {
        finalRow = Object.assign(finalRow, json.row);
      }
      if (typeof json.id === 'number') {
        finalRow.id = json.id;
      }
      if (json.uuid) {
        finalRow.uuid = json.uuid;
        rowUuid = json.uuid;
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
    }
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
    forEachElement(container.querySelectorAll('tr[data-row-index]'), function (rowEl) {
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
      forEachElement(once('pdsTimelineAdminRoot', '.js-pds-template-admin', context), function (root) {
        annotatePreview(root);
        observePreview(root);
        bindManageClick(root);
      });
    }
  };

})(Drupal, once, drupalSettings);

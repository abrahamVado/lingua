(function (Drupal) {

  function clamp(n, min, max) {
    return n < min ? min : (n > max ? max : n);
  }

  Drupal.behaviors.principalMapaAdmin = {
    attach: function (context) {

      // Find each preview once.
      const previews = context.querySelectorAll('.pds-map-preview:not([data-map-init])');
      previews.forEach(preview => {
        preview.setAttribute('data-map-init', '1');

        const img = preview.querySelector('.pds-map-preview__img');
        if (!img) {
          return;
        }

        function initDragging() {
          const pins = preview.querySelectorAll('.pds-map-preview__pin'); // buttons with .pin too

          pins.forEach(pinEl => {
            if (pinEl.hasAttribute('data-drag-init')) {
              return;
            }
            pinEl.setAttribute('data-drag-init', '1');

            let dragging = false;

            // mousedown starts drag
            pinEl.addEventListener('mousedown', function (e) {
              e.preventDefault(); // do not focus the button or submit anything
              dragging = true;
              pinEl.classList.add('is-dragging');
            });

            // move follows mouse while dragging
            window.addEventListener('mousemove', function (e) {
              if (!dragging) {
                return;
              }

              const rect = img.getBoundingClientRect();

              // Pointer relative to image
              const relX = e.clientX - rect.left;
              const relY = e.clientY - rect.top;

              // Normalize 0..1
              let xRatio = clamp(relX / rect.width, 0, 1);
              let yRatio = clamp(relY / rect.height, 0, 1);

              // Apply visual position in %
              pinEl.style.left = (xRatio * 100).toFixed(3) + '%';
              pinEl.style.top  = (yRatio * 100).toFixed(3) + '%';

              // Sync numeric inputs in the table so this persists on Save
              const idx = pinEl.getAttribute('data-index');
              if (idx === null) {
                return;
              }

              // normal block form names vs LB modal names
              const xSelectors = [
                `[name="settings[pins_ui][pins][${idx}][x]"]`,
                `[name="pins_ui[pins][${idx}][x]"]`
              ];
              const ySelectors = [
                `[name="settings[pins_ui][pins][${idx}][y]"]`,
                `[name="pins_ui[pins][${idx}][y]"]`
              ];

              let xInput = null;
              let yInput = null;

              for (let sel of xSelectors) {
                const cand = context.querySelector(sel);
                if (cand) { xInput = cand; break; }
              }
              for (let sel of ySelectors) {
                const cand = context.querySelector(sel);
                if (cand) { yInput = cand; break; }
              }

              if (xInput) {
                xInput.value = xRatio.toFixed(3);
              }
              if (yInput) {
                yInput.value = yRatio.toFixed(3);
              }
            });

            // mouseup ends drag
            window.addEventListener('mouseup', function () {
              if (dragging) {
                dragging = false;
                pinEl.classList.remove('is-dragging');
              }
            });
          });

          // Also reflect manual edits of X/Y back to pin position
          if (!preview.hasAttribute('data-sync-init')) {
            preview.setAttribute('data-sync-init', '1');

            const formEl = preview.closest('form');
            if (formEl) {
              formEl.addEventListener('input', function (ev) {
                const t = ev.target;
                if (!t.name) {
                  return;
                }

                // match ...[pins][<idx>][x] or ...[pins][<idx>][y]
                const m = t.name.match(/pins]\[(\d+)]\[(x|y)]$/);
                if (!m) {
                  return;
                }

                const idx = m[1];
                const axis = m[2];
                const raw = parseFloat(t.value);
                if (Number.isNaN(raw)) {
                  return;
                }

                const val = clamp(raw, 0, 1);

                const pinTarget = preview.querySelector('.pds-map-preview__pin[data-index="' + idx + '"]');
                if (!pinTarget) {
                  return;
                }

                if (axis === 'x') {
                  pinTarget.style.left = (val * 100).toFixed(3) + '%';
                }
                else {
                  pinTarget.style.top  = (val * 100).toFixed(3) + '%';
                }
              });
            }
          }
        }

        // Wait for the image to know dimensions
        if (img.complete) {
          initDragging();
        } else {
          img.addEventListener('load', initDragging, { once: true });
        }
      });
    }
  };

})(Drupal);

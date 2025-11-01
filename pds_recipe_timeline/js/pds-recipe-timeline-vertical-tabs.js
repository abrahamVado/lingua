((Drupal, once) => {
  Drupal.behaviors.pdsRecipeTimelineVerticalTabs = {
    attach(context) {
      //1.- Locate every vertical tab wrapper rendered by the admin form.
      once('pds-recipe-vertical-tabs', '[data-pds-vertical-tabs]', context).forEach((root) => {
        const menu = root.querySelector('[data-pds-vertical-tabs-menu]');
        if (!menu) {
          return;
        }

        const anchors = Array.from(menu.querySelectorAll('[data-pds-vertical-tab]'));
        const items = anchors.map((anchor) => anchor.closest('.pds-vertical-tabs__menu-item'));
        const panes = Array.from(root.querySelectorAll('[data-pds-vertical-pane]'));
        const hiddenInput = root.querySelector('[data-pds-vertical-tabs-active]');

        if (!anchors.length || !panes.length) {
          return;
        }

        //2.- Hide every pane and reset menu highlighting.
        const reset = () => {
          anchors.forEach((anchor) => {
            anchor.setAttribute('aria-selected', 'false');
            anchor.setAttribute('tabindex', '-1');
          });
          items.forEach((item) => {
            if (item) {
              item.classList.remove('is-selected');
            }
          });
          panes.forEach((pane) => {
            pane.hidden = true;
            pane.setAttribute('aria-hidden', 'true');
          });
        };

        //3.- Provide a helper that cancels edits when abandoning the edit tab.
        const triggerEditCancel = () => {
          const cancelButton = root.querySelector('[name="pds_recipe_timeline_cancel_edit"]');
          if (!cancelButton || cancelButton.disabled) {
            return false;
          }

          if (typeof cancelButton.click === 'function') {
            cancelButton.click();
          }
          else {
            const eventInit = { bubbles: true, cancelable: true };
            let clickEvent;
            if (typeof window !== 'undefined' && typeof window.MouseEvent === 'function') {
              clickEvent = new window.MouseEvent('click', eventInit);
            }
            else {
              clickEvent = new Event('click', eventInit);
            }
            cancelButton.dispatchEvent(clickEvent);
          }

          return true;
        };

        let activeKey = null;
        let pointerCancelTriggered = false;

        //4.- Activate the chosen tab and show the matching pane.
        const activate = (anchor, focus = true) => {
          if (!anchor) {
            return;
          }

          const nextKey = anchor.getAttribute('data-pds-vertical-tab') || '';
          const previousKey = activeKey;

          if (previousKey === 'edit' && nextKey !== 'edit' && !pointerCancelTriggered) {
            triggerEditCancel();
          }

          pointerCancelTriggered = false;

          reset();

          const item = anchor.closest('.pds-vertical-tabs__menu-item');
          if (item) {
            item.classList.add('is-selected');
          }

          anchor.setAttribute('aria-selected', 'true');
          anchor.removeAttribute('tabindex');

          let paneKey = nextKey;
          let pane = null;
          if (paneKey) {
            pane = root.querySelector('[data-pds-vertical-pane="' + paneKey + '"]');
          }
          if (!pane) {
            const href = anchor.getAttribute('href');
            if (href && href.startsWith('#')) {
              pane = root.querySelector(href);
              if (pane && !paneKey) {
                paneKey = href.slice(1);
              }
            }
          }

          if (pane) {
            pane.hidden = false;
            pane.removeAttribute('hidden');
            pane.setAttribute('aria-hidden', 'false');
          }

          if (hiddenInput) {
            hiddenInput.value = paneKey || '';
          }

          activeKey = paneKey || '';

          if (focus) {
            anchor.focus();
          }
        };

        //5.- Detect pointer interaction early so cancel fires before switching panes.
        menu.addEventListener('pointerdown', (event) => {
          const anchor = event.target.closest('[data-pds-vertical-tab]');
          if (!anchor) {
            return;
          }

          const nextKey = anchor.getAttribute('data-pds-vertical-tab') || '';
          if (activeKey === 'edit' && nextKey !== 'edit') {
            pointerCancelTriggered = triggerEditCancel();
          }
        });

        //6.- Support mouse activation while preventing default anchor jumps.
        menu.addEventListener('click', (event) => {
          const anchor = event.target.closest('[data-pds-vertical-tab]');
          if (!anchor) {
            return;
          }
          event.preventDefault();
          activate(anchor, true);
        });

        //7.- Enable keyboard navigation consistent with WAI-ARIA tabs.
        menu.addEventListener('keydown', (event) => {
          const currentIndex = anchors.indexOf(document.activeElement);
          if (currentIndex === -1) {
            return;
          }

          let nextAnchor = null;
          switch (event.key) {
            case 'ArrowDown':
            case 'ArrowRight':
              nextAnchor = anchors[(currentIndex + 1) % anchors.length];
              break;
            case 'ArrowUp':
            case 'ArrowLeft':
              nextAnchor = anchors[(currentIndex - 1 + anchors.length) % anchors.length];
              break;
            case 'Home':
              nextAnchor = anchors[0];
              break;
            case 'End':
              nextAnchor = anchors[anchors.length - 1];
              break;
            case 'Enter':
            case ' ': // Spacebar
              event.preventDefault();
              activate(anchors[currentIndex], true);
              return;
            default:
              return;
          }

          event.preventDefault();
          if (nextAnchor) {
            nextAnchor.focus();
          }
        });

        //8.- Initialize the widget using the server-provided selection.
        const preselected = anchors.find((anchor) => anchor.getAttribute('aria-selected') === 'true');
        activate(preselected || anchors[0], false);
      });
    },
  };
})(Drupal, once);

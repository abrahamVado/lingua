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

        //3.- Activate the chosen tab and show the matching pane.
        const activate = (anchor, focus = true) => {
          if (!anchor) {
            return;
          }

          reset();

          const item = anchor.closest('.pds-vertical-tabs__menu-item');
          if (item) {
            item.classList.add('is-selected');
          }

          anchor.setAttribute('aria-selected', 'true');
          anchor.removeAttribute('tabindex');

          const paneKey = anchor.getAttribute('data-pds-vertical-tab');
          let pane = null;
          if (paneKey) {
            pane = root.querySelector('[data-pds-vertical-pane="' + paneKey + '"]');
          }
          if (!pane) {
            const href = anchor.getAttribute('href');
            if (href && href.startsWith('#')) {
              pane = root.querySelector(href);
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

          if (focus) {
            anchor.focus();
          }
        };

        //4.- Support mouse activation while preventing default anchor jumps.
        menu.addEventListener('click', (event) => {
          const anchor = event.target.closest('[data-pds-vertical-tab]');
          if (!anchor) {
            return;
          }
          event.preventDefault();
          activate(anchor, true);
        });

        //5.- Enable keyboard navigation consistent with WAI-ARIA tabs.
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

        //6.- Initialize the widget using the server-provided selection.
        const preselected = anchors.find((anchor) => anchor.getAttribute('aria-selected') === 'true');
        activate(preselected || anchors[0], false);
      });
    },
  };
})(Drupal, once);

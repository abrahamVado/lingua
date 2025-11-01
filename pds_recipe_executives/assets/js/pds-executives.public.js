/**
 * Principal Executives Executives interactions.
 */
(function (Drupal, once) {
  Drupal.behaviors.principalExecutives = {
    attach(context) {
      //1.- Process each executives wrapper only once per behavior attachment.
      const wrappers = once('principal-executives-init', '.principal-executives', context);
      wrappers.forEach((root) => {
        //2.- Gather interactive pieces inside the current wrapper.
        const viewport = root.querySelector('.principal-executives__viewport') || window;
        const segments = root.querySelectorAll('.principal-executives__job-segment:not(.principal-executives__job-segment--first)');
        if (!segments.length) {
          return;
        }

        let openSeg = null;

        //3.- Helper closes whichever segment bubble is currently visible.
        const closeOpen = () => {
          if (!openSeg) {
            return;
          }
          openSeg.classList.remove('is-open');
          openSeg = null;
        };

        //4.- Toggle the info bubble when a segment is clicked.
        const onSegmentClick = (event) => {
          const segment = event.currentTarget;
          if (openSeg === segment) {
            closeOpen();
            return;
          }

          if (openSeg) {
            openSeg.classList.remove('is-open');
          }

          segment.classList.add('is-open');
          openSeg = segment;
          event.stopPropagation();
        };

        segments.forEach((segment) => {
          segment.addEventListener('click', onSegmentClick);
        });

        //5.- Close the bubble if a click happens outside of any segment.
        const onDocumentClick = (event) => {
          if (!openSeg) {
            return;
          }
          if (!event.target.closest('.principal-executives__job-segment')) {
            closeOpen();
          }
        };

        document.addEventListener('click', onDocumentClick);

        //6.- Hide bubbles during scroll or resize interactions.
        const hideOnScroll = () => closeOpen();
        viewport.addEventListener('scroll', hideOnScroll, { passive: true });
        window.addEventListener('scroll', hideOnScroll, { passive: true });
        window.addEventListener('resize', hideOnScroll, { passive: true });

        //7.- Store references so detach() can remove listeners cleanly.
        root.__principalExecutivesDocClick = onDocumentClick;
        root.__principalExecutivesHideOnScroll = hideOnScroll;
        root.__principalExecutivesViewport = viewport;
      });
    },

    detach(context) {
      //8.- When Drupal detaches the context, unhook the listeners added above.
      const wrappers = once.remove('principal-executives-init', '.principal-executives', context);
      wrappers.forEach((root) => {
        const onDocumentClick = root.__principalExecutivesDocClick;
        if (onDocumentClick) {
          document.removeEventListener('click', onDocumentClick);
        }

        const hideOnScroll = root.__principalExecutivesHideOnScroll;
        const viewport = root.__principalExecutivesViewport || window;
        if (hideOnScroll) {
          viewport.removeEventListener('scroll', hideOnScroll);
          window.removeEventListener('scroll', hideOnScroll);
          window.removeEventListener('resize', hideOnScroll);
        }

        delete root.__principalExecutivesDocClick;
        delete root.__principalExecutivesHideOnScroll;
        delete root.__principalExecutivesViewport;
      });
    }
  };
})(Drupal, once);

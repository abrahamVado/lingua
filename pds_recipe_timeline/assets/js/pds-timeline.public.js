/**
 * Principal Executives Timeline
 * - Click to toggle a segment's info bubble.
 * - Close on outside click, scroll, or resize.
 */
(function (Drupal) {
  Drupal.behaviors.principalTimeline = {
    attach(context) {
      const wrappers = once('principal-timeline-init', '.principal-timeline', context);
      wrappers.forEach((root) => {
        const viewport = root.querySelector('.principal-timeline__viewport') || window;
        const segments = root.querySelectorAll('.principal-timeline__job-segment:not(.principal-timeline__job-segment--first)');
        let openSeg = null;

        const closeOpen = () => {
          if (!openSeg) return;
          openSeg.classList.remove('is-open');
          openSeg = null;
        };

        segments.forEach((seg) => {
          seg.addEventListener('click', (e) => {
            if (openSeg === seg) {
              closeOpen();
              return;
            }
            if (openSeg) openSeg.classList.remove('is-open');
            seg.classList.add('is-open');
            openSeg = seg;
            e.stopPropagation();
          });
        });

        document.addEventListener('click', (e) => {
          if (!openSeg) return;
          if (!e.target.closest('.principal-timeline__job-segment')) {
            closeOpen();
          }
        }, { passive: true });

        const hideOnScroll = () => closeOpen();
        viewport.addEventListener('scroll', hideOnScroll, { passive: true });
        window.addEventListener('scroll', hideOnScroll, { passive: true });
        window.addEventListener('resize', hideOnScroll, { passive: true });
      });
    }
  };
})(Drupal);

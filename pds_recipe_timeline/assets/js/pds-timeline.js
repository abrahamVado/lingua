(function (Drupal, once) {
  Drupal.behaviors.pdsTimeline = Drupal.behaviors.pdsTimeline || {
    attach(context) {
      //1.- Garantizamos que el comportamiento solo se inicialice una vez por renderizado.
      if (once('pds-timeline-root', '#principal-timeline', context).length === 0) {
        return;
      }

      const segments = once(
        'pds-timeline-segment',
        '#principal-timeline .principal-timeline__job-segment:not(.principal-timeline__job-segment--first)',
        context
      ).map((element) => element);

      if (segments.length === 0) {
        return;
      }

      const viewport = document.querySelector('#principal-timeline .principal-timeline__viewport') || window;
      let openSeg = null;

      //2.- Permitimos alternar cada tooltip mediante clicks táctiles o de ratón.
      segments.forEach((segment) => {
        segment.addEventListener('click', (event) => {
          if (openSeg === segment) {
            segment.classList.remove('is-open');
            openSeg = null;
            return;
          }

          if (openSeg) {
            openSeg.classList.remove('is-open');
          }

          segment.classList.add('is-open');
          openSeg = segment;
          event.stopPropagation();
        });
      });

      //3.- Cerramos el tooltip cuando se hace click fuera del timeline.
      document.addEventListener('click', (event) => {
        if (!openSeg) {
          return;
        }
        if (!event.target.closest('#principal-timeline .principal-timeline__job-segment')) {
          openSeg.classList.remove('is-open');
          openSeg = null;
        }
      });

      const hideOnScroll = () => {
        if (openSeg) {
          openSeg.classList.remove('is-open');
          openSeg = null;
        }
      };

      //4.- Resetamos el estado cuando el contenedor o la ventana hacen scroll.
      viewport.addEventListener('scroll', hideOnScroll, { passive: true });
      window.addEventListener('scroll', hideOnScroll, { passive: true });
      window.addEventListener('resize', hideOnScroll);
    },
  };
})(Drupal, once);

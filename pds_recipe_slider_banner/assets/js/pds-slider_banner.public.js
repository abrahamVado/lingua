/**
 * Principal SliderBanner SliderBanner interactions.
 */
(function (Drupal, once) {
  Drupal.behaviors.principalSliderBanner = {
    attach(context) {
      //1.- Process each slider_banner wrapper only once per behavior attachment.
      const wrappers = once('principal-slider_banner-init', '.principal-slider_banner', context);
      wrappers.forEach((root) => {
        //2.- Gather interactive pieces inside the current wrapper.
        const viewport = root.querySelector('.principal-slider_banner__viewport') || window;
        const segments = root.querySelectorAll('.principal-slider_banner__job-segment:not(.principal-slider_banner__job-segment--first)');
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
          if (!event.target.closest('.principal-slider_banner__job-segment')) {
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
        root.__principalSliderBannerDocClick = onDocumentClick;
        root.__principalSliderBannerHideOnScroll = hideOnScroll;
        root.__principalSliderBannerViewport = viewport;
      });
    },

    detach(context) {
      //8.- When Drupal detaches the context, unhook the listeners added above.
      const wrappers = once.remove('principal-slider_banner-init', '.principal-slider_banner', context);
      wrappers.forEach((root) => {
        const onDocumentClick = root.__principalSliderBannerDocClick;
        if (onDocumentClick) {
          document.removeEventListener('click', onDocumentClick);
        }

        const hideOnScroll = root.__principalSliderBannerHideOnScroll;
        const viewport = root.__principalSliderBannerViewport || window;
        if (hideOnScroll) {
          viewport.removeEventListener('scroll', hideOnScroll);
          window.removeEventListener('scroll', hideOnScroll);
          window.removeEventListener('resize', hideOnScroll);
        }

        delete root.__principalSliderBannerDocClick;
        delete root.__principalSliderBannerHideOnScroll;
        delete root.__principalSliderBannerViewport;
      });
    }
  };
})(Drupal, once);

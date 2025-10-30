(function (Drupal, once) {
  'use strict';

  /**
   * //1.- Close the currently open job segment, if any.
   */
  function closeOpenSegment(state) {
    if (state.openSegment) {
      state.openSegment.classList.remove('is-open');
      state.openSegment = null;
    }
  }

  /**
   * //2.- Register click handlers so segments toggle their expanded tooltip.
   */
  function bindSegmentClicks(section, state) {
    var segments = Array.prototype.slice.call(
      section.querySelectorAll(
        '.principal-timeline__job-segment:not(.principal-timeline__job-segment--first)'
      )
    );

    segments.forEach(function (segment) {
      segment.addEventListener('click', function (event) {
        //1.- Prevent ancestor handlers from immediately closing the segment.
        event.stopPropagation();

        if (state.openSegment === segment) {
          //2.- Collapse when the active segment is clicked again.
          closeOpenSegment(state);
          return;
        }

        //3.- Close any previously opened segment to keep a single tooltip visible.
        closeOpenSegment(state);
        segment.classList.add('is-open');
        state.openSegment = segment;
      });
    });
  }

  /**
   * //3.- Collapse the tooltip whenever the user clicks outside of the segments.
   */
  function bindOutsideClicks(section, state) {
    section.addEventListener('click', function (event) {
      if (!event.target.closest('.principal-timeline__job-segment')) {
        closeOpenSegment(state);
      }
    });
  }

  /**
   * //4.- Reset the open tooltip on scroll or resize interactions.
   */
  function bindMotionReset(section, state) {
    var viewport = section.querySelector('.principal-timeline__viewport');

    var hideOnScroll = function hideOnScroll() {
      closeOpenSegment(state);
    };

    if (viewport) {
      viewport.addEventListener('scroll', hideOnScroll, { passive: true });
    }

    window.addEventListener('scroll', hideOnScroll, { passive: true });
    window.addEventListener('resize', hideOnScroll);
  }

  /**
   * //5.- Initialize an executive timeline section after Drupal attaches behaviors.
   */
  function initTimeline(section) {
    var state = { openSegment: null };
    bindSegmentClicks(section, state);
    bindOutsideClicks(section, state);
    bindMotionReset(section, state);
  }

  Drupal.behaviors.pdsTimelinePublic = {
    attach: function attach(context) {
      //1.- Enhance each timeline once to avoid duplicating listeners on AJAX refreshes.
      once('pds-timeline-public', '#principal-timeline', context).forEach(function (section) {
        initTimeline(section);
      });
    },
  };
})(Drupal, once);

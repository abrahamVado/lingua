(function (Drupal) {
  'use strict';

  //1.- Initialize every timeline instance exactly once even when Drupal AJAX rebuilds the markup.
  function attachTimelines(context) {
    var root = context && context.querySelectorAll ? context : document;
    var timelines = root.querySelectorAll('.principal-timeline');

    timelines.forEach(function (timeline) {
      if (timeline.dataset.pdsTimelineReady === '1') {
        return;
      }
      timeline.dataset.pdsTimelineReady = '1';
      initTimeline(timeline);
    });
  }

  //2.- Bind click handlers that toggle inline tooltips on each timeline segment.
  function initTimeline(timeline) {
    var selector = '.principal-timeline__job-segment:not(.principal-timeline__job-segment--first)';
    var segments = timeline.querySelectorAll(selector);
    var viewport = timeline.querySelector('.principal-timeline__viewport') || window;
    var openSeg = null;

    segments.forEach(function (segment) {
      segment.addEventListener('click', function (e) {
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
        e.stopPropagation();
      });
    });

    document.addEventListener('click', function (e) {
      if (!openSeg) {
        return;
      }
      if (!e.target.closest(selector)) {
        openSeg.classList.remove('is-open');
        openSeg = null;
      }
    });

    var hideOnScroll = function () {
      if (openSeg) {
        openSeg.classList.remove('is-open');
        openSeg = null;
      }
    };

    viewport.addEventListener('scroll', hideOnScroll, { passive: true });
    window.addEventListener('scroll', hideOnScroll, { passive: true });
    window.addEventListener('resize', hideOnScroll);
  }

  if (typeof Drupal !== 'undefined' && Drupal.behaviors) {
    Drupal.behaviors.pdsTimelinePublic = {
      attach: function (context) {
        attachTimelines(context || document);
      }
    };
  }

  attachTimelines(document);
})(window.Drupal || {});

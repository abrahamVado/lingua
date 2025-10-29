(function (Drupal, once) {
  'use strict';

  function buildDots(section, slides) {
    //1.- Reuse an existing dot wrapper to keep the DOM stable across reattachments.
    var dotsHost = section.querySelector('[data-pds-slider-banner-dots]');
    if (!dotsHost) {
      return [];
    }

    dotsHost.innerHTML = '';
    var dots = [];

    //2.- Mirror the slide order with buttons so keyboard users can jump directly.
    slides.forEach(function (_slide, index) {
      var dot = document.createElement('button');
      dot.className = 'principal_hero__dot';
      dot.type = 'button';
      dot.setAttribute('aria-label', Drupal.t('Go to slide @index', { '@index': index + 1 }));
      dot.setAttribute('data-pds-slider-banner-dot', String(index));
      dotsHost.appendChild(dot);
      dots.push(dot);
    });

    return dots;
  }

  function activateSlide(slides, dots, index) {
    //1.- Hide all slides to prepare for the next active item.
    slides.forEach(function (slide, slideIndex) {
      if (slideIndex === index) {
        slide.removeAttribute('hidden');
        slide.classList.add('is-active');
      }
      else {
        slide.setAttribute('hidden', 'hidden');
        slide.classList.remove('is-active');
      }
    });

    //2.- Synchronize the pagination dots to reflect the visible slide.
    dots.forEach(function (dot, dotIndex) {
      if (dotIndex === index) {
        dot.classList.add('is-active');
        dot.setAttribute('aria-current', 'true');
      }
      else {
        dot.classList.remove('is-active');
        dot.removeAttribute('aria-current');
      }
    });
  }

  function createAutoplay(intervalMs, callback) {
    //1.- Avoid scheduling timers for invalid delay values.
    if (!intervalMs || intervalMs < 0) {
      return { stop: function stop() {} };
    }

    var timerId = window.setTimeout(callback, intervalMs);

    return {
      stop: function stop() {
        //2.- Guarantee no stale timers execute after teardown.
        if (timerId) {
          window.clearTimeout(timerId);
          timerId = 0;
        }
      },
    };
  }

  function initSlider(section) {
    //1.- Collect the interactive pieces only once per render root.
    var track = section.querySelector('[data-pds-slider-banner-track]');
    if (!track) {
      return;
    }

    var slides = Array.prototype.slice.call(
      track.querySelectorAll('[data-pds-slider-banner-slide]')
    );
    if (!slides.length) {
      return;
    }

    var prevBtn = section.querySelector('[data-pds-slider-banner-prev]');
    var nextBtn = section.querySelector('[data-pds-slider-banner-next]');
    var dots = buildDots(section, slides);
    var autoplayDelay = Number(section.getAttribute('data-pds-slider-banner-autoplay')) || 6000;
    var activeIndex = 0;
    var timer = null;

    function scheduleNext() {
      //1.- Reset any existing timer so rapid interactions do not accelerate autoplay.
      if (timer) {
        timer.stop();
      }

      timer = createAutoplay(autoplayDelay, function () {
        goToSlide(activeIndex + 1);
      });
    }

    function goToSlide(nextIndex) {
      //1.- Normalize the desired index to wrap seamlessly.
      if (slides.length === 0) {
        return;
      }

      var normalized = (nextIndex + slides.length) % slides.length;
      activeIndex = normalized;
      activateSlide(slides, dots, normalized);
      scheduleNext();
    }

    function handlePrev() {
      //1.- Step backwards one slide on button activation.
      goToSlide(activeIndex - 1);
    }

    function handleNext() {
      //1.- Step forward one slide on button activation.
      goToSlide(activeIndex + 1);
    }

    //1.- Display the initial slide immediately for perceived performance.
    activateSlide(slides, dots, activeIndex);
    scheduleNext();

    if (prevBtn) {
      prevBtn.addEventListener('click', handlePrev);
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', handleNext);
    }

    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        //1.- Jump to the requested slide while restarting autoplay.
        var target = Number(dot.getAttribute('data-pds-slider-banner-dot')) || 0;
        goToSlide(target);
      });
    });

    section.addEventListener('keydown', function (event) {
      //1.- Support arrow key navigation when focus is within the slider.
      var key = event.key;
      if (key === 'ArrowLeft') {
        event.preventDefault();
        handlePrev();
      }
      else if (key === 'ArrowRight') {
        event.preventDefault();
        handleNext();
      }
    });

    section.addEventListener('mouseenter', function () {
      //1.- Pause autoplay on hover so readers can finish the content.
      if (timer) {
        timer.stop();
      }
    });

    section.addEventListener('mouseleave', function () {
      //1.- Resume autoplay when the pointer leaves the slider region.
      scheduleNext();
    });
  }

  Drupal.behaviors.pdsSliderBanner = {
    attach: function attach(context) {
      //1.- Initialize each slider instance exactly once during progressive enhancement.
      once('pds-slider-banner', '[data-pds-slider-banner-root]', context).forEach(function (section) {
        initSlider(section);
      });
    },
  };
})(Drupal, once);

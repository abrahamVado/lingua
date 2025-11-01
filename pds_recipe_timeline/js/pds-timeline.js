//1.- Locate all toggle buttons inside the timeline component.
(function () {
  const timeline = document.querySelector('.principal-timeline');
  if (!timeline) {
    return;
  }

  const toggles = timeline.querySelectorAll('.principal-timeline__toggle');
  if (!toggles.length) {
    return;
  }

  //2.- Helper closes every expanded item except the one provided.
  function collapseOthers(except) {
    toggles.forEach((button) => {
      if (button !== except) {
        button.setAttribute('aria-expanded', 'false');
        const details = button.nextElementSibling;
        if (details) {
          details.hidden = true;
        }
      }
    });
  }

  //3.- Attach click handlers to toggle open/close state per event.
  toggles.forEach((button) => {
    button.addEventListener('click', () => {
      const isOpen = button.getAttribute('aria-expanded') === 'true';
      const details = button.nextElementSibling;
      if (!details) {
        return;
      }

      if (isOpen) {
        button.setAttribute('aria-expanded', 'false');
        details.hidden = true;
      }
      else {
        collapseOthers(button);
        button.setAttribute('aria-expanded', 'true');
        details.hidden = false;
      }
    });
  });
})();
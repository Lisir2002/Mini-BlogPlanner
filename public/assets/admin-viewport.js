(function () {
  'use strict';

  var root = document.documentElement;
  var pending = false;

  function sync() {
    pending = false;
    var viewport = window.visualViewport;
    if (!viewport) {
      root.classList.remove('is-visually-zoomed');
      root.style.removeProperty('--admin-visible-width');
      root.style.removeProperty('--admin-fit-width');
      root.style.removeProperty('--admin-fit-left');
      root.style.removeProperty('--admin-right-inset');
      root.style.removeProperty('--admin-bottom-inset');
      return;
    }

    var width = Number(viewport.width) || window.innerWidth;
    var height = Number(viewport.height) || window.innerHeight;
    var left = Number(viewport.offsetLeft) || 0;
    var top = Number(viewport.offsetTop) || 0;
    var scale = Number(viewport.scale) || 1;
    var zoomed = scale > 1.02 || width + 2 < window.innerWidth;
    var gutter = window.innerWidth <= 460 ? 11 : 16;
    var fitWidth = Math.max(120, width - gutter * 2);
    var rightInset = Math.max(0, window.innerWidth - left - width);
    var bottomInset = Math.max(0, window.innerHeight - top - height);

    root.style.setProperty('--admin-visible-width', width.toFixed(2) + 'px');
    root.style.setProperty('--admin-fit-width', fitWidth.toFixed(2) + 'px');
    root.style.setProperty('--admin-fit-left', (left + gutter).toFixed(2) + 'px');
    root.style.setProperty('--admin-right-inset', rightInset.toFixed(2) + 'px');
    root.style.setProperty('--admin-bottom-inset', bottomInset.toFixed(2) + 'px');
    root.classList.toggle('is-visually-zoomed', zoomed);
  }

  function schedule() {
    if (pending) {
      return;
    }
    pending = true;
    window.requestAnimationFrame(sync);
  }

  sync();

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', schedule);
    window.visualViewport.addEventListener('scroll', schedule);
  }
  window.addEventListener('resize', schedule);
  window.addEventListener('orientationchange', schedule);
})();

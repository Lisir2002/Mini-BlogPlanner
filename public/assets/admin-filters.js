(function () {
  'use strict';

  var closers = new WeakMap();
  var FILTER_ICON = '<svg class="icon admin-filter-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.5 5.5h15l-5.8 6.5v6.2l-3.4 1.3v-7.5L4.5 5.5Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
  var panelIndex = 0;

  function activeCount(form) {
    var raw = Number.parseInt(form.getAttribute('data-active-filter-count') || '0', 10);
    return Number.isFinite(raw) && raw > 0 ? raw : 0;
  }

  function buildToggle(form, id, label, count) {
    var row = document.createElement('div');
    row.className = 'admin-filter-toggle-row';

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'admin-filter-toggle';
    button.setAttribute('aria-controls', id);
    button.setAttribute('aria-expanded', 'false');
    button.innerHTML = FILTER_ICON;

    var text = document.createElement('span');
    text.textContent = label;
    button.appendChild(text);

    if (count > 0) {
      var badge = document.createElement('strong');
      badge.className = 'admin-filter-count';
      badge.textContent = String(count);
      button.appendChild(badge);
      button.classList.add('has-active-filters');
      button.setAttribute('aria-label', label + '，已启用 ' + count + ' 项条件');
    } else {
      button.setAttribute('aria-label', label);
    }

    row.appendChild(button);
    form.parentNode.insertBefore(row, form);
    return { row: row, button: button };
  }

  function buildCardHead() {
    var head = document.createElement('div');
    head.className = 'admin-filter-card-head';

    var title = document.createElement('span');
    title.textContent = '筛选条件';
    head.appendChild(title);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'admin-filter-close';
    close.setAttribute('aria-label', '关闭筛选');
    close.innerHTML = '<span>关闭</span><span class="admin-filter-close-mark" aria-hidden="true">×</span>';
    head.appendChild(close);

    return { head: head, close: close };
  }

  function enhance(form) {
    if (form.getAttribute('data-admin-filter-enhanced') === '1') {
      return;
    }
    form.setAttribute('data-admin-filter-enhanced', '1');

    panelIndex += 1;
    if (!form.id) {
      form.id = 'admin-filter-panel-' + panelIndex;
    }

    var label = String(form.getAttribute('data-admin-filter-label') || '筛选');
    var count = activeCount(form);
    var parts = buildToggle(form, form.id, label, count);
    var card = buildCardHead();
    form.insertBefore(card.head, form.firstChild);
    form.classList.add('admin-filter-collapsible');

    function setOpen(open) {
      form.classList.toggle('is-filter-open', open);
      parts.row.classList.toggle('is-open', open);
      parts.button.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        card.close.focus({ preventScroll: true });
      } else {
        parts.button.focus();
      }
    }

    parts.button.addEventListener('click', function () {
      setOpen(true);
    });

    card.close.addEventListener('click', function () {
      setOpen(false);
    });

    closers.set(form, function () { setOpen(false); });
  }

  function start(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var panels = scope.querySelectorAll('[data-admin-filter-panel]');
    Array.prototype.forEach.call(panels, enhance);
    if (scope.matches && scope.matches('[data-admin-filter-panel]')) {
      enhance(scope);
    }
  }

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || !window.matchMedia('(max-width: 760px)').matches) {
      return;
    }
    var open = document.querySelector('[data-admin-filter-panel].is-filter-open');
    var close = open ? closers.get(open) : null;
    if (close) {
      event.preventDefault();
      close();
    }
  });

  window.AdminFilters = { enhance: start };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { start(document); }, { once: true });
  } else {
    start(document);
  }
})();

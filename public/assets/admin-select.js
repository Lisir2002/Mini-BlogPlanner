/* 后台自绘下拉：保留原生 select 作为数据源，界面完全由纸面菜单绘制。 */
(function () {
  'use strict';

  var sequence = 0;
  var current = null;
  var registry = new WeakMap();

  function text(value) {
    return String(value == null ? '' : value);
  }

  function labelText(select) {
    var explicit = select.getAttribute('aria-label');
    if (explicit) return explicit.trim();
    var label = select.id ? document.querySelector('label[for="' + select.id.replace(/"/g, '\\"') + '"]') : null;
    if (!label) label = select.closest('label');
    if (!label) return '';
    var copy = label.cloneNode(true);
    copy.querySelectorAll('select, .admin-select, input, textarea, button').forEach(function (node) {
      node.remove();
    });
    return text(copy.textContent).replace(/\s+/g, ' ').trim();
  }

  function close(restoreFocus) {
    if (!current) return;
    var state = current;
    current = null;
    state.menu.hidden = true;
    state.trigger.setAttribute('aria-expanded', 'false');
    state.trigger.removeAttribute('aria-activedescendant');
    state.wrapper.classList.remove('is-open');
    document.removeEventListener('pointerdown', state.outside, true);
    document.removeEventListener('keydown', state.keydown, true);
    window.removeEventListener('resize', state.position, true);
    window.removeEventListener('scroll', state.position, true);
    if (restoreFocus !== false) state.trigger.focus({ preventScroll: true });
  }

  function enhance(select) {
    if (!select || select.dataset.adminSelectReady === 'true') return;
    select.dataset.adminSelectReady = 'true';
    sequence += 1;

    var variant = text(select.dataset.selectVariant).trim();
    var options = Array.prototype.slice.call(select.options);
    var wrapper = document.createElement('span');
    wrapper.className = 'admin-select';
    if (variant) wrapper.dataset.variant = variant;
    wrapper.dataset.value = select.value;

    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select);
    select.classList.add('admin-select-native');
    select.setAttribute('tabindex', '-1');
    select.setAttribute('aria-hidden', 'true');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'admin-select-trigger';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    var accessibleLabel = labelText(select);
    if (accessibleLabel) trigger.setAttribute('aria-label', accessibleLabel);

    var dot = document.createElement('span');
    dot.className = 'admin-select-dot';
    dot.setAttribute('aria-hidden', 'true');
    if (variant === 'status') trigger.appendChild(dot);

    var valueLabel = document.createElement('span');
    valueLabel.className = 'admin-select-value';
    trigger.appendChild(valueLabel);

    var caret = document.createElement('span');
    caret.className = 'admin-select-caret';
    caret.setAttribute('aria-hidden', 'true');
    trigger.appendChild(caret);

    wrapper.appendChild(trigger);

    var menu = document.createElement('div');
    menu.className = 'admin-select-menu';
    menu.id = 'admin-select-menu-' + sequence;
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    trigger.setAttribute('aria-controls', menu.id);

    if (variant === 'status') {
      var head = document.createElement('div');
      head.className = 'admin-select-head';
      var headTitle = document.createElement('strong');
      headTitle.textContent = accessibleLabel || '线上状态';
      var headHint = document.createElement('small');
      headHint.textContent = '选择这篇笔记的线上状态';
      head.appendChild(headTitle);
      head.appendChild(headHint);
      menu.appendChild(head);
    }

    var optionNodes = [];
    options.forEach(function (option, index) {
      var node = document.createElement('div');
      node.className = 'admin-select-option';
      node.id = menu.id + '-option-' + index;
      node.setAttribute('role', 'option');
      node.setAttribute('aria-selected', 'false');
      node.dataset.index = String(index);

      var copy = document.createElement('span');
      copy.className = 'admin-select-option-copy';

      var main = document.createElement('span');
      main.className = 'admin-select-option-main';
      main.textContent = text(option.textContent).trim();
      copy.appendChild(main);

      var hint = text(option.dataset.hint).trim();
      if (hint) {
        var small = document.createElement('small');
        small.textContent = hint;
        copy.appendChild(small);
      }

      var radio = document.createElement('span');
      radio.className = 'admin-select-radio';
      radio.setAttribute('aria-hidden', 'true');

      node.appendChild(copy);
      node.appendChild(radio);
      node.addEventListener('pointerenter', function () {
        if (current) setActive(current, index, false);
      });
      node.addEventListener('pointerdown', function (event) {
        event.preventDefault();
      });
      node.addEventListener('click', function (event) {
        event.preventDefault();
        choose(select.activeState, option);
      });
      menu.appendChild(node);
      optionNodes.push(node);
    });

    document.body.appendChild(menu);

    function sync() {
      var selectedIndex = Math.max(0, select.selectedIndex);
      var selectedOption = options[selectedIndex] || null;
      valueLabel.textContent = selectedOption ? text(selectedOption.textContent).trim() : '请选择';
      wrapper.dataset.value = select.value;
      optionNodes.forEach(function (node, index) {
        var selected = index === selectedIndex;
        node.setAttribute('aria-selected', selected ? 'true' : 'false');
        node.classList.toggle('is-selected', selected);
      });
    }

    function setActive(state, index, scroll) {
      var target = Math.max(0, Math.min(optionNodes.length - 1, index));
      state.activeIndex = target;
      optionNodes.forEach(function (node, i) {
        node.classList.toggle('is-active', i === target);
      });
      var active = optionNodes[target];
      if (active) {
        state.trigger.setAttribute('aria-activedescendant', active.id);
        if (scroll) active.scrollIntoView({ block: 'nearest' });
      }
    }

    function position(state) {
      if (!state || !current) return;
      var rect = state.trigger.getBoundingClientRect();
      var margin = 12;
      var preferred = Math.max(260, Math.ceil(rect.width));
      var width = Math.min(preferred, window.innerWidth - margin * 2);
      state.menu.style.width = width + 'px';
      state.menu.style.left = Math.max(margin, Math.min(rect.left, window.innerWidth - width - margin)) + 'px';
      state.menu.hidden = false;
      var height = state.menu.offsetHeight;
      var top = rect.bottom + 8;
      if (top + height > window.innerHeight - margin) top = rect.top - height - 8;
      if (top < margin) top = margin;
      state.menu.style.top = top + 'px';
    }

    function open() {
      if (!options.length) return;
      if (current) close(false);
      var state = {
        select: select,
        wrapper: wrapper,
        trigger: trigger,
        menu: menu,
        activeIndex: Math.max(0, select.selectedIndex),
        position: null,
        outside: null,
        keydown: null
      };
      current = state;
      trigger.setAttribute('aria-expanded', 'true');
      wrapper.classList.add('is-open');
      setActive(state, state.activeIndex, false);
      state.position = function () { position(state); };
      position(state);
      state.menu.scrollTop = 0;
      state.outside = function (event) {
        if (menu.contains(event.target) || trigger.contains(event.target)) return;
        close(false);
      };
      state.keydown = function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          close(true);
          return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          var delta = event.key === 'ArrowDown' ? 1 : -1;
          var next = state.activeIndex + delta;
          if (next < 0) next = optionNodes.length - 1;
          if (next >= optionNodes.length) next = 0;
          setActive(state, next, true);
          return;
        }
        if (event.key === 'Home' || event.key === 'End') {
          event.preventDefault();
          setActive(state, event.key === 'Home' ? 0 : optionNodes.length - 1, true);
          return;
        }
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          var option = options[state.activeIndex];
          if (option) choose(state, option);
          return;
        }
        if (event.key === 'Tab') close(false);
      };
      document.addEventListener('pointerdown', state.outside, true);
      document.addEventListener('keydown', state.keydown, true);
      window.addEventListener('resize', state.position, true);
      window.addEventListener('scroll', state.position, true);
    }

    function choose(state, option) {
      if (!state) return;
      select.value = option.value;
      sync();
      close(true);
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      if (current && current.trigger === trigger) {
        close(true);
        return;
      }
      open();
    });

    trigger.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (!current) {
          event.preventDefault();
          open();
        }
      }
    });

    select.addEventListener('change', sync);
    select.addEventListener('admin-select:refresh', sync);
    if (select.form) {
      select.form.addEventListener('reset', function () {
        window.setTimeout(sync, 0);
      });
    }

    var instance = {
      sync: sync,
      options: optionNodes,
      trigger: trigger
    };
    select.activeState = instance;
    registry.set(select, instance);
    sync();
  }

  function enhanceAll(root) {
    var scope = root && root.querySelectorAll ? root : document;
    Array.prototype.forEach.call(scope.querySelectorAll('select:not([data-admin-select-ready])'), enhance);
    if (scope.matches && scope.matches('select:not([data-admin-select-ready])')) enhance(scope);
  }

  window.AdminSelect = {
    enhance: enhanceAll,
    sync: function (select) {
      if (!select) return;
      var instance = registry.get(select);
      if (instance) instance.sync();
      else select.dispatchEvent(new Event('admin-select:refresh', { bubbles: true }));
    }
  };

  enhanceAll(document);
})();

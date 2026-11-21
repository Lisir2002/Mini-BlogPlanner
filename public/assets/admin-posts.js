/* 后台文章台账交互：行菜单、批量选择、纸面确认。 */
(function () {
  'use strict';

  var main = document.getElementById('admin-main');
  if (!main || !document.body.classList.contains('admin-body')) return;

  var menuState = null;
  var confirmState = null;
  var modal = null;
  var modalTitle = null;
  var modalBody = null;
  var modalConfirm = null;
  var queued = false;

  function scopeName(node) {
    var scope = node && node.closest ? node.closest('[data-post-bulk-scope]') : null;
    return scope ? scope.getAttribute('data-post-bulk-scope') : '';
  }

  function scopeBox(scope) {
    return document.querySelector('.post-admin-bulk-scope[data-post-bulk-scope="' + scope + '"]');
  }

  function scopeForm(scope) {
    return document.getElementById('post-bulk-' + scope);
  }

  function rowBoxes(scope) {
    var box = scopeBox(scope);
    return box ? Array.prototype.slice.call(box.querySelectorAll('[data-post-row-checkbox]')) : [];
  }

  function syncScope(scope) {
    if (!scope) return;
    var boxes = rowBoxes(scope);
    var checked = boxes.filter(function (box) { return box.checked; });
    boxes.forEach(function (box) {
      var row = box.closest('tr, li');
      if (row) row.classList.toggle('is-selected', box.checked);
    });

    var box = scopeBox(scope);
    var all = box ? box.querySelector('[data-post-select-all]') : null;
    if (all) {
      all.checked = boxes.length > 0 && checked.length === boxes.length;
      all.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }

    var form = scopeForm(scope);
    if (!form) return;
    var bar = form.querySelector('[data-post-bulk-bar]');
    if (!bar) return;
    bar.hidden = checked.length === 0;
    var count = bar.querySelector('[data-post-bulk-count]');
    if (count) count.textContent = '已选 ' + checked.length + ' 篇';
    var statuses = checked.map(function (box) {
      var row = box.closest('tr, li');
      return row ? row.getAttribute('data-post-status') : '';
    });
    var archiveButton = bar.querySelector('[data-post-bulk-action="archive"]');
    var restoreButton = bar.querySelector('[data-post-bulk-action="restore"]');
    if (archiveButton) archiveButton.disabled = checked.length === 0 || !statuses.some(function (status) { return status !== 'archived'; });
    if (restoreButton) restoreButton.disabled = checked.length === 0 || !statuses.some(function (status) { return status === 'archived'; });
  }

  function clearScope(scope) {
    rowBoxes(scope).forEach(function (box) { box.checked = false; });
    syncScope(scope);
  }

  function removeMenu() {
    if (!menuState) return;
    var state = menuState;
    menuState = null;
    if (state.menu && state.menu.parentNode) state.menu.parentNode.removeChild(state.menu);
    if (state.trigger) state.trigger.setAttribute('aria-expanded', 'false');
    document.removeEventListener('pointerdown', onDocumentPointer, true);
    document.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('resize', onViewportChange);
  }

  function closeMenu(restoreFocus) {
    var trigger = menuState ? menuState.trigger : null;
    removeMenu();
    if (restoreFocus && trigger && trigger.isConnected) trigger.focus({ preventScroll: true });
  }

  function onDocumentPointer(event) {
    if (!menuState) return;
    if (menuState.menu && menuState.menu.contains(event.target)) return;
    if (menuState.trigger && menuState.trigger.contains(event.target)) return;
    closeMenu(false);
  }

  function onViewportChange() {
    closeMenu(false);
  }

  function positionMenu(menu, trigger) {
    var pad = 8;
    var gap = 6;
    var rect = trigger.getBoundingClientRect();
    var box = menu.getBoundingClientRect();
    var left = rect.right - box.width;
    if (left < pad) left = pad;
    if (left + box.width > window.innerWidth - pad) left = Math.max(pad, window.innerWidth - pad - box.width);
    var top = rect.bottom + gap;
    if (top + box.height > window.innerHeight - pad) top = Math.max(pad, rect.top - box.height - gap);
    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(top) + 'px';
  }

  function openMenu(trigger) {
    var template = trigger.parentElement && trigger.parentElement.parentElement
      ? trigger.parentElement.parentElement.querySelector('template.post-admin-menu-template')
      : null;
    if (!template || !template.content) return;
    closeMenu(false);

    var menu = template.content.firstElementChild.cloneNode(true);
    menu.style.position = 'fixed';
    menu.style.zIndex = '85';
    menu.hidden = false;
    document.body.appendChild(menu);
    trigger.setAttribute('aria-expanded', 'true');
    positionMenu(menu, trigger);
    menuState = { menu: menu, trigger: trigger };
    document.addEventListener('pointerdown', onDocumentPointer, true);
    document.addEventListener('scroll', onViewportChange, true);
    window.addEventListener('resize', onViewportChange);
    var first = menu.querySelector('[role="menuitem"]');
    if (first) first.focus({ preventScroll: true });
  }

  function buildModal() {
    modal = document.createElement('div');
    modal.className = 'post-admin-confirm';
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'post-confirm-title');

    var scrim = document.createElement('div');
    scrim.className = 'post-admin-confirm-scrim';
    var card = document.createElement('div');
    card.className = 'post-admin-confirm-card';
    modalTitle = document.createElement('h2');
    modalTitle.id = 'post-confirm-title';
    modalBody = document.createElement('p');
    var actions = document.createElement('div');
    actions.className = 'post-admin-confirm-actions';
    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'admin-button ghost';
    cancel.textContent = '先不归档';
    modalConfirm = document.createElement('button');
    modalConfirm.type = 'button';
    modalConfirm.className = 'admin-button danger';
    modalConfirm.textContent = '确认归档';

    actions.appendChild(cancel);
    actions.appendChild(modalConfirm);
    card.appendChild(modalTitle);
    card.appendChild(modalBody);
    card.appendChild(actions);
    modal.appendChild(scrim);
    modal.appendChild(card);
    document.body.appendChild(modal);

    scrim.addEventListener('click', function () { closeConfirm(true); });
    cancel.addEventListener('click', function () { closeConfirm(true); });
    modalConfirm.addEventListener('click', function () {
      if (!confirmState) return;
      var form = confirmState.form;
      var submitter = confirmState.submitter;
      var trigger = confirmState.trigger;
      closeConfirm(false);
      if (!form) return;
      form.dataset.confirmed = '1';
      try {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit(submitter && submitter.isConnected ? submitter : undefined);
        } else {
          form.submit();
        }
      } finally {
        delete form.dataset.confirmed;
      }
      window.setTimeout(function () {
        closeMenu(false);
        if (trigger && trigger.isConnected) trigger.focus({ preventScroll: true });
      }, 0);
    });
  }

  function pauseMenuWatchers() {
    if (!menuState) return;
    document.removeEventListener('pointerdown', onDocumentPointer, true);
    document.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('resize', onViewportChange);
  }

  function openConfirm(title, body, form, submitter) {
    if (!modal) buildModal();
    pauseMenuWatchers();
    confirmState = {
      form: form,
      submitter: submitter,
      trigger: menuState ? menuState.trigger : null
    };
    modalTitle.textContent = title || '确认归档？';
    modalBody.textContent = body || '归档后公开页和 API 不再显示，文章数据会保留。';
    modalConfirm.textContent = '确认归档';
    modal.hidden = false;
    modalConfirm.focus({ preventScroll: true });
  }

  function closeConfirm(restoreFocus) {
    if (!modal) return;
    var trigger = confirmState ? confirmState.trigger : null;
    confirmState = null;
    modal.hidden = true;
    if (restoreFocus) {
      closeMenu(false);
      if (trigger && trigger.isConnected) trigger.focus({ preventScroll: true });
    }
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-post-menu]');
    if (trigger) {
      event.preventDefault();
      if (menuState && menuState.trigger === trigger) closeMenu(true);
      else openMenu(trigger);
      return;
    }
    if (event.target.closest('.post-admin-menu a')) closeMenu(false);
    var bulkAction = event.target.closest('[data-post-bulk-action]');
    if (bulkAction) {
      var bulkForm = bulkAction.closest('form[data-post-bulk-scope]');
      var actionField = bulkForm ? bulkForm.querySelector('input[name="bulk_action"]') : null;
      if (actionField) actionField.value = bulkAction.value;
    }
    var clear = event.target.closest('[data-post-bulk-clear]');
    if (clear) {
      var form = clear.closest('form[data-post-bulk-scope]');
      if (form) clearScope(form.getAttribute('data-post-bulk-scope'));
    }
  });

  document.addEventListener('change', function (event) {
    var box = event.target.closest('[data-post-row-checkbox]');
    if (box) {
      syncScope(scopeName(box));
      return;
    }
    var all = event.target.closest('[data-post-select-all]');
    if (!all) return;
    var scope = scopeName(all);
    rowBoxes(scope).forEach(function (item) { item.checked = all.checked; });
    syncScope(scope);
  });

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form');
    if (!form) return;
    var submitter = event.submitter || null;
    var inMenu = Boolean(form.closest('.post-admin-menu'));
    if (form.dataset.confirmed === '1') {
      if (inMenu) window.setTimeout(function () { closeMenu(false); }, 0);
      return;
    }
    var title = (submitter && submitter.dataset.confirmTitle) || form.dataset.confirmTitle;
    if (!title) {
      if (inMenu) window.setTimeout(function () { closeMenu(false); }, 0);
      return;
    }
    event.preventDefault();
    if (menuState && menuState.menu) menuState.menu.hidden = true;
    openConfirm(
      title,
      (submitter && submitter.dataset.confirmBody) || form.dataset.confirmBody || '',
      form,
      submitter
    );
  }, true);

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    if (confirmState) {
      event.preventDefault();
      closeConfirm(true);
      return;
    }
    if (menuState) {
      event.preventDefault();
      closeMenu(true);
    }
  });

  document.addEventListener('keydown', function (event) {
    if (!menuState || !menuState.menu || menuState.menu.hidden) return;
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    var items = Array.prototype.slice.call(menuState.menu.querySelectorAll('[role="menuitem"]'));
    if (!items.length) return;
    var index = items.indexOf(document.activeElement);
    event.preventDefault();
    if (event.key === 'ArrowDown') index = index < items.length - 1 ? index + 1 : 0;
    else index = index > 0 ? index - 1 : items.length - 1;
    items[index].focus({ preventScroll: true });
  });

  function enhanceSelects() {
    if (window.AdminSelect && window.AdminSelect.enhance) window.AdminSelect.enhance(main);
    ['desktop', 'mobile'].forEach(syncScope);
  }

  if ('MutationObserver' in window) {
    new MutationObserver(function () {
      if (queued) return;
      queued = true;
      window.requestAnimationFrame(function () {
        queued = false;
        enhanceSelects();
      });
    }).observe(main, { childList: true, subtree: true });
  }

  window.addEventListener('popstate', function () {
    window.location.reload();
  });

  enhanceSelects();
})();

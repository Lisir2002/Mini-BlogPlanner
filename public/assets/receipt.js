(() => {
  'use strict';

  const receipt = document.querySelector('[data-post-receipt]');
  if (!receipt) return;

  const tab = receipt.querySelector('[data-receipt-tab]');
  const panel = receipt.querySelector('[data-receipt-panel]');
  if (!(tab instanceof HTMLButtonElement) || !(panel instanceof HTMLElement)) return;

  document.documentElement.classList.add('receipt-script');

  const homeParent = receipt.parentElement;
  const homeNext = receipt.nextSibling;

  const mobile = window.matchMedia('(max-width: 700px)');
  const storageKey = 'cream-receipt-y';
  const minY = 92;
  let ratio = 0.62;
  let open = false;
  let dragging = false;
  let moved = false;
  let startPointerY = 0;
  let startY = 0;
  let currentY = 0;
  let suppressClick = false;

  try {
    const saved = Number.parseFloat(window.localStorage.getItem(storageKey) || '');
    if (Number.isFinite(saved) && saved >= 0.18 && saved <= 0.82) {
      ratio = saved;
    }
  } catch (error) {
    // 本地位置不可用时使用默认位置。
  }

  const clampY = (value) => {
    const maxY = Math.max(minY, window.innerHeight - minY);

    return Math.min(Math.max(value, minY), maxY);
  };

  const applyY = (value) => {
    currentY = clampY(value);
    ratio = currentY / Math.max(1, window.innerHeight);
    receipt.style.setProperty('--receipt-y', currentY + 'px');
  };

  const saveY = () => {
    try {
      window.localStorage.setItem(storageKey, String(ratio));
    } catch (error) {
      // 位置保存失败不影响交互。
    }
  };

  const isFloating = () => receipt.getAttribute('data-receipt-floating') === 'true';

  const setOpen = (next, returnFocus) => {
    if (!isFloating()) return;
    open = next;
    if (next) {
      receipt.setAttribute('data-receipt-open', 'true');
    } else {
      receipt.removeAttribute('data-receipt-open');
    }
    tab.setAttribute('aria-expanded', next ? 'true' : 'false');
    tab.setAttribute('aria-label', next ? '收起读者回执' : '打开读者回执');
    if (next) {
      panel.removeAttribute('aria-hidden');
      panel.removeAttribute('inert');
    } else {
      panel.setAttribute('aria-hidden', 'true');
      panel.setAttribute('inert', '');
    }
    if (!next && returnFocus === true) {
      tab.focus();
    }
  };

  const enableFloating = () => {
    applyY(window.innerHeight * ratio);
    tab.hidden = false;
    receipt.setAttribute('data-receipt-floating', 'true');
    if (receipt.parentElement !== document.body) {
      document.body.appendChild(receipt);
    }
    setOpen(window.location.hash === '#echo');
  };

  const disableFloating = () => {
    receipt.removeAttribute('data-receipt-floating');
    receipt.removeAttribute('data-receipt-open');
    tab.hidden = true;
    tab.setAttribute('aria-expanded', 'false');
    tab.setAttribute('aria-label', '打开读者回执');
    panel.removeAttribute('aria-hidden');
    panel.removeAttribute('inert');
    open = false;
    dragging = false;
    if (homeParent instanceof HTMLElement && receipt.parentElement !== homeParent) {
      homeParent.insertBefore(receipt, homeNext);
    }
  };

  const updateMode = () => {
    if (mobile.matches) {
      enableFloating();
    } else {
      disableFloating();
    }
  };

  tab.addEventListener('click', (event) => {
    if (suppressClick) {
      suppressClick = false;
      event.preventDefault();
      return;
    }
    setOpen(!open);
  });

  tab.addEventListener('pointerdown', (event) => {
    if (!isFloating() || event.button !== 0) return;
    dragging = true;
    moved = false;
    startPointerY = event.clientY;
    startY = currentY;
    if (tab.setPointerCapture) {
      tab.setPointerCapture(event.pointerId);
    }
  });

  tab.addEventListener('pointermove', (event) => {
    if (!dragging) return;
    const delta = event.clientY - startPointerY;
    if (!moved && Math.abs(delta) < 5) return;
    moved = true;
    if (event.cancelable) event.preventDefault();
    applyY(startY + delta);
  });

  const finishDrag = () => {
    if (!dragging) return;
    dragging = false;
    if (!moved) return;
    saveY();
    suppressClick = true;
    window.setTimeout(() => {
      suppressClick = false;
    }, 0);
  };

  tab.addEventListener('pointerup', finishDrag);
  tab.addEventListener('pointercancel', finishDrag);

  document.addEventListener('pointerdown', (event) => {
    if (!open) return;
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (receipt.contains(target)) return;
    setOpen(false);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || !open) return;
    setOpen(false, true);
  });

  window.addEventListener('resize', () => {
    if (!isFloating()) return;
    applyY(window.innerHeight * ratio);
  });

  if (typeof mobile.addEventListener === 'function') {
    mobile.addEventListener('change', updateMode);
  } else {
    mobile.addListener(updateMode);
  }

  updateMode();
})();
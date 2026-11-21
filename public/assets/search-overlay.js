(() => {
  'use strict';

  const overlay = document.getElementById('search-overlay');
  if (!(overlay instanceof HTMLElement)) return;

  const panel = overlay.querySelector('.search-overlay-panel');
  const form = overlay.querySelector('[data-search-form]');
  const input = overlay.querySelector('[data-search-input]');
  const clearButton = overlay.querySelector('[data-search-clear]');
  const statusText = overlay.querySelector('[data-search-status-text]');
  const start = overlay.querySelector('[data-search-start]');
  const results = overlay.querySelector('[data-search-results]');
  const empty = overlay.querySelector('[data-search-empty]');
  const error = overlay.querySelector('[data-search-error]');
  const recent = overlay.querySelector('[data-search-recent]');
  const recentList = overlay.querySelector('[data-search-recent-list]');
  const recentClear = overlay.querySelector('[data-search-recent-clear]');
  const triggers = document.querySelectorAll('[data-search-open]');
  const mobileNav = document.querySelector('.nav-mobile');
  const historyKey = 'cream-search-history-v1';
  const maxHistory = 5;
  const cache = new Map();
  const maxCache = 12;

  if (!(panel instanceof HTMLElement) || !(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement)) {
    return;
  }

  let closeTimer = 0;
  let searchTimer = 0;
  let sequence = 0;
  let controller = null;
  let lastFocus = null;
  let activeIndex = -1;
  let currentResults = [];
  let currentQuery = '';
  let bodyOverflow = '';
  let bodyPadding = '';
  let scrollLocked = false;

  const normalize = (value) => String(value || '').trim().replace(/\s+/g, ' ').slice(0, 60);
  const isOpen = () => !overlay.hidden;

  const setPhase = (phase) => {
    overlay.dataset.searchPhase = phase;
  };

  const setStatus = (text) => {
    if (statusText) statusText.textContent = text;
  };

  const show = (element) => {
    if (element instanceof HTMLElement) element.hidden = false;
  };

  const hide = (element) => {
    if (element instanceof HTMLElement) element.hidden = true;
  };

  const showStart = () => {
    show(start);
    hide(results);
    hide(empty);
    hide(error);
    setPhase('idle');
    activeIndex = -1;
  };

  const hideContent = () => {
    hide(start);
    hide(results);
    hide(empty);
    hide(error);
  };

  const lockBody = () => {
    if (scrollLocked) return;
    bodyOverflow = document.body.style.overflow;
    bodyPadding = document.body.style.paddingRight;
    const width = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
    document.body.style.overflow = 'hidden';
    if (width > 0) document.body.style.paddingRight = width + 'px';
    document.body.classList.add('search-open');
    scrollLocked = true;
  };

  const unlockBody = () => {
    if (!scrollLocked) return;
    document.body.style.overflow = bodyOverflow;
    document.body.style.paddingRight = bodyPadding;
    document.body.classList.remove('search-open');
    scrollLocked = false;
  };

  const close = (restoreFocus = true) => {
    if (!isOpen()) return;
    window.clearTimeout(closeTimer);
    window.clearTimeout(searchTimer);
    if (controller) {
      controller.abort();
      controller = null;
    }
    sequence += 1;
    overlay.classList.remove('is-open');
    overlay.dataset.searchPhase = 'idle';
    triggers.forEach((trigger) => trigger.setAttribute('aria-expanded', 'false'));
    unlockBody();
    closeTimer = window.setTimeout(() => {
      overlay.hidden = true;
      if (restoreFocus && lastFocus instanceof HTMLElement) lastFocus.focus({ preventScroll: true });
    }, 190);
  };

  const open = (query, focusInput = true) => {
    window.clearTimeout(closeTimer);
    lastFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (mobileNav instanceof HTMLDetailsElement) mobileNav.open = false;
    const value = normalize(query);
    if (value !== '') input.value = value;
    clearButton.hidden = input.value === '';
    overlay.hidden = false;
    lockBody();
    triggers.forEach((trigger) => trigger.setAttribute('aria-expanded', 'true'));
    requestAnimationFrame(() => overlay.classList.add('is-open'));
    renderRecent();
    if (value === '') {
      setStatus('输入两个字开始搜索');
      showStart();
    } else {
      runSearch(value, true);
    }
    if (focusInput) {
      window.setTimeout(() => {
        input.focus({ preventScroll: true });
        const end = input.value.length;
        try {
          input.setSelectionRange(end, end);
        } catch (error) {
          // 某些移动浏览器不支持 setSelectionRange，聚焦即可。
        }
      }, 70);
    }
  };

  const readHistory = () => {
    try {
      const raw = window.localStorage.getItem(historyKey);
      const parsed = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(parsed)) return [];
      return parsed.map((item) => normalize(item)).filter((item) => item.length >= 2).slice(0, maxHistory);
    } catch (error) {
      return [];
    }
  };

  const writeHistory = (items) => {
    try {
      window.localStorage.setItem(historyKey, JSON.stringify(items));
    } catch (error) {
      // 隐私模式可能禁用本地存储，搜索本身不受影响。
    }
  };

  const remember = (query) => {
    const value = normalize(query);
    if (value.length < 2) return;
    const items = readHistory().filter((item) => item !== value);
    items.unshift(value);
    writeHistory(items.slice(0, maxHistory));
    renderRecent();
  };

  const renderRecent = () => {
    if (!(recent instanceof HTMLElement) || !(recentList instanceof HTMLElement)) return;
    const items = readHistory();
    recentList.textContent = '';
    if (items.length === 0) {
      recent.hidden = true;
      return;
    }
    items.forEach((item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = item;
      button.addEventListener('click', () => {
        input.value = item;
        clearButton.hidden = false;
        runSearch(item, true);
        input.focus({ preventScroll: true });
      });
      recentList.appendChild(button);
    });
    recent.hidden = false;
  };

  const highlight = (parent, text, query) => {
    const value = String(text || '');
    const needle = query.toLocaleLowerCase('zh-CN');
    if (needle === '') {
      parent.textContent = value;
      return;
    }
    const lower = value.toLocaleLowerCase('zh-CN');
    let cursor = 0;
    let index = lower.indexOf(needle, cursor);
    if (index < 0) {
      parent.textContent = value;
      return;
    }
    while (index >= 0) {
      if (index > cursor) parent.appendChild(document.createTextNode(value.slice(cursor, index)));
      const mark = document.createElement('mark');
      mark.textContent = value.slice(index, index + needle.length);
      parent.appendChild(mark);
      cursor = index + needle.length;
      index = lower.indexOf(needle, cursor);
    }
    if (cursor < value.length) parent.appendChild(document.createTextNode(value.slice(cursor)));
  };

  const setActive = (index) => {
    const links = Array.from(results.querySelectorAll('.search-overlay-result'));
    if (links.length === 0) {
      activeIndex = -1;
      return;
    }
    if (index < 0) index = links.length - 1;
    if (index >= links.length) index = 0;
    activeIndex = index;
    links.forEach((link, position) => {
      link.classList.toggle('is-active', position === index);
      if (position === index) link.scrollIntoView({ block: 'nearest' });
    });
  };

  const renderResults = (query, items) => {
    if (!(results instanceof HTMLElement)) return;
    currentResults = Array.isArray(items) ? items : [];
    currentQuery = query;
    activeIndex = -1;
    results.textContent = '';
    hide(start);
    hide(empty);
    hide(error);
    show(results);
    setPhase('results');

    const head = document.createElement('div');
    head.className = 'search-overlay-results-head';
    const count = document.createElement('strong');
    count.textContent = String(currentResults.length);
    const label = document.createElement('span');
    label.append('找到 ', count, ' 篇');
    const queryLabel = document.createElement('em');
    queryLabel.textContent = '「' + query + '」';
    head.append(label, queryLabel);
    results.appendChild(head);

    if (currentResults.length === 0) {
      hide(results);
      show(empty);
      setPhase('empty');
      setStatus('没有找到与「' + query + '」有关的文章');
      return;
    }

    currentResults.forEach((item, index) => {
      const post = item && typeof item === 'object' ? item : {};
      const link = document.createElement('a');
      link.className = 'search-overlay-result';
      link.href = typeof post.url === 'string' ? post.url : '#';
      link.dataset.resultIndex = String(index);
      link.addEventListener('mouseenter', () => setActive(index));
      link.addEventListener('click', () => remember(query));

      const number = document.createElement('span');
      number.className = 'search-overlay-result-index';
      number.textContent = String(index + 1).padStart(2, '0');

      const copy = document.createElement('div');
      copy.className = 'search-overlay-result-copy';
      const title = document.createElement('strong');
      highlight(title, post.title || '未命名文章', query);
      const excerpt = document.createElement('p');
      highlight(excerpt, post.excerpt || '这篇文章没有摘要。', query);
      copy.append(title, excerpt);

      const arrow = document.createElement('span');
      arrow.className = 'search-overlay-result-arrow';
      arrow.setAttribute('aria-hidden', 'true');
      arrow.textContent = '→';

      link.append(number, copy, arrow);
      results.appendChild(link);
    });

    setStatus('找到 ' + currentResults.length + ' 篇与「' + query + '」有关的文章');
    remember(query);
  };

  const renderError = () => {
    hide(start);
    hide(results);
    hide(empty);
    show(error);
    setPhase('error');
    setStatus('搜索暂时不可用，请稍后再试');
  };

  const renderCache = (query, items) => {
    renderResults(query, items);
    setStatus('找到 ' + items.length + ' 篇与「' + query + '」有关的文章');
  };

  const rememberCache = (query, items) => {
    if (cache.size >= maxCache) {
      const oldest = cache.keys().next().value;
      if (oldest) cache.delete(oldest);
    }
    cache.set(query.toLocaleLowerCase('zh-CN'), items);
  };

  const runSearch = (rawQuery, force = false) => {
    const query = normalize(rawQuery);
    window.clearTimeout(searchTimer);
    if (query !== input.value) input.value = query;
    clearButton.hidden = query === '';
    if (query === '') {
      setStatus('输入两个字开始搜索');
      showStart();
      return;
    }
    if (query.length < 2) {
      setStatus('再输入一个字就可以搜索');
      showStart();
      return;
    }

    const key = query.toLocaleLowerCase('zh-CN');
    if (!force && cache.has(key)) {
      renderCache(query, cache.get(key));
      return;
    }

    if (controller) controller.abort();
    controller = new AbortController();
    const current = ++sequence;
    hideContent();
    setPhase('loading');
    setStatus('正在翻找与「' + query + '」有关的文章…');

    fetch('/api/v1/search?q=' + encodeURIComponent(query), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      signal: controller.signal,
    })
      .then((response) => {
        if (!response.ok) throw new Error('Search request failed: ' + response.status);
        return response.json();
      })
      .then((payload) => {
        if (current !== sequence) return;
        const items = payload && Array.isArray(payload.data) ? payload.data : [];
        rememberCache(query, items);
        renderResults(query, items);
      })
      .catch((exception) => {
        if (exception && exception.name === 'AbortError') return;
        if (current !== sequence) return;
        renderError();
      })
      .finally(() => {
        if (current === sequence) controller = null;
      });
  };

  triggers.forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
      event.preventDefault();
      if (isOpen()) close();
      else open('');
    });
  });

  overlay.querySelectorAll('[data-search-close]').forEach((button) => {
    button.addEventListener('click', () => close());
  });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const query = normalize(input.value);
    clearButton.hidden = query === '';
    if (query === '') {
      setStatus('先写一个词，再翻手账');
      input.focus();
      return;
    }
    if (query.length < 2) {
      setStatus('再输入一个字就可以搜索');
      input.focus();
      return;
    }
    runSearch(query, true);
  });

  input.addEventListener('input', () => {
    const query = normalize(input.value);
    clearButton.hidden = query === '';
    window.clearTimeout(searchTimer);
    if (query === '') {
      setStatus('输入两个字开始搜索');
      showStart();
      return;
    }
    if (query.length < 2) {
      setStatus('再输入一个字就可以搜索');
      showStart();
      return;
    }
    setPhase('loading');
    setStatus('正在听你写完这个词…');
    searchTimer = window.setTimeout(() => runSearch(query), 280);
  });

  clearButton.addEventListener('click', () => {
    input.value = '';
    clearButton.hidden = true;
    setStatus('输入两个字开始搜索');
    input.focus();
    showStart();
    renderRecent();
  });

  if (recentClear) {
    recentClear.addEventListener('click', () => {
      writeHistory([]);
      renderRecent();
    });
  }

  overlay.querySelectorAll('[data-search-suggest]').forEach((button) => {
    button.addEventListener('click', () => {
      const query = normalize(button.dataset.searchSuggest || '');
      if (query === '') return;
      input.value = query;
      clearButton.hidden = false;
      input.focus();
      runSearch(query, true);
    });
  });

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const suggest = target.closest('[data-search-suggest]');
    if (suggest instanceof HTMLElement && overlay.contains(suggest)) {
      event.preventDefault();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!isOpen()) {
      const target = event.target;
      const editable = target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
      if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        open('');
        return;
      }
      if (event.key === '/' && !editable && !event.metaKey && !event.ctrlKey && !event.altKey) {
        event.preventDefault();
        open('');
      }
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }

    if (event.key === 'Tab') {
      const focusable = Array.from(panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'))
        .filter((element) => element instanceof HTMLElement && !element.hidden && element.offsetParent !== null);
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
      return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (results.hidden) return;
      if (document.activeElement !== input) return;
      event.preventDefault();
      setActive(event.key === 'ArrowDown' ? activeIndex + 1 : activeIndex - 1);
      return;
    }

    if (event.key === 'Enter' && activeIndex >= 0 && !results.hidden) {
      const links = Array.from(results.querySelectorAll('.search-overlay-result'));
      const link = links[activeIndex];
      if (link instanceof HTMLAnchorElement) {
        event.preventDefault();
        window.location.assign(link.href);
      }
    }
  });

  const param = new URLSearchParams(window.location.search);
  const initial = normalize(param.get('search') || param.get('q') || '');
  if (initial !== '') {
    try {
      const url = new URL(window.location.href);
      url.searchParams.delete('search');
      url.searchParams.delete('q');
      window.history.replaceState({}, '', url);
    } catch (error) {
      // 历史地址不可写时仍打开搜索，不影响结果。
    }
    window.setTimeout(() => open(initial, false), 40);
  }
})();

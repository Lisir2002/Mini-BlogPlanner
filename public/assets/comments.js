(() => {
  'use strict';

  const commentList = document.querySelector('[data-comment-list]');
  const commentMore = document.querySelector('[data-comment-more]');
  if (commentList && commentMore) {
    const threads = Array.from(commentList.querySelectorAll('[data-comment-thread]'));
    const moreButton = commentMore.querySelector('[data-comment-more-button]');
    const moreCount = commentMore.querySelector('[data-comment-more-count]');
    const pageSize = 3;
    let visible = pageSize;

    if (threads.length > pageSize && moreButton && moreCount) {
      const hash = location.hash.replace(/^#/, '');
      if (hash) {
        try {
          const target = document.getElementById(decodeURIComponent(hash));
          const thread = target ? target.closest('[data-comment-thread]') : null;
          const index = thread ? threads.indexOf(thread) : -1;
          if (index >= pageSize) visible = index + 1;
        } catch (error) {
          // 地址中的锚点无法解析时，仍按默认三条展示。
        }
      }

      const render = () => {
        threads.forEach((thread, index) => {
          thread.hidden = index >= visible;
        });
        const left = threads.length - visible;
        if (left <= 0) {
          commentMore.hidden = true;
          return;
        }
        commentMore.hidden = false;
        moreCount.textContent = String(left);
        moreButton.setAttribute('aria-label', '再看 ' + left + ' 条留言');
      };

      moreButton.addEventListener('click', () => {
        const start = visible;
        visible = Math.min(visible + pageSize, threads.length);
        threads.slice(start, visible).forEach((thread) => {
          thread.hidden = false;
          thread.classList.add('is-revealing');
          thread.addEventListener('animationend', () => thread.classList.remove('is-revealing'), { once: true });
        });
        render();
      });

      render();
    }
  }

  const composer = document.getElementById('comment-form');
  if (!composer) return;

  const form = composer.querySelector('.comment-form');
  const parent = form ? form.querySelector('[data-comment-parent]') : null;
  const context = form ? form.querySelector('[data-comment-reply-context]') : null;
  const replyName = context ? context.querySelector('[data-comment-reply-name]') : null;
  const summaryNote = composer.querySelector('[data-comment-summary-note]');
  const body = form ? form.querySelector('textarea[name="body"]') : null;
  if (!form || !parent || !context || !replyName || !summaryNote) return;

  const emojiTrigger = form.querySelector('[data-comment-emoji-trigger]');
  const emojiPanel = form.querySelector('[data-comment-emoji-panel]');
  const emojiClose = form.querySelector('[data-comment-emoji-close]');
  const emojiStatus = form.querySelector('[data-comment-emoji-status]');
  const emojiRecent = form.querySelector('[data-comment-emoji-recent]');
  const emojiRecentList = form.querySelector('[data-comment-emoji-recent-list]');
  if (emojiTrigger && emojiPanel && emojiClose && emojiStatus && emojiRecent && emojiRecentList && body) {
    const tabs = Array.from(emojiPanel.querySelectorAll('[data-comment-emoji-tab]'));
    const groups = Array.from(emojiPanel.querySelectorAll('[data-comment-emoji-group]'));
    const recentKey = 'cream.comment.emoji.recent.v1';
    const recentLimit = 8;
    let insertRange = { start: body.value.length, end: body.value.length };

    const readRecent = () => {
      try {
        const parsed = JSON.parse(window.localStorage.getItem(recentKey) || '[]');
        if (!Array.isArray(parsed)) return [];
        return parsed.filter((item) => item && typeof item.v === 'string' && typeof item.n === 'string'
          && typeof item.g === 'string' && (item.k === 'emoji' || item.k === 'text')
          && item.v.length <= 40 && item.n.length <= 20 && item.g.length <= 8).slice(0, recentLimit);
      } catch (error) {
        return [];
      }
    };

    const writeRecent = (items) => {
      try {
        window.localStorage.setItem(recentKey, JSON.stringify(items));
      } catch (error) {
        // 隐私模式或存储已满时，最近使用只在当前页面内保留。
      }
    };

    let recent = readRecent();

    const createRecentItem = (item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'comment-emoji-item' + (item.k === 'text' ? ' is-text' : '');
      button.setAttribute('data-comment-emoji', item.v);
      button.setAttribute('data-comment-emoji-name', item.n);
      button.setAttribute('data-comment-emoji-group', item.g);
      button.setAttribute('data-comment-emoji-kind', item.k);
      button.setAttribute('aria-label', '插入' + item.g + '：' + item.n);
      button.title = item.n;
      button.textContent = item.v;
      return button;
    };

    const renderRecent = () => {
      emojiRecentList.replaceChildren();
      recent.forEach((item) => emojiRecentList.appendChild(createRecentItem(item)));
      emojiRecent.hidden = recent.length === 0;
    };

    const remember = (item) => {
      recent = [item, ...recent.filter((entry) => !(entry.v === item.v && entry.k === item.k))].slice(0, recentLimit);
      writeRecent(recent);
      renderRecent();
    };

    const syncInsertRange = () => {
      insertRange = {
        start: body.selectionStart ?? body.value.length,
        end: body.selectionEnd ?? body.value.length,
      };
    };

    const activateTab = (key, focusTab) => {
      tabs.forEach((tab) => {
        const active = tab.getAttribute('data-comment-emoji-tab') === key;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
        if (active && focusTab) tab.focus();
      });
      groups.forEach((group) => {
        group.hidden = group.getAttribute('data-comment-emoji-group') !== key;
      });
    };

    const closePanel = (focusBody) => {
      emojiPanel.hidden = true;
      emojiTrigger.setAttribute('aria-expanded', 'false');
      if (focusBody) body.focus({ preventScroll: true });
    };

    const openPanel = () => {
      composer.open = true;
      syncInsertRange();
      renderRecent();
      emojiPanel.hidden = false;
      emojiTrigger.setAttribute('aria-expanded', 'true');
      const active = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0];
      if (active) activateTab(active.getAttribute('data-comment-emoji-tab') || '', false);
    };

    emojiTrigger.addEventListener('click', () => {
      if (emojiPanel.hidden) {
        openPanel();
      } else {
        closePanel(false);
      }
    });

    emojiClose.addEventListener('click', () => closePanel(true));

    emojiPanel.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closePanel(true);
      }
    });

    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => activateTab(tab.getAttribute('data-comment-emoji-tab') || '', false));
      tab.addEventListener('keydown', (event) => {
        let next = -1;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        if (next < 0) return;
        event.preventDefault();
        activateTab(tabs[next].getAttribute('data-comment-emoji-tab') || '', true);
      });
    });

    emojiPanel.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target.closest('[data-comment-emoji]') : null;
      if (!target || !(target instanceof HTMLButtonElement) || !emojiPanel.contains(target)) return;
      event.preventDefault();
      const value = target.getAttribute('data-comment-emoji') || '';
      const name = target.getAttribute('data-comment-emoji-name') || '';
      const group = target.getAttribute('data-comment-emoji-group') || '';
      const kind = target.getAttribute('data-comment-emoji-kind') === 'text' ? 'text' : 'emoji';
      const maxLength = Number(body.maxLength) || 1000;
      const start = Math.max(0, Math.min(insertRange.start, body.value.length));
      const end = Math.max(start, Math.min(insertRange.end, body.value.length));
      if (body.value.length - (end - start) + value.length > maxLength) {
        emojiStatus.textContent = '快到 1000 字上限了。';
        return;
      }
      body.setRangeText(value, start, end, 'end');
      body.dispatchEvent(new Event('input', { bubbles: true }));
      insertRange = { start: body.selectionStart ?? start + value.length, end: body.selectionEnd ?? start + value.length };
      remember({ v: value, n: name, g: group, k: kind });
      emojiStatus.textContent = '已插入：' + name;
      body.focus({ preventScroll: true });
    });

    ['click', 'keyup', 'select', 'input', 'focus'].forEach((eventName) => {
      body.addEventListener(eventName, syncInsertRange);
    });

    document.addEventListener('click', (event) => {
      if (emojiPanel.hidden) return;
      const target = event.target instanceof Element ? event.target : null;
      if (target && (emojiPanel.contains(target) || emojiTrigger.contains(target))) return;
      closePanel(false);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !emojiPanel.hidden && document.activeElement === emojiTrigger) {
        event.preventDefault();
        closePanel(false);
      }
    });

    renderRecent();
  }

  const defaultNote = summaryNote.getAttribute('data-default-note') || summaryNote.textContent || '';
  let scrollTimer = 0;
  let focusTimer = 0;

  const syncUrl = (id) => {
    const url = new URL(location.href);
    if (id) {
      url.searchParams.set('reply', id);
    } else {
      url.searchParams.delete('reply');
    }
    url.hash = 'comment-form';
    try {
      history.replaceState(history.state, '', url);
    } catch (error) {
      // 地址栏同步失败时，页面内回复仍可继续。
    }
  };

  const openReply = (id, author) => {
    composer.open = true;
    parent.value = id;
    context.hidden = false;
    replyName.textContent = author;
    summaryNote.textContent = '回复 ' + author;
    syncUrl(id);

    window.clearTimeout(scrollTimer);
    window.clearTimeout(focusTimer);
    scrollTimer = window.setTimeout(() => {
      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      composer.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
    }, 40);
    if (body) {
      focusTimer = window.setTimeout(() => body.focus({ preventScroll: true }), 220);
    }
  };

  const clearReply = () => {
    context.hidden = true;
    parent.value = '';
    replyName.textContent = '';
    summaryNote.textContent = defaultNote;
    syncUrl(null);
  };

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const reply = target.closest('[data-comment-reply]');
    if (reply) {
      const id = reply.getAttribute('data-comment-reply') || '';
      if (!/^[1-9][0-9]*$/.test(id)) return;
      event.preventDefault();
      openReply(id, reply.getAttribute('data-comment-author') || '');
      return;
    }

    const cancel = target.closest('[data-comment-cancel]');
    if (cancel && !context.hidden) {
      event.preventDefault();
      clearReply();
    }
  });
})();

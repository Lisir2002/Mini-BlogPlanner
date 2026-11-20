(() => {
  'use strict';

  const composer = document.getElementById('comment-form');
  if (!composer) return;

  const form = composer.querySelector('.comment-form');
  const parent = form ? form.querySelector('[data-comment-parent]') : null;
  const context = form ? form.querySelector('[data-comment-reply-context]') : null;
  const replyName = context ? context.querySelector('[data-comment-reply-name]') : null;
  const summaryNote = composer.querySelector('[data-comment-summary-note]');
  const body = form ? form.querySelector('textarea[name="body"]') : null;
  if (!form || !parent || !context || !replyName || !summaryNote) return;

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

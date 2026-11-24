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
  const emojiDragHandle = form.querySelector('[data-comment-emoji-drag-handle]');
  const emojiResizeHandle = form.querySelector('[data-comment-emoji-resize-handle]');
  const emojiStatus = form.querySelector('[data-comment-emoji-status]');
  const emojiRecent = form.querySelector('[data-comment-emoji-recent]');
  const emojiRecentList = form.querySelector('[data-comment-emoji-recent-list]');
  if (emojiTrigger && emojiPanel && emojiClose && emojiDragHandle && emojiResizeHandle && emojiStatus && emojiRecent && emojiRecentList && body) {
    const sectionTabs = Array.from(emojiPanel.querySelectorAll('[data-comment-expression-section][role="tab"]'));
    const sectionPanels = Array.from(emojiPanel.querySelectorAll('[data-comment-expression-panel]'));
    const groupTabs = Array.from(emojiPanel.querySelectorAll('[data-comment-emoji-tab]'));
    const groups = Array.from(emojiPanel.querySelectorAll('.comment-emoji-group'));
    const emojiNotice = emojiPanel.querySelector('[data-comment-emoji-notice]');
    if (emojiPanel.parentElement !== document.body) document.body.appendChild(emojiPanel);
    const recentKey = 'cream.comment.expression.recent.v1';
    const legacyRecentKey = 'cream.comment.emoji.recent.v1';
    const recentLimit = 8;
    const stickerPattern = /\[贴图:[^\[\]\r\n]{1,12}\]/gu;
    const markerPath = (slug, extension) => '/assets/stickers/' + slug + '.' + extension;
    const panelMargin = 10;
    const panelMaxHeight = 620;
    const panelResizeMaxHeight = 760;
    const panelWidthLimit = 380;
    const panelResizeWidthLimit = 560;
    const panelMinWidth = 260;
    const panelMinHeight = 220;
    const panelStickerMinHeight = 300;
    const panelSizeKey = 'cream.comment.expression.size.v1';
    let insertRange = { start: body.value.length, end: body.value.length };
    let activeSection = '';
    let noticeTimer = 0;
    let panelClampFrame = 0;
    let panelDrag = null;
    let panelResize = null;
    let panelPinned = false;
    let panelSized = false;
    let panelAspect = 0;
    const lastGroup = new Map();

    const readStoredRecent = (key) => {
      try {
        const parsed = JSON.parse(window.localStorage.getItem(key) || '[]');
        if (!Array.isArray(parsed)) return [];
        return parsed.filter((item) => {
          if (!item || typeof item.v !== 'string' || typeof item.n !== 'string'
            || typeof item.g !== 'string' || !['emoji', 'text', 'sticker'].includes(item.k)
            || item.v.length > 40 || item.n.length > 20 || item.g.length > 8) return false;
          return item.k !== 'sticker' || (typeof item.s === 'string' && /^[a-z0-9-]+$/.test(item.s));
        }).slice(0, recentLimit);
      } catch (error) {
        return [];
      }
    };

    const readRecent = () => {
      let items = readStoredRecent(recentKey);
      if (items.length === 0) {
        items = readStoredRecent(legacyRecentKey).map((item) => ({ ...item, s: item.s || '' }));
        if (items.length > 0) writeRecent(items);
      }
      return items;
    };

    const writeRecent = (items) => {
      try {
        window.localStorage.setItem(recentKey, JSON.stringify(items));
      } catch (error) {
        // 隐私模式或存储已满时，最近使用只在当前页面内保留。
      }
    };

    const readPanelSize = () => {
      try {
        const parsed = JSON.parse(window.localStorage.getItem(panelSizeKey) || 'null');
        if (!parsed || typeof parsed.w !== 'number' || typeof parsed.h !== 'number'
          || !Number.isFinite(parsed.w) || !Number.isFinite(parsed.h)
          || parsed.w < 180 || parsed.h < 150 || parsed.w > 1200 || parsed.h > 1600) return null;
        return { width: parsed.w, height: parsed.h };
      } catch (error) {
        return null;
      }
    };

    const writePanelSize = (width, height) => {
      try {
        window.localStorage.setItem(panelSizeKey, JSON.stringify({
          w: Math.round(width * 10) / 10,
          h: Math.round(height * 10) / 10,
        }));
      } catch (error) {
        // 隐私模式或存储已满时，本次打开仍保留用户调好的大小。
      }
    };

    const clearPanelSize = () => {
      try {
        window.localStorage.removeItem(panelSizeKey);
      } catch (error) {
        // 无法写入存储时不需要额外处理。
      }
    };

    let recent = [];

    const buildStickerPicture = (slug, size) => {
      const picture = document.createElement('picture');
      const source = document.createElement('source');
      source.media = '(prefers-reduced-motion: reduce)';
      source.srcset = markerPath(slug, 'png');
      const image = document.createElement('img');
      image.src = markerPath(slug, 'gif');
      image.alt = '';
      image.width = size;
      image.height = size;
      image.loading = 'lazy';
      image.decoding = 'async';
      picture.append(source, image);
      return picture;
    };

    const createRecentItem = (item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'comment-emoji-item' + (item.k === 'text' ? ' is-text' : '') + (item.k === 'sticker' ? ' is-sticker' : '');
      button.setAttribute('data-comment-emoji', item.v);
      button.setAttribute('data-comment-emoji-name', item.n);
      button.setAttribute('data-comment-emoji-group', item.g);
      button.setAttribute('data-comment-emoji-kind', item.k);
      button.setAttribute('aria-label', '插入' + (item.k === 'sticker' ? '贴图' : '表情') + '：' + item.n);
      button.title = item.n;
      if (item.k === 'sticker') {
        button.setAttribute('data-comment-emoji-sticker', item.s);
        const thumb = document.createElement('span');
        thumb.className = 'comment-emoji-thumb';
        thumb.appendChild(buildStickerPicture(item.s, 40));
        button.appendChild(thumb);
      } else {
        button.textContent = item.v;
      }
      return button;
    };

    const renderRecent = () => {
      emojiRecentList.replaceChildren();
      recent.forEach((item) => emojiRecentList.appendChild(createRecentItem(item)));
      emojiRecent.hidden = recent.length === 0;
      if (!emojiPanel.hidden) queuePanelClamp();
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

    const hideNotice = () => {
      window.clearTimeout(noticeTimer);
      if (emojiNotice) {
        emojiNotice.hidden = true;
        emojiNotice.textContent = '';
      }
    };

    const showNotice = (message) => {
      hideNotice();
      if (!emojiNotice) return;
      emojiNotice.textContent = message;
      emojiNotice.hidden = false;
      noticeTimer = window.setTimeout(() => {
        emojiNotice.hidden = true;
        emojiNotice.textContent = '';
      }, 4200);
    };

    const sectionOf = (element) => element.getAttribute('data-comment-emoji-section') || '';

    const panelViewport = () => {
      const view = window.visualViewport;
      return {
        left: view ? view.offsetLeft : 0,
        top: view ? view.offsetTop : 0,
        width: view ? view.width : window.innerWidth,
        height: view ? view.height : window.innerHeight,
      };
    };

    const panelWidthCap = (view) => Math.max(120, Math.min(panelResizeWidthLimit, view.width - panelMargin * 2));
    const panelHeightCap = (view) => Math.max(120, Math.min(panelResizeMaxHeight, view.height - panelMargin * 2));
    const panelMinHeightForSection = (sectionKey = activeSection) => sectionKey === 'sticker' ? panelStickerMinHeight : panelMinHeight;

    const syncPanelMetrics = () => {
      const view = panelViewport();
      if (panelSized) {
        emojiPanel.style.maxWidth = Math.round(panelWidthCap(view)) + 'px';
        emojiPanel.style.maxHeight = Math.round(panelHeightCap(view)) + 'px';
        return;
      }
      const width = Math.max(240, Math.floor(view.width - panelMargin * 2));
      const height = Math.min(view.height - panelMargin * 2, Math.max(160, Math.floor(view.height * 0.78)));
      emojiPanel.style.maxWidth = Math.min(panelWidthLimit, width) + 'px';
      emojiPanel.style.maxHeight = Math.min(panelMaxHeight, height) + 'px';
    };

    const applyPanelSize = (width, height, keepAspect = true) => {
      const nextWidth = Math.round(width * 10) / 10;
      const nextHeight = Math.round(height * 10) / 10;
      if (!Number.isFinite(nextWidth) || !Number.isFinite(nextHeight) || nextWidth <= 0 || nextHeight <= 0) return;
      if (!keepAspect || !panelAspect) panelAspect = nextWidth / nextHeight;
      panelSized = true;
      emojiPanel.style.width = nextWidth + 'px';
      emojiPanel.style.height = nextHeight + 'px';
    };

    const fitPanelSize = () => {
      if (!panelSized || emojiPanel.hidden) return;
      const rect = emojiPanel.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      const view = panelViewport();
      const aspect = panelAspect > 0 ? panelAspect : rect.width / rect.height;
      const width = Math.min(rect.width, rect.height * aspect, panelWidthCap(view), panelHeightCap(view) * aspect);
      if (!Number.isFinite(width) || width <= 0) return;
      applyPanelSize(width, width / aspect);
    };

    const resetPanelSize = () => {
      panelSized = false;
      panelAspect = 0;
      emojiPanel.style.width = '';
      emojiPanel.style.height = '';
      syncPanelMetrics();
    };

    const restorePanelSize = () => {
      const size = readPanelSize();
      if (!size) return false;
      panelSized = true;
      panelAspect = size.width / size.height;
      emojiPanel.style.width = size.width + 'px';
      emojiPanel.style.height = size.height + 'px';
      syncPanelMetrics();
      fitPanelSize();
      return true;
    };

    const persistPanelSize = () => {
      if (!panelSized || emojiPanel.hidden) return;
      const rect = emojiPanel.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      writePanelSize(rect.width, rect.height);
    };

    const clampPanelBox = (left, top) => {
      const view = panelViewport();
      const width = emojiPanel.offsetWidth;
      const height = emojiPanel.offsetHeight;
      const minLeft = view.left + panelMargin;
      const minTop = view.top + panelMargin;
      const maxLeft = Math.max(minLeft, view.left + view.width - width - panelMargin);
      const maxTop = Math.max(minTop, view.top + view.height - height - panelMargin);
      return {
        left: Math.round(Math.min(Math.max(left, minLeft), maxLeft)),
        top: Math.round(Math.min(Math.max(top, minTop), maxTop)),
      };
    };

    const applyPanelPosition = (left, top) => {
      const next = clampPanelBox(left, top);
      emojiPanel.style.left = next.left + 'px';
      emojiPanel.style.top = next.top + 'px';
      emojiPanel.style.right = 'auto';
      emojiPanel.style.bottom = 'auto';
    };

    const clampPanelPosition = () => {
      if (emojiPanel.hidden) return;
      const rect = emojiPanel.getBoundingClientRect();
      applyPanelPosition(rect.left, rect.top);
    };

    const queuePanelClamp = () => {
      if (emojiPanel.hidden) return;
      window.cancelAnimationFrame(panelClampFrame);
      panelClampFrame = window.requestAnimationFrame(() => {
        if (emojiPanel.hidden) return;
        syncPanelMetrics();
        fitPanelSize();
        clampPanelPosition();
      });
    };

    const placePanel = () => {
      if (emojiPanel.hidden) return;
      syncPanelMetrics();
      fitPanelSize();
      const view = panelViewport();
      const width = emojiPanel.offsetWidth;
      const height = emojiPanel.offsetHeight;
      const triggerRect = emojiTrigger.getBoundingClientRect();
      const gap = 8;
      let left = triggerRect.right - width;
      let top = triggerRect.bottom + gap;
      const fitsBelow = top + height <= view.top + view.height - panelMargin;
      const above = triggerRect.top - gap - height;
      const fitsAbove = above >= view.top + panelMargin;
      const right = triggerRect.right + gap;
      const fitsRight = right + width <= view.left + view.width - panelMargin;

      if (!fitsBelow) {
        if (fitsAbove) {
          top = above;
        } else if (fitsRight) {
          left = right;
          top = triggerRect.top;
        } else {
          top = view.top + panelMargin;
        }
      }

      applyPanelPosition(left, top);
    };

    const clearPanelDrag = () => {
      if (!panelDrag) {
        emojiPanel.classList.remove('is-dragging');
        return;
      }
      const pointerId = panelDrag.pointerId;
      panelDrag = null;
      emojiPanel.classList.remove('is-dragging');
      try {
        if (emojiDragHandle.hasPointerCapture(pointerId)) {
          emojiDragHandle.releasePointerCapture(pointerId);
        }
      } catch (error) {
        // 指针已经离开时，浏览器会自动释放捕获。
      }
    };

    const startPanelDrag = (event) => {
      if (emojiPanel.hidden || event.button !== 0 || !event.isPrimary) return;
      const target = event.target instanceof Element ? event.target : null;
      if (target && target.closest('button, a, input, select, textarea')) return;
      const rect = emojiPanel.getBoundingClientRect();
      panelDrag = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        left: rect.left,
        top: rect.top,
        moved: false,
      };
      try {
        emojiDragHandle.setPointerCapture(event.pointerId);
      } catch (error) {
        // 捕获失败时，仍可在面板内继续拖动。
      }
    };

    const movePanelDrag = (event) => {
      if (!panelDrag || event.pointerId !== panelDrag.pointerId) return;
      const dx = event.clientX - panelDrag.startX;
      const dy = event.clientY - panelDrag.startY;
      if (!panelDrag.moved) {
        if (Math.hypot(dx, dy) < 5) return;
        panelDrag.moved = true;
        panelPinned = true;
        emojiPanel.classList.add('is-dragging');
      }
      if (event.cancelable) event.preventDefault();
      applyPanelPosition(panelDrag.left + dx, panelDrag.top + dy);
    };

    const finishPanelDrag = (event) => {
      if (!panelDrag || event.pointerId !== panelDrag.pointerId) return;
      const moved = panelDrag.moved;
      clearPanelDrag();
      if (moved) clampPanelPosition();
    };

    const clearPanelResize = () => {
      if (!panelResize) {
        emojiPanel.classList.remove('is-resizing');
        return;
      }
      const pointerId = panelResize.pointerId;
      panelResize = null;
      emojiPanel.classList.remove('is-resizing');
      try {
        if (emojiResizeHandle.hasPointerCapture(pointerId)) {
          emojiResizeHandle.releasePointerCapture(pointerId);
        }
      } catch (error) {
        // 指针已经离开时，浏览器会自动释放捕获。
      }
    };

    const startPanelResize = (event) => {
      if (emojiPanel.hidden || event.button !== 0 || !event.isPrimary) return;
      const rect = emojiPanel.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      const view = panelViewport();
      const aspect = rect.width / rect.height;
      const maxWidth = Math.max(rect.width, Math.min(
        panelResizeWidthLimit,
        panelWidthCap(view),
        panelHeightCap(view) * aspect,
        view.left + view.width - panelMargin - rect.left,
      ));
      const minHeight = panelMinHeightForSection(activeSection);
      panelResize = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        width: rect.width,
        height: rect.height,
        aspect,
        minWidth: Math.min(maxWidth, Math.max(panelMinWidth, minHeight * aspect)),
        maxWidth,
        moved: false,
      };
      try {
        emojiResizeHandle.setPointerCapture(event.pointerId);
      } catch (error) {
        // 捕获失败时，仍可在手柄上继续缩放。
      }
    };

    const movePanelResize = (event) => {
      if (!panelResize || event.pointerId !== panelResize.pointerId) return;
      const dx = event.clientX - panelResize.startX;
      const dy = event.clientY - panelResize.startY;
      if (!panelResize.moved) {
        if (Math.hypot(dx, dy) < 4) return;
        panelResize.moved = true;
        emojiPanel.classList.add('is-resizing');
        if (!panelSized) {
          panelSized = true;
          panelAspect = panelResize.aspect;
          emojiPanel.style.width = panelResize.width + 'px';
          emojiPanel.style.height = panelResize.height + 'px';
          syncPanelMetrics();
        }
      }
      if (event.cancelable) event.preventDefault();
      const projected = (dx + dy / panelResize.aspect) / (1 + 1 / (panelResize.aspect * panelResize.aspect));
      const width = Math.min(Math.max(panelResize.width + projected, panelResize.minWidth), panelResize.maxWidth);
      applyPanelSize(width, width / panelResize.aspect);
    };

    const finishPanelResize = (event) => {
      if (!panelResize || event.pointerId !== panelResize.pointerId) return;
      const moved = panelResize.moved;
      clearPanelResize();
      if (moved) {
        clampPanelPosition();
        persistPanelSize();
      }
    };

    const ensurePanelMinSize = () => {
      if (!panelSized || emojiPanel.hidden) return;
      const rect = emojiPanel.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      const view = panelViewport();
      const aspect = panelAspect > 0 ? panelAspect : rect.width / rect.height;
      const minimumHeight = panelMinHeightForSection(activeSection);
      const minimumWidth = Math.max(panelMinWidth, minimumHeight * aspect);
      const width = Math.max(rect.width, Math.min(minimumWidth, panelWidthCap(view)));
      const fittedHeight = width / aspect;
      const height = Math.min(Math.max(fittedHeight, minimumHeight), panelHeightCap(view));
      if (width <= rect.width + 0.5 && height <= rect.height + 0.5) return;
      const keepsAspect = Math.abs(width / height - aspect) < 0.002;
      applyPanelSize(width, height, keepsAspect);
      syncPanelMetrics();
      clampPanelPosition();
    };

    const resetPanelSizeForUser = () => {
      resetPanelSize();
      clearPanelSize();
      if (panelPinned) {
        queuePanelClamp();
      } else {
        placePanel();
      }
    };

    const resizePanelByStep = (delta) => {
      const rect = emojiPanel.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      const view = panelViewport();
      const aspect = rect.width / rect.height;
      const maxWidth = Math.max(rect.width, Math.min(
        panelResizeWidthLimit,
        panelWidthCap(view),
        panelHeightCap(view) * aspect,
        view.left + view.width - panelMargin - rect.left,
      ));
      const minWidth = Math.min(maxWidth, Math.max(panelMinWidth, panelMinHeightForSection(activeSection) * aspect));
      const width = Math.min(Math.max(rect.width + delta, minWidth), maxWidth);
      if (Math.abs(width - rect.width) < 1) return;
      applyPanelSize(width, width / aspect);
      syncPanelMetrics();
      clampPanelPosition();
      persistPanelSize();
    };

    const onPanelResizeKeydown = (event) => {
      if (event.target !== emojiResizeHandle) return;
      if (event.key === 'Home') {
        event.preventDefault();
        resetPanelSizeForUser();
        return;
      }
      const step = event.shiftKey ? 64 : 24;
      let delta = 0;
      if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') delta = -step;
      if (event.key === 'ArrowRight' || event.key === 'ArrowDown') delta = step;
      if (!delta) return;
      event.preventDefault();
      resizePanelByStep(delta);
    };

    const onPanelHandleKeydown = (event) => {
      if (event.target !== emojiDragHandle) return;
      if (event.key === 'Home') {
        event.preventDefault();
        panelPinned = false;
        placePanel();
        return;
      }
      const step = event.shiftKey ? 32 : 12;
      let dx = 0;
      let dy = 0;
      if (event.key === 'ArrowLeft') dx = -step;
      if (event.key === 'ArrowRight') dx = step;
      if (event.key === 'ArrowUp') dy = -step;
      if (event.key === 'ArrowDown') dy = step;
      if (!dx && !dy) return;
      event.preventDefault();
      panelPinned = true;
      const rect = emojiPanel.getBoundingClientRect();
      applyPanelPosition(rect.left + dx, rect.top + dy);
    };

    const activateGroup = (sectionKey, groupKey, focusTab) => {
      const sectionGroups = groupTabs.filter((tab) => sectionOf(tab) === sectionKey);
      const target = sectionGroups.find((tab) => tab.getAttribute('data-comment-emoji-tab') === groupKey) || sectionGroups[0];
      if (!target) return;
      const targetKey = target.getAttribute('data-comment-emoji-tab') || '';
      groupTabs.forEach((tab) => {
        const active = tab === target;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
        if (active && focusTab) tab.focus();
      });
      groups.forEach((group) => {
        const visible = sectionOf(group) === sectionKey && group.getAttribute('data-comment-emoji-group') === targetKey;
        group.hidden = !visible;
      });
      const sectionPanel = sectionPanels.find((panel) => panel.getAttribute('data-comment-expression-panel') === sectionKey);
      const groupList = sectionPanel ? sectionPanel.querySelector('.comment-emoji-groups') : null;
      if (groupList) groupList.scrollTop = 0;
      lastGroup.set(sectionKey, targetKey);
    };

    const activateSection = (sectionKey, focusTab) => {
      const target = sectionTabs.find((tab) => tab.getAttribute('data-comment-expression-section') === sectionKey) || sectionTabs[0];
      if (!target) return;
      const targetKey = target.getAttribute('data-comment-expression-section') || '';
      activeSection = targetKey;
      sectionTabs.forEach((tab) => {
        const active = tab === target;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
      });
      sectionPanels.forEach((panel) => {
        panel.hidden = panel.getAttribute('data-comment-expression-panel') !== targetKey;
      });
      const remembered = lastGroup.get(targetKey) || '';
      activateGroup(targetKey, remembered, false);
      hideNotice();
      ensurePanelMinSize();
      if (panelPinned || panelSized) {
        queuePanelClamp();
      } else {
        placePanel();
      }
      if (focusTab) target.focus();
    };

    const closePanel = (focusBody) => {
      window.cancelAnimationFrame(panelClampFrame);
      clearPanelDrag();
      clearPanelResize();
      emojiPanel.hidden = true;
      emojiTrigger.setAttribute('aria-expanded', 'false');
      hideNotice();
      if (focusBody) body.focus({ preventScroll: true });
    };

    const openPanel = () => {
      composer.open = true;
      syncInsertRange();
      renderRecent();
      panelPinned = false;
      resetPanelSize();
      emojiPanel.hidden = false;
      emojiTrigger.setAttribute('aria-expanded', 'true');
      const selected = sectionTabs.find((tab) => tab.getAttribute('aria-selected') === 'true');
      activateSection(selected ? (selected.getAttribute('data-comment-expression-section') || '') : activeSection, false);
      if (restorePanelSize()) {
        ensurePanelMinSize();
        placePanel();
      }
    };

    emojiTrigger.addEventListener('click', () => {
      if (emojiPanel.hidden) {
        openPanel();
      } else {
        closePanel(false);
      }
    });

    emojiClose.addEventListener('click', () => closePanel(true));

    emojiDragHandle.addEventListener('pointerdown', startPanelDrag);
    emojiDragHandle.addEventListener('pointermove', movePanelDrag);
    emojiDragHandle.addEventListener('pointerup', finishPanelDrag);
    emojiDragHandle.addEventListener('pointercancel', finishPanelDrag);
    emojiDragHandle.addEventListener('lostpointercapture', clearPanelDrag);
    emojiDragHandle.addEventListener('keydown', onPanelHandleKeydown);
    emojiDragHandle.addEventListener('dblclick', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (target && target.closest('button')) return;
      event.preventDefault();
      panelPinned = false;
      placePanel();
    });

    emojiResizeHandle.addEventListener('pointerdown', startPanelResize);
    emojiResizeHandle.addEventListener('pointermove', movePanelResize);
    emojiResizeHandle.addEventListener('pointerup', finishPanelResize);
    emojiResizeHandle.addEventListener('pointercancel', finishPanelResize);
    emojiResizeHandle.addEventListener('lostpointercapture', clearPanelResize);
    emojiResizeHandle.addEventListener('keydown', onPanelResizeKeydown);
    emojiResizeHandle.addEventListener('dblclick', (event) => {
      event.preventDefault();
      resetPanelSizeForUser();
    });

    window.addEventListener('resize', queuePanelClamp, { passive: true });
    window.addEventListener('orientationchange', queuePanelClamp, { passive: true });
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', queuePanelClamp, { passive: true });
      window.visualViewport.addEventListener('scroll', queuePanelClamp, { passive: true });
    }

    sectionTabs.forEach((tab, index) => {
      tab.addEventListener('click', () => activateSection(tab.getAttribute('data-comment-expression-section') || '', false));
      tab.addEventListener('keydown', (event) => {
        let next = -1;
        if (event.key === 'ArrowRight') next = (index + 1) % sectionTabs.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + sectionTabs.length) % sectionTabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = sectionTabs.length - 1;
        if (next < 0) return;
        event.preventDefault();
        activateSection(sectionTabs[next].getAttribute('data-comment-expression-section') || '', true);
      });
    });

    groupTabs.forEach((tab) => {
      tab.addEventListener('click', () => activateGroup(sectionOf(tab), tab.getAttribute('data-comment-emoji-tab') || '', false));
      tab.addEventListener('keydown', (event) => {
        const sectionKey = sectionOf(tab);
        const siblings = groupTabs.filter((item) => sectionOf(item) === sectionKey);
        const index = siblings.indexOf(tab);
        let next = -1;
        if (event.key === 'ArrowRight') next = (index + 1) % siblings.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + siblings.length) % siblings.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = siblings.length - 1;
        if (next < 0) return;
        event.preventDefault();
        activateGroup(sectionKey, siblings[next].getAttribute('data-comment-emoji-tab') || '', true);
      });
    });

    emojiPanel.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target.closest('[data-comment-emoji]') : null;
      if (!target || !(target instanceof HTMLButtonElement) || !emojiPanel.contains(target)) return;
      event.preventDefault();
      const value = target.getAttribute('data-comment-emoji') || '';
      const name = target.getAttribute('data-comment-emoji-name') || '';
      const group = target.getAttribute('data-comment-emoji-group') || '';
      const kind = ['text', 'sticker'].includes(target.getAttribute('data-comment-emoji-kind') || '') ? (target.getAttribute('data-comment-emoji-kind') || '') : 'emoji';
      const stickerSlug = target.getAttribute('data-comment-emoji-sticker') || '';
      const maxLength = Number(body.maxLength) || 1000;
      const start = Math.max(0, Math.min(insertRange.start, body.value.length));
      const end = Math.max(start, Math.min(insertRange.end, body.value.length));
      if (body.value.length - (end - start) + value.length > maxLength) {
        showNotice('快到 1000 字上限了。');
        emojiStatus.textContent = '快到 1000 字上限了。';
        return;
      }
      if (kind === 'sticker') {
        const remaining = body.value.slice(0, start) + body.value.slice(end);
        if ((remaining.match(stickerPattern) || []).length >= 6) {
          showNotice('一条留言最多 6 张贴图。');
          emojiStatus.textContent = '一条留言最多 6 张贴图。';
          return;
        }
      }
      body.setRangeText(value, start, end, 'end');
      body.dispatchEvent(new Event('input', { bubbles: true }));
      insertRange = { start: body.selectionStart ?? start + value.length, end: body.selectionEnd ?? start + value.length };
      const remembered = { v: value, n: name, g: group, k: kind };
      if (kind === 'sticker' && stickerSlug) remembered.s = stickerSlug;
      remember(remembered);
      hideNotice();
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
      if (event.key !== 'Escape' || emojiPanel.hidden) return;
      event.preventDefault();
      closePanel(emojiPanel.contains(document.activeElement));
    });

    recent = readRecent();
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

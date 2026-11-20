/* 文章编辑器：双源切换、服务端预览、保存状态与本地草稿。 */
(function () {
  'use strict';

  var form = document.querySelector('[data-editor]');
  if (!form) return;

  var source = form.querySelector('#bodySource');
  var html = form.querySelector('#bodyHtml');
  var bodyFormat = form.querySelector('#bodyFormat');
  var contentVersion = form.querySelector('#contentVersion');
  var draftVersionInput = form.querySelector('#draftVersion');
  var title = form.querySelector('#title');
  var slug = form.querySelector('#slug');
  var excerpt = form.querySelector('#excerpt');
  var tags = form.querySelector('#tags');
  var status = form.querySelector('#status');
  var csrf = form.querySelector('input[name="_csrf"]');
  var saveTop = document.querySelector('#editorSaveTop');
  var saveButton = form.querySelector('#editorSaveButton');
  var saveStatus = form.querySelector('#editorSaveStatus');
  var saveState = document.querySelector('#editorSaveState');
  var saveDot = document.querySelector('.editor-save-dot');
  var errorsBox = document.querySelector('#editorErrors');
  var paper = form.querySelector('#editorPaper');
  var preview = form.querySelector('#editorPreview');
  var previewEmpty = form.querySelector('#editorPreviewEmpty');
  var previewWarnings = form.querySelector('#editorWarnings');
  var warningBox = form.querySelector('#editorWarningBox');
  var statsLabel = form.querySelector('#editorStats');
  var sourceStats = form.querySelector('#editorSourceStats');
  var cursorLabel = form.querySelector('#editorCursor');
  var railChars = form.querySelector('#railChars');
  var railMinutes = form.querySelector('#railMinutes');
  var summaryMinutes = form.querySelector('#editorSummaryMinutes');
  var summaryFormat = form.querySelector('#editorSummaryFormat');
  var formatChip = form.querySelector('#editorFormatChip');
  var formatLabel = form.querySelector('#editorFormatLabel');
  var paneLabel = form.querySelector('#editorPaneLabel');
  var versionLabel = document.querySelector('.editor-version');
  var outline = form.querySelector('.editor-outline');
  var draftBanner = form.querySelector('#editorDraftBanner');
  var draftTime = form.querySelector('#editorDraftTime');
  var serverDraftBanner = form.querySelector('#editorServerDraft');
  var draftClear = form.querySelector('#editorDraftClear');
  var checkList = form.querySelector('#editorCheckList');
  var conflict = form.querySelector('#editorConflict');
  var conflictText = form.querySelector('#editorConflictText');
  var htmlNote = form.querySelector('[data-html-note]');
  var stamp = form.querySelector('#editorStamp');
  var inspectorSummary = form.querySelector('#editorInspectorSummary');
  var mobilePreview = form.querySelector('#editorMobilePreview');
  var mobileOutline = form.querySelector('#editorMobileOutline');
  var markdownTools = form.querySelectorAll('[data-markdown-tools] button');
  var rich = form.querySelector('#editorRich');
  var richNote = form.querySelector('#editorRichNote');
  var selectionBar = document.querySelector('#editorSelectionBar');

  var postId = parseInt(form.getAttribute('data-post-id') || '0', 10) || 0;
  var previewUrl = form.getAttribute('data-preview-url') || '/admin/posts/preview-body.html';
  var draftUrl = form.getAttribute('data-draft-url') || '';
  var draftDiscardUrl = form.getAttribute('data-draft-discard-url') || '';
  var serverUpdatedAt = form.getAttribute('data-updated-at') || '';
  var mode = bodyFormat && bodyFormat.value === 'html' ? 'html' : 'markdown';
  var expectedVersion = parseInt(contentVersion && contentVersion.value ? contentVersion.value : '1', 10) || 1;
  var draftVersion = parseInt(form.getAttribute('data-draft-version') || '0', 10) || 0;
  var dirty = false;
  var saving = false;
  var richReady = true;
  var richStale = false;
  var richSource = source ? source.value : '';
  var richSeq = 0;
  var lastRichRange = null;
  var autosaving = false;
  var autosavePaused = false;
  var publishQueued = false;
  var editRevision = 0;
  var previewSeq = 0;
  var previewTimer = 0;
  var draftTimer = 0;
  var autosaveTimer = 0;
  var conflictCurrent = null;
  var pendingDraft = null;
  var draftKey = 'cream-editor:' + (postId > 0 ? postId : 'new');

  function bodyValue() {
    return mode === 'html' ? html.value : source.value;
  }

  function activeTextarea() {
    if (mode === 'html') return html;
    if (mode === 'markdown') return source;
    return null;
  }

  function payloadFormat() {
    return mode === 'html' ? 'html' : 'markdown';
  }

  function escapeMarkdownText(value) {
    return String(value)
      .replace(/\u00a0/g, ' ')
      .replace(/([\\`*_\[\]<>])/g, '\\$1');
  }

  function inlineMarkdown(node) {
    if (!node) return '';
    if (node.nodeType === 3) return escapeMarkdownText(node.nodeValue || '');
    if (node.nodeType !== 1) return '';
    var tag = node.tagName.toLowerCase();
    var inner = function () {
      var text = '';
      node.childNodes.forEach(function (child) { text += inlineMarkdown(child); });
      return text;
    };
    if (tag === 'strong' || tag === 'b') return '**' + inner() + '**';
    if (tag === 'em' || tag === 'i') return '*' + inner() + '*';
    if (tag === 'del' || tag === 's' || tag === 'strike') return '~~' + inner() + '~~';
    if (tag === 'code') {
      var codeText = node.textContent || '';
      var tick = codeText.indexOf('`') >= 0 ? '``' : '`';
      return tick + codeText + tick;
    }
    if (tag === 'a') {
      var href = String(node.getAttribute('href') || '');
      return '[' + inner() + '](' + href + ')';
    }
    if (tag === 'img') {
      return '![' + String(node.getAttribute('alt') || '') + '](' + String(node.getAttribute('src') || '') + ')';
    }
    if (tag === 'br') return '\n';
    if (tag === 'pre') return '\n' + (node.textContent || '') + '\n';
    return inner();
  }

  function listMarkdown(node, depth) {
    var ordered = node.tagName.toLowerCase() === 'ol';
    var lines = [];
    var index = 1;
    Array.from(node.children).forEach(function (item) {
      if (!item || item.tagName.toLowerCase() !== 'li') return;
      var text = '';
      var nested = [];
      item.childNodes.forEach(function (child) {
        var childTag = child.nodeType === 1 ? child.tagName.toLowerCase() : '';
        if (childTag === 'ul' || childTag === 'ol') nested.push(child);
        else text += inlineMarkdown(child);
      });
      var marker = ordered ? (index++) + '.' : '-';
      lines.push('  '.repeat(depth) + marker + ' ' + text.trim());
      nested.forEach(function (list) { lines.push(listMarkdown(list, depth + 1)); });
    });
    return lines.join('\n');
  }

  function tableMarkdown(node) {
    var rows = Array.from(node.querySelectorAll('tr'));
    if (rows.length === 0) return '';
    var lines = rows.map(function (row) {
      var cells = Array.from(row.children).filter(function (cell) {
        return cell.tagName.toLowerCase() === 'td' || cell.tagName.toLowerCase() === 'th';
      });
      return '| ' + cells.map(function (cell) {
        return inlineMarkdown(cell).trim().replace(/\|/g, '\\|').replace(/\n/g, ' ');
      }).join(' | ') + ' |';
    });
    if (lines.length > 1) lines.splice(1, 0, '| ' + Array.from(rows[0].children).map(function () { return '---'; }).join(' | ') + ' |');
    return lines.join('\n');
  }

  function blockMarkdown(node) {
    if (!node) return '';
    if (node.nodeType === 3) {
      var text = String(node.nodeValue || '').trim();
      return text === '' ? '' : escapeMarkdownText(text);
    }
    if (node.nodeType !== 1) return '';
    var tag = node.tagName.toLowerCase();
    if (tag === 'h1') return '# ' + inlineMarkdown(node).trim();
    if (tag === 'h2') return '## ' + inlineMarkdown(node).trim();
    if (tag === 'h3') return '### ' + inlineMarkdown(node).trim();
    if (tag === 'h4') return '#### ' + inlineMarkdown(node).trim();
    if (tag === 'h5' || tag === 'h6') return '##### ' + inlineMarkdown(node).trim();
    if (tag === 'blockquote') {
      return blockChildren(node).split('\n').map(function (line) {
        return line === '' ? '>' : '> ' + line;
      }).join('\n');
    }
    if (tag === 'ul' || tag === 'ol') return listMarkdown(node, 0);
    if (tag === 'pre') {
      var codeText = String(node.textContent || '').replace(/\u00a0/g, ' ').replace(/\n$/, '');
      var language = '';
      var codeNode = node.querySelector('code');
      if (codeNode && codeNode.className) {
        var match = String(codeNode.className).match(/language-([A-Za-z0-9+#._-]+)/);
        if (match) language = match[1];
      }
      var fence = codeText.indexOf('```') >= 0 ? '~~~' : '```';
      return fence + language + '\n' + codeText + '\n' + fence;
    }
    if (tag === 'hr') return '---';
    if (tag === 'table') return tableMarkdown(node);
    if (tag === 'img') return inlineMarkdown(node);
    if (tag === 'p' || tag === 'div' || tag === 'section' || tag === 'article') {
      var hasBlock = Array.from(node.children).some(function (child) {
        return ['p', 'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'pre', 'blockquote', 'table', 'hr'].indexOf(child.tagName.toLowerCase()) >= 0;
      });
      return hasBlock ? blockChildren(node) : inlineMarkdown(node).trim();
    }
    return inlineMarkdown(node).trim();
  }

  function blockChildren(node) {
    var blocks = [];
    node.childNodes.forEach(function (child) {
      var block = blockMarkdown(child);
      if (block !== '') blocks.push(block);
    });
    return blocks.join('\n\n');
  }

  function richToMarkdown() {
    if (!rich) return source.value;
    return blockChildren(rich).replace(/\n{3,}/g, '\n\n').trim();
  }

  function syncRichToSource() {
    if (!rich) return;
    var markdown = richToMarkdown();
    source.value = markdown;
    html.value = rich.innerHTML;
    richSource = markdown;
    richStale = false;
  }


  function setStatus(kind, message) {
    if (saveState) saveState.textContent = message;
    if (saveStatus) saveStatus.textContent = message;
    if (saveDot) {
      saveDot.classList.toggle('is-dirty', kind === 'dirty' || kind === 'saving');
      saveDot.classList.toggle('is-error', kind === 'error');
    }
  }

  var activeToast = null;

  function showToast(message, kind) {
    if (activeToast) {
      activeToast.remove();
      activeToast = null;
    }
    var el = document.createElement('div');
    el.className = 'cream-toast editor-toast' + (kind === 'error' ? ' is-error' : '');
    el.setAttribute('role', 'status');
    el.textContent = message;
    document.body.appendChild(el);
    activeToast = el;
    window.requestAnimationFrame(function () { el.classList.add('is-visible'); });
    window.setTimeout(function () {
      el.classList.remove('is-visible');
      window.setTimeout(function () {
        el.remove();
        if (activeToast === el) activeToast = null;
      }, 240);
    }, kind === 'error' ? 4200 : 3200);
  }

  function setErrors(messages) {
    if (!errorsBox) return;
    errorsBox.replaceChildren();
    if (!messages || !messages.length) {
      errorsBox.hidden = true;
      return;
    }
    messages.forEach(function (message) {
      var p = document.createElement('p');
      p.textContent = String(message);
      errorsBox.appendChild(p);
    });
    errorsBox.hidden = false;
  }

  var saveTopHtml = saveTop ? saveTop.innerHTML : '';
  var saveButtonText = saveButton ? saveButton.textContent : '发布笔记';

  function setBusy(value) {
    saving = value;
    form.setAttribute('aria-busy', value ? 'true' : 'false');
    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
      button.disabled = value;
    });
    if (saveTop) {
      saveTop.disabled = value;
      saveTop.innerHTML = value ? '发布中…' : saveTopHtml;
    }
    if (saveButton) {
      saveButton.disabled = value;
      saveButton.textContent = value ? '发布中…' : saveButtonText;
    }
  }

  function versionText() {
    return '线上 v' + expectedVersion + (draftVersion > 0 ? ' · 工作稿 d' + draftVersion : '');
  }

  function updateVersion(value) {
    expectedVersion = Math.max(1, parseInt(String(value), 10) || 1);
    if (contentVersion) contentVersion.value = String(expectedVersion);
    if (versionLabel) versionLabel.textContent = versionText();
    var summaryVersion = document.querySelector('#editorSummaryVersion');
    if (summaryVersion) summaryVersion.textContent = 'v' + expectedVersion;
  }

  function updateDraftVersion(value) {
    draftVersion = Math.max(0, parseInt(String(value), 10) || 0);
    if (draftVersionInput) draftVersionInput.value = String(draftVersion);
    if (versionLabel) versionLabel.textContent = versionText();
    var summaryDraft = document.querySelector('#editorSummaryDraft');
    if (summaryDraft) summaryDraft.textContent = 'd' + draftVersion;
    if (serverDraftBanner) serverDraftBanner.hidden = draftVersion <= 0;
  }

  function updateDocMeta() {
    var chip = form.querySelector('.editor-slug-chip');
    if (chip) chip.textContent = '/posts/' + (slug.value.trim() !== '' ? slug.value.trim() : 'new-post') + '.html';
  }

  function updateStamp() {
    if (!stamp) return;
    var online = status && status.value === 'published';
    stamp.textContent = online ? 'LIVE' : 'DRAFT';
    stamp.classList.toggle('is-online', online);
  }

  function updateCursor() {
    if (!cursorLabel) return;
    if (mode === 'write') {
      var selection = window.getSelection();
      if (!rich || !selection || selection.rangeCount === 0 || !rich.contains(selection.anchorNode)) {
        cursorLabel.textContent = '写作模式';
        return;
      }
      var range = selection.getRangeAt(0);
      var beforeRange = range.cloneRange();
      beforeRange.selectNodeContents(rich);
      beforeRange.setEnd(range.startContainer, range.startOffset);
      var text = beforeRange.toString().replace(/\u00a0/g, ' ');
      var lines = text.split('\n');
      cursorLabel.textContent = '第 ' + lines.length + ' 行，第 ' + (lines[lines.length - 1].length + 1) + ' 列';
      return;
    }
    var field = activeTextarea();
    if (!field) return;
    var position = typeof field.selectionStart === 'number' ? field.selectionStart : 0;
    var before = field.value.slice(0, position);
    var lines = before.split('\n');
    cursorLabel.textContent = '第 ' + lines.length + ' 行，第 ' + (lines[lines.length - 1].length + 1) + ' 列';
  }

  function updateSourceCount() {
    if (!sourceStats) return;
    var count = bodyValue().length;
    sourceStats.textContent = count + ' 字';
  }

  function currentStats(stats) {
    if (!stats || typeof stats !== 'object') return;
    var chars = Math.max(0, parseInt(String(stats.chars || 0), 10) || 0);
    var minutes = Math.max(1, parseInt(String(stats.reading_minutes || 1), 10) || 1);
    if (statsLabel) statsLabel.textContent = chars + ' 字 · 约 ' + minutes + ' 分钟';
    if (railChars) railChars.textContent = String(chars);
    if (railMinutes) railMinutes.textContent = String(minutes);
    if (summaryMinutes) summaryMinutes.textContent = minutes + ' 分钟';
    if (sourceStats && mode !== 'html') sourceStats.textContent = chars + ' 字';
  }

  function setWarnings(items) {
    var warnings = Array.isArray(items) ? items : [];
    [previewWarnings, warningBox].forEach(function (box) {
      if (!box) return;
      box.querySelectorAll('p').forEach(function (p) { p.remove(); });
      warnings.forEach(function (item) {
        var p = document.createElement('p');
        p.textContent = String(item);
        box.appendChild(p);
      });
      box.hidden = warnings.length === 0;
    });
  }

  function renderOutline(headings) {
    if (!outline) return;
    outline.querySelectorAll('.editor-outline-sub').forEach(function (node) { node.remove(); });
    var anchor = outline.querySelector('a[href="#editorSource"]');
    if (!anchor) return;
    var cursor = anchor;
    (Array.isArray(headings) ? headings : []).forEach(function (heading) {
      var a = document.createElement('a');
      var level = Math.max(2, Math.min(4, parseInt(String(heading.level || 2), 10) || 2));
      a.className = 'editor-outline-sub level-' + level;
      a.href = '#editorPreview';
      var span = document.createElement('span');
      var b = document.createElement('b');
      var text = String(heading.text || '').trim();
      if (text !== '' && text === title.value.trim()) return;
      b.textContent = text.length > 26 ? text.slice(0, 26) + '…' : text;
      a.appendChild(span);
      a.appendChild(b);
      cursor.insertAdjacentElement('afterend', a);
      cursor = a;
    });
  }

  function renderHealth(health) {
    if (!health || !Array.isArray(health.checks) || !checkList) return;
    checkList.replaceChildren();
    var passCount = 0;
    var blockCount = 0;
    health.checks.forEach(function (check) {
      var ok = check && check.ok === true;
      var level = String((check && check.level) || 'warning');
      var item = document.createElement('span');
      item.className = 'editor-check ' + (ok ? 'is-pass' : (level === 'blocker' ? 'is-block' : (level === 'warning' ? 'is-warn' : 'is-info')));
      item.setAttribute('data-check', String((check && check.id) || ''));
      item.title = String((check && check.message) || '');
      var icon = document.createElement('i');
      icon.textContent = ok ? '✓' : (level === 'blocker' ? '!' : (level === 'warning' ? '?' : 'i'));
      item.appendChild(icon);
      item.appendChild(document.createTextNode(String((check && check.label) || '')));
      checkList.appendChild(item);
      if (ok) passCount += 1;
      if (!ok && level === 'blocker') blockCount += 1;
    });
    if (inspectorSummary) {
      if (blockCount > 0) inspectorSummary.textContent = passCount + ' 项通过 · ' + blockCount + ' 项阻止发布';
      else inspectorSummary.textContent = '内容检查通过，可以发布笔记';
    }
  }

  function updateChecks() {
    if (!checkList) return;
    var titleReady = title.value.trim() !== '';
    var slugReady = /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug.value.trim());
    var bodyReady = mode === 'html'
      ? bodyValue().replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim() !== ''
      : bodyValue().trim() !== '';
    var states = {
      title: { state: titleReady ? 'pass' : 'warn', pass: titleReady },
      slug: { state: slugReady ? 'pass' : 'block', pass: slugReady },
      body: { state: bodyReady ? 'pass' : 'block', pass: bodyReady }
    };
    Object.keys(states).forEach(function (key) {
      var el = checkList.querySelector('[data-check="' + key + '"]');
      if (!el) return;
      el.classList.remove('is-pass', 'is-warn', 'is-block', 'is-info');
      el.classList.add('is-' + states[key].state);
      var icon = el.querySelector('i');
      if (icon) icon.textContent = states[key].pass ? '✓' : (states[key].state === 'warn' ? '?' : '!');
    });
  }

  function applyMode(next, silent) {
    next = next === 'html' ? 'html' : (next === 'write' ? 'write' : 'markdown');
    var previousMode = mode;
    var markdownButton = form.querySelector('[data-editor-mode="markdown"]');
    var writeButton = form.querySelector('[data-editor-mode="write"]');
    if ((next === 'markdown' || next === 'write') && markdownButton && markdownButton.disabled) return;
    if (next === 'write' && writeButton && writeButton.disabled) return;
    if (mode === 'write' && next !== 'write') {
      richSeq += 1;
      if (richReady) syncRichToSource();
    }
    mode = next;
    if (bodyFormat) bodyFormat.value = payloadFormat();
    form.querySelectorAll('[data-editor-mode]').forEach(function (button) {
      var active = button.getAttribute('data-editor-mode') === mode;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    source.hidden = mode !== 'markdown';
    html.hidden = mode !== 'html';
    if (rich) rich.hidden = mode !== 'write';
    if (richNote) richNote.hidden = mode !== 'write';
    if (paneLabel) paneLabel.textContent = mode === 'html' ? 'HTML' : (mode === 'write' ? 'WRITE' : 'MARKDOWN');
    if (formatLabel) formatLabel.textContent = mode === 'html' ? 'HTML' : (mode === 'write' ? 'WRITE' : 'MARKDOWN');
    if (formatChip) formatChip.textContent = mode === 'html' ? 'HTML' : (mode === 'write' ? '写作' : 'Markdown');
    if (summaryFormat) summaryFormat.textContent = mode === 'html' ? 'HTML' : (mode === 'write' ? '写作' : 'Markdown');
    if (htmlNote) htmlNote.hidden = mode !== 'html';
    markdownTools.forEach(function (button) { button.disabled = mode === 'html'; });
    if (mode !== 'write') hideSelectionBar();
    if (mode === 'write') {
      if (!silent && previousMode !== 'write') hydrateRich();
      else if (richStale || !richReady || richSource !== source.value) hydrateRich();
    }
    if (!silent) {
      markDirty();
      updateChecks();
      queuePreview(120);
    }
  }

  function markDirty() {
    if (!dirty) {
      dirty = true;
      setStatus('dirty', '有未保存更改');
    }
    editRevision += 1;
    scheduleDraft();
    scheduleAutosave();
  }

  function validateClient() {
    var messages = [];
    if (title.value.trim() === '') messages.push('标题不能为空。');
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug.value.trim())) {
      messages.push('固定链接只能使用小写字母、数字和单个短横线。');
    }
    if (bodyValue().trim() === '') messages.push('正文不能为空。');
    if (bodyValue().length > 1000000) messages.push('源文不能超过 1 MB。');
    if (excerpt.value.length > 500) messages.push('摘要不能超过 500 个字符。');
    if (tags.value.length > 500) messages.push('标签总长度不能超过 500 个字符。');
    return messages;
  }

  function collectPayload() {
    return {
      _csrf: csrf ? csrf.value : '',
      title: title.value.trim(),
      slug: slug.value.trim(),
      excerpt: excerpt.value.trim(),
      tags: tags.value.trim(),
      status: status ? status.value : 'draft',
      body_format: payloadFormat(),
      content_version: expectedVersion,
      draft_version: draftVersion,
      body_source: payloadFormat() === 'markdown' ? source.value : '',
      body: payloadFormat() === 'html' ? html.value : ''
    };
  }

  function postJson(url, payload, timeout) {
    var controller = new AbortController();
    var timer = window.setTimeout(function () { controller.abort(); }, timeout || 20000);
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload),
      signal: controller.signal
    }).then(function (response) {
      return response.text().then(function (text) {
        var json = null;
        if (text !== '') {
          try { json = JSON.parse(text); } catch (error) { json = null; }
        }
        return { response: response, json: json };
      });
    }).finally(function () {
      window.clearTimeout(timer);
    });
  }

  function scheduleAutosave() {
    if (!draftUrl || autosavePaused) return;
    window.clearTimeout(autosaveTimer);
    autosaveTimer = window.setTimeout(function () { saveDraftNow(false); }, 1800);
  }

  function saveDraftNow(force) {
    if (!draftUrl || saving || autosaving) return;
    if (!dirty && !force) return;
    if (force && conflictCurrent && conflictCurrent.type === 'draft') {
      updateDraftVersion(conflictCurrent.draft_version || 0);
    }
    var revision = editRevision;
    autosaving = true;
    setStatus('saving', '正在保存工作稿…');

    postJson(draftUrl, collectPayload(), 15000).then(function (result) {
      var response = result.response;
      var json = result.json;

      if (response.status === 409 && json && json.code === 'draft_conflict') {
        autosavePaused = true;
        publishQueued = false;
        conflictCurrent = {
          type: 'draft',
          draft_version: parseInt(String((json.current && json.current.draft_version) || draftVersion), 10) || draftVersion,
          updated_at: String((json.current && json.current.updated_at) || '')
        };
        if (conflictText) conflictText.textContent = '这份工作稿已在另一个窗口更新到 d' + conflictCurrent.draft_version + '。自动保存已暂停。';
        if (conflict) conflict.hidden = false;
        setStatus('error', '工作稿冲突，等待你的选择');
        writeDraft();
        return;
      }

      if (!response.ok || !json || !json.ok) {
        var list = json && Array.isArray(json.errors) ? json.errors : ['自动保存失败，内容已留在本地草稿。'];
        setErrors(list);
        setStatus('error', '自动保存失败，稍后重试');
        writeDraft();
        return;
      }

      updateDraftVersion(json.draft_version || draftVersion);
      if (json.updated_at) {
        serverUpdatedAt = String(json.updated_at);
        form.setAttribute('data-updated-at', serverUpdatedAt);
      }
      if (json.health) renderHealth(json.health);
      if (revision === editRevision) dirty = false;
      clearDraft();
      var time = formatClock(serverUpdatedAt);
      setStatus('saved', '工作稿已保存' + (time ? ' ' + time : ''));
      if (json.stale) setStatus('error', '工作稿已保存，但线上文章已有更新');
      if (json.stale) autosavePaused = true;
    }).catch(function (error) {
      var message = error && error.name === 'AbortError'
        ? '自动保存超时，内容已留在本地草稿。'
        : '自动保存失败，内容已留在本地草稿。';
      setErrors([message]);
      setStatus('error', '自动保存失败');
      writeDraft();
    }).finally(function () {
      autosaving = false;
      if (publishQueued && !autosavePaused) {
        publishQueued = false;
        save();
        return;
      }
      if (dirty && revision !== editRevision && !autosavePaused) scheduleAutosave();
    });
  }

  function save() {
    if (saving) return;
    if (autosaving) {
      publishQueued = true;
      setStatus('saving', '正在保存工作稿，马上发布…');
      showToast('正在保存工作稿，完成后会自动发布。');
      return;
    }
    publishQueued = false;
    var messages = validateClient();
    if (messages.length) {
      setErrors(messages);
      setStatus('error', '请先补齐检查项');
      showToast(messages[0] || '请先补齐检查项。', 'error');
      if (errorsBox) {
        errorsBox.setAttribute('tabindex', '-1');
        errorsBox.focus();
        errorsBox.scrollIntoView({ block: 'nearest' });
      }
      return;
    }
    setErrors([]);
    setBusy(true);
    setStatus('saving', '正在发布笔记…');

    postJson(form.getAttribute('action') || window.location.pathname, collectPayload(), 20000).then(function (result) {
      var response = result.response;
      var json = result.json;

      if (response.status === 409 && json && json.current) {
        conflictCurrent = json.current;
        conflictCurrent.type = 'publish';
        var currentVersion = parseInt(String(json.current.content_version || expectedVersion), 10) || expectedVersion;
        if (conflictText) {
          conflictText.textContent = '这篇文稿已在另一个窗口保存到 v' + currentVersion + '（' + String(json.current.updated_at || '刚刚') + '）。';
        }
        if (conflict) conflict.hidden = false;
        setStatus('error', '版本冲突，等待你的选择');
        setErrors([]);
        showToast('线上已有更新，请在冲突面板选择载入最新或覆盖发布。', 'error');
        return;
      }

      if (!response.ok || !json || !json.ok) {
        var list = json && Array.isArray(json.errors) ? json.errors : ['保存失败，请检查网络后重试。'];
        setErrors(list);
        if (json && json.health) renderHealth(json.health);
        setStatus('error', json && json.code === 'blocked' ? '内容检查未通过' : '发布失败');
        showToast(list[0] || '发布失败，请检查网络后重试。', 'error');
        return;
      }

      dirty = false;
      conflictCurrent = null;
      if (conflict) conflict.hidden = true;
      var publishedVersion = typeof json.content_version !== 'undefined' ? json.content_version : expectedVersion;
      if (typeof json.content_version !== 'undefined') updateVersion(json.content_version);
      updateDraftVersion(0);
      if (json.updated_at) {
        serverUpdatedAt = String(json.updated_at);
        form.setAttribute('data-updated-at', serverUpdatedAt);
        var time = formatClock(serverUpdatedAt);
        setStatus('saved', '已发布 v' + expectedVersion + (time ? ' ' + time : ''));
      } else {
        setStatus('saved', '已发布 v' + expectedVersion);
      }
      if (json.health) renderHealth(json.health);
      showToast('已发布 v' + publishedVersion + '，线上笔记已更新。');
      autosavePaused = false;
      clearDraft();
      updateChecks();
      refreshPreview();

      if (json.redirect) {
        window.location.href = String(json.redirect);
      }
    }).catch(function (error) {
      var message = error && error.name === 'AbortError'
        ? '保存请求超时，请确认网络后重试。'
        : '保存失败，请确认网络后重试。';
      setErrors([message]);
      setStatus('error', '保存失败');
      showToast(message, 'error');
    }).finally(function () {
      setBusy(false);
    });
  }

  function formatClock(value) {
    if (!value) return '';
    var date = new Date(String(value).replace(' ', 'T'));
    if (isNaN(date.getTime())) return '';
    return String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
  }

  function refreshPreview() {
    var value = bodyValue();
    if (value.trim() === '') {
      previewSeq += 1;
      if (preview) preview.replaceChildren();
      if (previewEmpty) previewEmpty.hidden = false;
      setWarnings([]);
      renderOutline([]);
      currentStats({ chars: 0, reading_minutes: 1 });
      updateChecks();
      return;
    }

    var seq = ++previewSeq;
    postJson(previewUrl, {
      _csrf: csrf ? csrf.value : '',
      post_id: postId,
      format: payloadFormat(),
      source: value,
      title: title.value.trim(),
      slug: slug.value.trim(),
      excerpt: excerpt.value.trim(),
      tags: tags.value.trim()
    }, 12000).then(function (result) {
      if (seq !== previewSeq) return;
      if (!result.response.ok || !result.json || !result.json.ok) {
        var list = result.json && Array.isArray(result.json.errors) ? result.json.errors : ['预览暂时不可用，保存后以线上页面为准。'];
        setWarnings(list);
        return;
      }
      var data = result.json;
      if (preview) preview.innerHTML = typeof data.html === 'string' ? data.html : '';
      if (previewEmpty) {
        var plain = (typeof data.html === 'string' ? data.html : '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
        previewEmpty.hidden = plain !== '';
      }
      setWarnings(data.warnings || []);
      renderOutline(data.headings || []);
      currentStats(data.stats || { chars: 0, reading_minutes: 1 });
      if (data.health) renderHealth(data.health);
    }).catch(function () {
      if (seq !== previewSeq) return;
      setWarnings(['预览暂时不可用，保存后以线上页面为准。']);
    });
  }

  function queuePreview(delay) {
    window.clearTimeout(previewTimer);
    previewTimer = window.setTimeout(refreshPreview, typeof delay === 'number' ? delay : 550);
  }

  function scheduleDraft() {
    window.clearTimeout(draftTimer);
    draftTimer = window.setTimeout(writeDraft, 700);
  }

  function writeDraft() {
    if (!dirty) return;
    var data = {
      title: title.value,
      slug: slug.value,
      excerpt: excerpt.value,
      tags: tags.value,
      status: status ? status.value : 'draft',
      mode: mode,
      source: source.value,
      html: html.value,
      rich: rich ? rich.innerHTML : '',
      updatedAt: new Date().toISOString(),
      serverUpdatedAt: serverUpdatedAt
    };
    try { window.localStorage.setItem(draftKey, JSON.stringify(data)); } catch (error) { /* 私密模式或空间已满时忽略。 */ }
  }

  function readDraft() {
    try {
      var raw = window.localStorage.getItem(draftKey);
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  function clearDraft() {
    pendingDraft = null;
    if (draftBanner) draftBanner.hidden = true;
    try { window.localStorage.removeItem(draftKey); } catch (error) { /* 忽略不可写存储。 */ }
  }

  function draftDiffers(draft) {
    return String(draft.title || '') !== title.value
      || String(draft.slug || '') !== slug.value
      || String(draft.excerpt || '') !== excerpt.value
      || String(draft.tags || '') !== tags.value
      || String(draft.status || 'draft') !== (status ? status.value : 'draft')
      || String(draft.mode || 'markdown') !== mode
      || String(draft.source || '') !== source.value
      || String(draft.html || '') !== html.value
      || (String(draft.mode || '') === 'write' && mode === 'write' && String(draft.rich || '') !== (rich ? rich.innerHTML : ''));
  }

  function loadDraft() {
    var draft = readDraft();
    if (!draft || !draftDiffers(draft)) {
      if (draft) clearDraft();
      return;
    }
    var draftBase = String(draft.serverUpdatedAt || '');
    if (draftBase !== '' && serverUpdatedAt !== '' && draftBase !== serverUpdatedAt) {
      clearDraft();
      return;
    }
    if (draftBase === '') {
      var localTime = Date.parse(String(draft.updatedAt || ''));
      var remoteTime = Date.parse(String(serverUpdatedAt || '').replace(' ', 'T'));
      if (!isNaN(localTime) && !isNaN(remoteTime) && localTime <= remoteTime) {
        clearDraft();
        return;
      }
    }
    pendingDraft = draft;
    if (draftTime) draftTime.textContent = formatDraftTime(draft.updatedAt);
    if (draftBanner) draftBanner.hidden = false;
  }

  function formatDraftTime(value) {
    var date = new Date(String(value || ''));
    if (isNaN(date.getTime())) return '刚刚';
    try {
      return new Intl.DateTimeFormat('zh-CN', {
        month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false
      }).format(date);
    } catch (error) {
      return '刚刚';
    }
  }

  function restoreDraft() {
    if (!pendingDraft) return;
    var draft = pendingDraft;
    title.value = String(draft.title || '');
    slug.value = String(draft.slug || '');
    excerpt.value = String(draft.excerpt || '');
    tags.value = String(draft.tags || '');
    if (status) status.value = String(draft.status || 'draft');
    source.value = String(draft.source || '');
    html.value = String(draft.html || '');
    var draftMode = String(draft.mode || 'markdown');
    richSeq += 1;
    if (rich) {
      rich.innerHTML = String(draft.rich || '');
      richSource = source.value;
      richReady = String(draft.rich || '') !== '';
      richStale = !richReady;
    }
    applyMode(draftMode === 'html' ? 'html' : (draftMode === 'write' ? 'write' : 'markdown'), true);
    dirty = false;
    markDirty();
    updateChecks();
    updateDocMeta();
    updateStamp();
    updateSourceCount();
    queuePreview(80);
    clearDraft();
    setStatus('dirty', '已恢复本地草稿');
  }

  function escapeHtml(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function safeLink(value) {
    var url = String(value || '').trim();
    return /^(https?:\/\/|mailto:|\/|#)/i.test(url) ? url : '';
  }

  function hideSelectionBar() {
    if (selectionBar) selectionBar.hidden = true;
  }

  function refreshSelectionBar() {
    if (!selectionBar || mode !== 'write' || !rich || rich.hidden) {
      hideSelectionBar();
      return;
    }
    var selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || selection.isCollapsed || !rich.contains(selection.anchorNode)) {
      hideSelectionBar();
      return;
    }
    var range = selection.getRangeAt(0);
    var rect = range.getClientRects()[0] || range.getBoundingClientRect();
    if (!rect || (rect.width === 0 && rect.height === 0) || !paper) {
      hideSelectionBar();
      return;
    }
    selectionBar.hidden = false;
    var paperRect = paper.getBoundingClientRect();
    var half = (selectionBar.offsetWidth || 160) / 2;
    var left = rect.left - paperRect.left + rect.width / 2;
    var top = rect.top - paperRect.top - 8;
    left = Math.max(half + 8, Math.min(paperRect.width - half - 8, left));
    top = Math.max(8, top);
    selectionBar.style.left = left + 'px';
    selectionBar.style.top = top + 'px';
  }

  function rememberRichSelection() {
    if (!rich || mode !== 'write') {
      lastRichRange = null;
      hideSelectionBar();
      return;
    }
    var selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || !rich.contains(selection.anchorNode)) {
      lastRichRange = null;
      hideSelectionBar();
      return;
    }
    lastRichRange = selection.getRangeAt(0).cloneRange();
    if (lastRichRange.collapsed) hideSelectionBar();
    else refreshSelectionBar();
    updateCursor();
  }

  function restoreRichSelection() {
    if (!rich) return;
    if (!lastRichRange) {
      rich.focus();
      return;
    }
    var selection = window.getSelection();
    if (!selection) return;
    selection.removeAllRanges();
    selection.addRange(lastRichRange);
  }

  function hydrateRich() {
    if (!rich || !source) return;
    var seq = ++richSeq;
    richReady = false;
    richStale = true;
    rich.setAttribute('contenteditable', 'false');
    rich.setAttribute('aria-busy', 'true');
    rich.innerHTML = '<p class="editor-rich-loading">正在把 Markdown 排成纸面…</p>';
    var fail = function () {
      if (seq !== richSeq) return;
      richReady = false;
      richStale = true;
      rich.innerHTML = '';
      rich.setAttribute('contenteditable', 'true');
      rich.removeAttribute('aria-busy');
      if (mode === 'write') applyMode('markdown', true);
      setStatus('error', '写作态暂时不可用');
      showToast('写作态暂时不可用，已切回 Markdown。', 'error');
    };
    postJson(previewUrl, {
      _csrf: csrf ? csrf.value : '',
      post_id: postId,
      format: 'markdown',
      writing: true,
      source: source.value,
      title: title.value.trim(),
      slug: slug.value.trim(),
      excerpt: excerpt.value.trim(),
      tags: tags.value.trim()
    }, 12000).then(function (result) {
      if (seq !== richSeq) return;
      if (!result.response.ok || !result.json || !result.json.ok) {
        fail();
        return;
      }
      var data = result.json;
      rich.innerHTML = typeof data.html === 'string' ? data.html : '';
      richReady = true;
      richStale = false;
      richSource = source.value;
      rich.setAttribute('contenteditable', 'true');
      rich.removeAttribute('aria-busy');
      if (data.stats) currentStats(data.stats);
    }).catch(fail);
  }

  function runRichCommand(kind, inlineOnly) {
    if (!rich || !richReady || mode !== 'write') return;
    var selection = window.getSelection();
    if (!selection) return;
    restoreRichSelection();
    var selectedText = selection.toString();
    if (kind === 'link') {
      var href = window.prompt('链接地址', 'https://');
      if (href === null) return;
      var safe = safeLink(href);
      if (safe === '') {
        showToast('链接只支持 http、https、mailto、站内路径或锚点。', 'error');
        return;
      }
      if (selection.isCollapsed) {
        document.execCommand('insertHTML', false, '<a href="' + escapeHtml(safe) + '">' + escapeHtml(safe) + '</a>');
      } else {
        document.execCommand('createLink', false, safe);
      }
    } else if (kind === 'bold' || kind === 'italic') {
      document.execCommand(kind, false, null);
    } else if (kind === 'code' && inlineOnly === true) {
      var codeText = selectedText || '代码';
      document.execCommand('insertHTML', false, '<code>' + escapeHtml(codeText) + '</code>');
    } else if (kind === 'code') {
      var blockText = selectedText || '代码';
      document.execCommand('insertHTML', false, '<pre><code>' + escapeHtml(blockText) + '</code></pre>');
    } else if (kind === 'h2') {
      document.execCommand('formatBlock', false, '<h2>');
    } else if (kind === 'quote') {
      document.execCommand('formatBlock', false, '<blockquote>');
    } else if (kind === 'list') {
      document.execCommand('insertUnorderedList', false, null);
    } else if (kind === 'hr') {
      document.execCommand('insertHTML', false, '<hr>');
    } else if (kind === 'table') {
      document.execCommand('insertHTML', false, '<table><thead><tr><th>列一</th><th>列二</th></tr></thead><tbody><tr><td>内容</td><td></td></tr></tbody></table>');
    } else {
      return;
    }
    syncRichToSource();
    markDirty();
    updateChecks();
    updateDocMeta();
    updateStamp();
    updateSourceCount();
    updateCursor();
    queuePreview(120);
    hideSelectionBar();
  }

  function insertSnippet(kind) {
    if (mode === 'write') {
      runRichCommand(kind, false);
      return;
    }
    var field = activeTextarea();
    if (!field) return;
    var start = field.selectionStart;
    var end = field.selectionEnd;
    var selected = field.value.slice(start, end);
    var map = mode === 'html'
      ? {
          h2: ['<h2>', '</h2>', '小节标题'],
          bold: ['<strong>', '</strong>', '重点'],
          italic: ['<em>', '</em>', '语气'],
          link: ['<a href="https://">', '</a>', '链接文字'],
          quote: ['<blockquote>', '</blockquote>', '引用'],
          list: ['<ul>\n  <li>', '</li>\n</ul>', '列表项'],
          code: ['<pre><code>', '</code></pre>', '代码'],
          table: ['<table>\n  <thead><tr><th>列一</th><th>列二</th></tr></thead>\n  <tbody><tr><td>', '</td><td></td></tr></tbody>\n</table>', '内容'],
          hr: ['<hr>', '', '']
        }
      : {
          h2: ['## ', '', '小节标题'],
          bold: ['**', '**', '重点'],
          italic: ['*', '*', '语气'],
          link: ['[', '](https://)', '链接文字'],
          quote: ['> ', '', '引用'],
          list: ['- ', '', '列表项'],
          code: ['\n```\n', '\n```\n', '代码'],
          table: ['\n| 列一 | 列二 |\n| --- | --- |\n| ', ' |  |\n', '内容'],
          hr: ['\n---\n', '', '']
        };
    var snippet = map[kind];
    if (!snippet) return;
    var before = snippet[0];
    var after = snippet[1];
    var placeholder = snippet[2];
    var content = selected || placeholder;
    field.setRangeText(before + content + after, start, end, 'end');
    field.setSelectionRange(start + before.length, start + before.length + content.length);
    field.focus();
    field.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function toggleMobilePreview() {
    if (!paper) return;
    var showing = paper.classList.toggle('is-previewing');
    if (mobilePreview) {
      var label = mobilePreview.querySelector('span');
      if (label) label.textContent = showing ? '编辑' : '预览';
      mobilePreview.setAttribute('aria-pressed', showing ? 'true' : 'false');
    }
    if (showing) queuePreview(0);
  }

  function toggleMobileOutline() {
    var page = form.closest('.editor-page');
    if (!page) return;
    var open = page.classList.toggle('is-outline-open');
    if (mobileOutline) mobileOutline.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  form.addEventListener('input', function (event) {
    var target = event.target;
    if (!target || !target.matches('input, textarea, select')) return;
    if (target.id === 'contentVersion') return;
    markDirty();
    updateChecks();
    updateDocMeta();
    updateStamp();
    updateSourceCount();
    updateCursor();
    if (target === source) richStale = true;
    if (target === source || target === html) queuePreview();
    else queuePreview(700);
  });

  form.addEventListener('change', function (event) {
    if (event.target === status) updateStamp();
    if (event.target.id === 'contentVersion') return;
    markDirty();
    updateChecks();
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    save();
  });

  form.addEventListener('click', function (event) {
    var modeButton = event.target.closest('[data-editor-mode]');
    if (modeButton) {
      event.preventDefault();
      applyMode(modeButton.getAttribute('data-editor-mode'), false);
      return;
    }
    var insertButton = event.target.closest('[data-editor-insert]');
    if (insertButton) {
      event.preventDefault();
      insertSnippet(insertButton.getAttribute('data-editor-insert'));
      return;
    }
  });

  [source, html].forEach(function (field) {
    field.addEventListener('keyup', updateCursor);
    field.addEventListener('click', updateCursor);
    field.addEventListener('select', updateCursor);
    field.addEventListener('keydown', function (event) {
      if (event.key === 'Tab') {
        event.preventDefault();
        field.setRangeText('  ', field.selectionStart, field.selectionEnd, 'end');
        field.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  });

  if (rich) {
    rich.addEventListener('input', function () {
      if (mode !== 'write' || !richReady) return;
      syncRichToSource();
      markDirty();
      updateChecks();
      updateDocMeta();
      updateStamp();
      updateSourceCount();
      updateCursor();
      queuePreview();
    });
    rich.addEventListener('keyup', updateCursor);
    rich.addEventListener('click', updateCursor);
    rich.addEventListener('paste', function (event) {
      if (!richReady) return;
      event.preventDefault();
      var clipboard = event.clipboardData || window.clipboardData;
      var text = clipboard ? clipboard.getData('text/plain') : '';
      if (text !== '') document.execCommand('insertText', false, text);
    });
  }

  document.addEventListener('selectionchange', rememberRichSelection);
  document.addEventListener('mousedown', function (event) {
    if (mode === 'write' && event.target.closest('[data-editor-insert], [data-rich-format]')) event.preventDefault();
  });

  if (selectionBar) {
    selectionBar.addEventListener('click', function (event) {
      var button = event.target.closest('[data-rich-format]');
      if (!button) return;
      event.preventDefault();
      var kind = button.getAttribute('data-rich-format');
      runRichCommand(kind, kind === 'code');
    });
  }

  if (mobilePreview) mobilePreview.addEventListener('click', function (event) { event.preventDefault(); toggleMobilePreview(); });
  if (mobileOutline) mobileOutline.addEventListener('click', function (event) { event.preventDefault(); toggleMobileOutline(); });

  if (outline) {
    outline.addEventListener('click', function (event) {
      if (!event.target.closest('a')) return;
      var page = form.closest('.editor-page');
      if (page) page.classList.remove('is-outline-open');
      if (mobileOutline) mobileOutline.setAttribute('aria-expanded', 'false');
    });
  }

  var tabButtons = form.querySelectorAll('[data-inspector-tab]');
  tabButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      var tab = button.getAttribute('data-inspector-tab');
      tabButtons.forEach(function (other) {
        var active = other === button;
        other.classList.toggle('is-active', active);
        other.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      form.querySelectorAll('[data-inspector-panel]').forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-inspector-panel') !== tab;
      });
    });
  });

  if (draftBanner) {
    var restoreButton = form.querySelector('#editorDraftRestore');
    var discardButton = form.querySelector('#editorDraftDiscard');
    if (restoreButton) restoreButton.addEventListener('click', restoreDraft);
    if (discardButton) discardButton.addEventListener('click', clearDraft);
  }

  if (draftClear && draftDiscardUrl) {
    draftClear.addEventListener('click', function () {
      if (!window.confirm('丢弃后，这份工作稿的修改会从后台移除，线上文章不受影响。')) return;
      postJson(draftDiscardUrl, { _csrf: csrf ? csrf.value : '' }, 12000).then(function (result) {
        if (result.response.ok && result.json && result.json.ok) {
          window.location.reload();
          return;
        }
        setStatus('error', '丢弃工作稿失败');
      }).catch(function () { setStatus('error', '丢弃工作稿失败'); });
    });
  }

  if (conflict) {
    conflict.addEventListener('click', function (event) {
      var action = event.target.closest('[data-conflict]');
      if (!action) return;
      var kind = action.getAttribute('data-conflict');
      if (kind === 'reload') {
        window.location.reload();
      } else if (kind === 'overwrite') {
        if (conflictCurrent && conflictCurrent.type === 'draft') {
          var draftCurrent = conflictCurrent;
          conflictCurrent = null;
          conflict.hidden = true;
          autosavePaused = false;
          updateDraftVersion(draftCurrent.draft_version || draftVersion);
          saveDraftNow(true);
        } else if (conflictCurrent) {
          updateVersion(conflictCurrent.content_version || expectedVersion);
          conflict.hidden = true;
          conflictCurrent = null;
          save();
        }
      } else {
        conflict.hidden = true;
      }
    });
  }

  document.addEventListener('click', function (event) {
    var page = form.closest('.editor-page');
    if (page && page.classList.contains('is-outline-open')
      && !event.target.closest('.editor-rail')
      && !event.target.closest('#editorMobileOutline')
    ) {
      page.classList.remove('is-outline-open');
      if (mobileOutline) mobileOutline.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', function (event) {
    var key = String(event.key || '').toLowerCase();
    var meta = event.metaKey || event.ctrlKey;
    if (meta && key === 's') {
      event.preventDefault();
      if (draftUrl) saveDraftNow(true);
      else save();
      return;
    }
    if (meta && key === 'k' && mode === 'write') {
      event.preventDefault();
      runRichCommand('link', false);
      return;
    }
    if (meta && mode === 'write' && (key === 'b' || key === 'i')) {
      event.preventDefault();
      runRichCommand(key === 'b' ? 'bold' : 'italic', false);
      return;
    }
    if (key === 'escape') {
      var page = form.closest('.editor-page');
      if (page) page.classList.remove('is-outline-open');
      hideSelectionBar();
    }
  });

  window.addEventListener('beforeunload', function (event) {
    if (!dirty || saving) return;
    event.preventDefault();
    event.returnValue = '';
  });

  applyMode(mode, true);
  updateDraftVersion(draftVersion);
  updateChecks();
  updateDocMeta();
  updateStamp();
  updateSourceCount();
  updateCursor();
  loadDraft();
})();

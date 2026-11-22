(function () {
  'use strict';

  const dialog = document.querySelector('[data-media-dialog]');
  const bulkForm = document.querySelector('[data-media-bulk-form]');
  const selectAll = document.querySelector('[data-media-select-all]');
  const bulkBar = document.querySelector('[data-media-bulk-bar]');
  const selectedCount = document.querySelector('[data-media-selected-count]');
  const toast = (function () {
    let node = null;
    let timer = 0;
    return function show(message) {
      if (!node) {
        node = document.createElement('div');
        node.className = 'media-toast';
        node.setAttribute('role', 'status');
        document.body.appendChild(node);
      }
      node.textContent = message;
      node.hidden = false;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        node.hidden = true;
      }, 1800);
    };
  })();

  function cardOf(node) {
    return node ? node.closest('[data-media-item]') : null;
  }

  function closeDetail() {
    if (!dialog) {
      return;
    }
    dialog.classList.remove('is-fallback-open');
    document.body.classList.remove('media-dialog-open');
    if (typeof dialog.close === 'function') {
      if (dialog.open) {
        dialog.close();
      }
      return;
    }
    dialog.removeAttribute('open');
  }

  function showDetail() {
    if (!dialog) {
      return false;
    }
    if (typeof dialog.showModal === 'function') {
      try {
        if (dialog.open) {
          closeDetail();
        }
        dialog.showModal();
        return true;
      } catch (error) {
        // 原生弹层不可用时继续走普通定位方案。
      }
    }
    dialog.setAttribute('open', '');
    dialog.classList.add('is-fallback-open');
    document.body.classList.add('media-dialog-open');
    return true;
  }

  function inputById(id) {
    return id ? document.getElementById(id) : null;
  }

  function fallbackCopy(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    let copied = false;
    try {
      copied = document.execCommand('copy');
    } catch (error) {
      copied = false;
    }
    document.body.removeChild(area);
    return copied;
  }

  async function copyText(text) {
    if (!text) {
      return;
    }
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        toast('已复制');
        return;
      }
    } catch (error) {
      // 继续使用兼容方案。
    }
    toast(fallbackCopy(text) ? '已复制' : '复制失败，请手动选择文本');
  }

  function refreshSelection() {
    if (!bulkForm) {
      return;
    }
    const checks = Array.from(bulkForm.querySelectorAll('[data-media-check]'));
    const selected = checks.filter(function (item) { return item.checked; });
    checks.forEach(function (item) {
      const card = cardOf(item);
      if (card) {
        card.classList.toggle('is-selected', item.checked);
      }
    });
    if (selectedCount) {
      selectedCount.textContent = String(selected.length);
    }
    if (bulkBar) {
      bulkBar.hidden = selected.length === 0;
    }
    if (selectAll) {
      selectAll.checked = checks.length > 0 && selected.length === checks.length;
      selectAll.indeterminate = selected.length > 0 && selected.length < checks.length;
    }
  }

  function openDetail(trigger) {
    const card = cardOf(trigger);
    if (!card || !dialog) {
      return false;
    }
    const fields = {
      name: card.dataset.mediaName || '图片详情',
      url: card.dataset.mediaUrl || '',
      html: card.dataset.mediaHtml || '',
      type: card.dataset.mediaType || '图片',
      size: (card.dataset.mediaWidth || '?') + '×' + (card.dataset.mediaHeight || '?'),
      bytes: card.dataset.mediaSize || '0',
      created: card.dataset.mediaCreated || '',
      id: card.dataset.mediaId || ''
    };
    const nameNode = dialog.querySelector('[data-detail-name]');
    const nameInput = dialog.querySelector('[data-detail-name-input]');
    const image = dialog.querySelector('[data-detail-image]');
    const urlInput = dialog.querySelector('[data-detail-url]');
    const htmlInput = dialog.querySelector('[data-detail-html]');
    const openLink = dialog.querySelector('[data-detail-open]');
    const renameForm = dialog.querySelector('[data-media-rename]');
    const deleteForm = dialog.querySelector('[data-media-delete]');
    if (nameNode) { nameNode.textContent = fields.name; }
    if (nameInput) { nameInput.value = fields.name; }
    if (image) { image.src = fields.url; image.alt = fields.name; }
    if (urlInput) { urlInput.value = fields.url; }
    if (htmlInput) { htmlInput.value = fields.html; }
    if (openLink) { openLink.href = fields.url; }
    if (renameForm && fields.id) { renameForm.action = '/admin/media/' + fields.id + '/rename.html'; }
    if (deleteForm && fields.id) { deleteForm.action = '/admin/media/' + fields.id + '/delete.html'; }

    const bytes = Number(fields.bytes || 0);
    const bytesLabel = bytes >= 1048576
      ? (bytes / 1048576).toFixed(1) + ' MB'
      : (bytes >= 1024 ? Math.round(bytes / 1024) + ' KB' : bytes + ' B');
    const typeNode = dialog.querySelector('[data-detail-type]');
    const sizeNode = dialog.querySelector('[data-detail-size]');
    const bytesNode = dialog.querySelector('[data-detail-bytes]');
    const createdNode = dialog.querySelector('[data-detail-created]');
    if (typeNode) { typeNode.textContent = fields.type; }
    if (sizeNode) { sizeNode.textContent = fields.size; }
    if (bytesNode) { bytesNode.textContent = bytesLabel; }
    if (createdNode) { createdNode.textContent = fields.created; }

    return showDetail();
  }

  document.addEventListener('click', function (event) {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const openTrigger = target.closest('[data-media-open]');
    if (openTrigger) {
      const opened = openDetail(openTrigger);
      if (opened) {
        event.preventDefault();
      }
      return;
    }

    const urlTrigger = target.closest('[data-copy-url]');
    if (urlTrigger) {
      const card = cardOf(urlTrigger);
      if (card) { copyText(card.dataset.mediaUrl || ''); }
      return;
    }

    const htmlTrigger = target.closest('[data-copy-html]');
    if (htmlTrigger) {
      const card = cardOf(htmlTrigger);
      if (card) { copyText(card.dataset.mediaHtml || ''); }
      return;
    }

    const copyTarget = target.closest('[data-copy-target]');
    if (copyTarget) {
      const input = inputById(copyTarget.getAttribute('data-copy-target'));
      if (input) { copyText(input.value); }
      return;
    }

    if (target.closest('[data-media-close]')) {
      closeDetail();
      return;
    }

    if (target.closest('[data-media-clear]')) {
      if (bulkForm) {
        bulkForm.querySelectorAll('[data-media-check]').forEach(function (item) {
          item.checked = false;
        });
        refreshSelection();
      }
    }
  });

  document.addEventListener('change', function (event) {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    if (target.matches('[data-media-check]')) {
      refreshSelection();
      return;
    }
    if (target.matches('[data-media-select-all]') && bulkForm) {
      bulkForm.querySelectorAll('[data-media-check]').forEach(function (item) {
        item.checked = target.checked;
      });
      refreshSelection();
    }
  });

  if (dialog) {
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        closeDetail();
      }
    });
    dialog.addEventListener('cancel', function () {
      dialog.classList.remove('is-fallback-open');
      document.body.classList.remove('media-dialog-open');
    });
  }

  if (bulkForm) {
    bulkForm.addEventListener('submit', function (event) {
      const selected = bulkForm.querySelectorAll('[data-media-check]:checked').length;
      if (selected === 0) {
        event.preventDefault();
        toast('请先选择图片');
        return;
      }
      if (!window.confirm('确认删除选中的 ' + selected + ' 张图片？文章里的引用会失效。')) {
        event.preventDefault();
      }
    });
  }

  const uploadForm = document.querySelector('[data-media-upload]');
  const fileInput = document.querySelector('[data-media-files]');
  const dropZone = document.querySelector('[data-media-drop]');
  const picked = document.querySelector('[data-media-picked]');

  function showPicked(files) {
    if (!picked) {
      return;
    }
    picked.classList.toggle('has-files', Boolean(files && files.length > 0));
    if (!files || files.length === 0) {
      picked.textContent = '还没有选择文件';
      picked.removeAttribute('title');
      return;
    }
    const names = Array.from(files).map(function (file) { return file.name; });
    const head = names.length === 1 ? '已选择 1 张' : '已选择 ' + names.length + ' 张';
    picked.textContent = head + '：' + names.slice(0, 3).join('、') + (names.length > 3 ? ' 等' : '');
    picked.title = names.join('、');
  }

  if (fileInput) {
    fileInput.addEventListener('change', function () {
      showPicked(fileInput.files);
    });
  }

  if (dropZone && fileInput) {
    ['dragenter', 'dragover'].forEach(function (name) {
      dropZone.addEventListener(name, function (event) {
        event.preventDefault();
        dropZone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (name) {
      dropZone.addEventListener(name, function (event) {
        event.preventDefault();
        dropZone.classList.remove('is-dragover');
      });
    });
    dropZone.addEventListener('drop', function (event) {
      const files = event.dataTransfer && event.dataTransfer.files;
      if (!files || files.length === 0) {
        return;
      }
      const transfer = new DataTransfer();
      Array.from(files).slice(0, 5).forEach(function (file) {
        transfer.items.add(file);
      });
      fileInput.files = transfer.files;
      showPicked(fileInput.files);
    });
  }

  if (uploadForm && fileInput) {
    uploadForm.addEventListener('submit', function (event) {
      if (!fileInput.files || fileInput.files.length === 0) {
        event.preventDefault();
        toast('请先选择图片');
      }
    });
  }

  if (selectAll) {
    refreshSelection();
  }
})();

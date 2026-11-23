(() => {
  'use strict';

  const body = document.body;
  let loader = document.getElementById('cream-loader');
  let loaderLabel = document.getElementById('cream-loader-label');
  let loaderDetail = document.getElementById('cream-loader-detail');
  let hideTimer = 0;
  let inFlight = false;

  const refreshLoader = () => {
    loader = document.getElementById('cream-loader');
    loaderLabel = document.getElementById('cream-loader-label');
    loaderDetail = document.getElementById('cream-loader-detail');
  };

  const showLoader = (label, detail) => {
    refreshLoader();
    if (!loader) return;
    window.clearTimeout(hideTimer);
    loader.hidden = false;
    loader.classList.remove('is-hiding');
    if (loaderLabel) loaderLabel.textContent = label || '正在处理…';
    if (loaderDetail) loaderDetail.textContent = detail || 'MINI-BLOGPLANNER';
  };

  const hideLoader = (immediate) => {
    refreshLoader();
    if (!loader || loader.hidden) return;
    if (immediate === true) {
      loader.hidden = true;
      loader.classList.remove('is-hiding');
      return;
    }
    loader.classList.add('is-hiding');
    hideTimer = window.setTimeout(() => {
      if (loader) loader.hidden = true;
    }, 260);
  };

  const toast = (message, kind) => {
    const el = document.createElement('div');
    el.className = 'cream-toast' + (kind === 'error' ? ' is-error' : '');
    el.setAttribute('role', 'status');
    el.textContent = message;
    document.body.appendChild(el);
    requestAnimationFrame(() => el.classList.add('is-visible'));
    window.setTimeout(() => {
      el.classList.remove('is-visible');
      window.setTimeout(() => el.remove(), 240);
    }, 2600);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hideLoader, { once: true });
  } else {
    hideLoader();
  }
  window.addEventListener('load', hideLoader);
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      document.documentElement.setAttribute('data-cream-nav', 'back_forward');
      hideLoader(true);
    } else {
      hideLoader();
    }
  });
  window.addEventListener('pagehide', () => {
    hideLoader(true);
  });
  window.setTimeout(hideLoader, 4000);

  const mobileNav = document.querySelector('.nav-mobile');
  if (mobileNav instanceof HTMLDetailsElement) {
    const summary = mobileNav.querySelector('summary');
    const closeNav = () => {
      if (!mobileNav.open) return;
      mobileNav.open = false;
    };
    mobileNav.addEventListener('toggle', () => {
      const open = mobileNav.open;
      document.body.classList.toggle('nav-open', open);
      if (summary) summary.setAttribute('aria-label', open ? '收起导航菜单' : '打开导航菜单');
    });
    document.addEventListener('click', (event) => {
      if (!mobileNav.open) return;
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (target.closest('.nav-mobile-panel') || target.closest('.nav-mobile summary')) return;
      closeNav();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || !mobileNav.open) return;
      closeNav();
      if (summary instanceof HTMLElement) summary.focus();
    });
    mobileNav.querySelectorAll('.nav-mobile-panel a').forEach((link) => {
      link.addEventListener('click', closeNav);
    });
    const desktopNav = window.matchMedia('(min-width: 721px)');
    desktopNav.addEventListener('change', (event) => {
      if (event.matches) closeNav();
    });
  }

  if (!body.classList.contains('admin-body')) return;

  const isHtml = (text, type) => type.includes('text/html') || /^\s*<(?:!doctype|html)/i.test(text);
  const isLoginUrl = (url) => /\/admin\/login(?:\.html)?(?:[?#]|$)/.test(url);

  const parse = (html) => new DOMParser().parseFromString(html, 'text/html');

  const applyResponse = (html, status, url, form) => {
    const type = 'text/html';
    const doc = parse(html);
    const selector = form.dataset.ajaxTarget || '#admin-main';
    const full = form.hasAttribute('data-ajax-full') || selector === 'body' || isLoginUrl(url);
    let contentRoot = document.body;

    if (full) {
      document.body.innerHTML = doc.body.innerHTML;
      document.body.className = doc.body.className;
      document.title = doc.title;
      refreshLoader();
    } else {
      const current = document.querySelector(selector);
      const incoming = doc.querySelector(selector);
      contentRoot = current;
      if (current && incoming) {
        current.innerHTML = incoming.innerHTML;
        current.className = incoming.className;
      } else if (current) {
        current.innerHTML = doc.body.innerHTML;
      } else {
        toast('页面结构已变化，请刷新后重试。', 'error');
        hideLoader();
        return;
      }
      if (doc.title) document.title = doc.title;
    }

    document.dispatchEvent(new CustomEvent('admin:content-replaced', {
      detail: { root: contentRoot, full: full }
    }));
    if (window.AdminSelect && typeof window.AdminSelect.enhance === 'function') {
      window.AdminSelect.enhance(contentRoot);
    }
    if (window.AdminFilters && typeof window.AdminFilters.enhance === 'function') {
      window.AdminFilters.enhance(contentRoot);
    }

    if (full && isLoginUrl(url)) {
      try {
        history.pushState({}, '', url);
      } catch (error) {
        // 登录跳转失败时保留原地址，用户仍可手动刷新。
      }
      hideLoader();
      toast(/\/admin\/logout/.test(form.action || '') ? '已退出登录。' : '登录状态已失效，请重新登录。', /\/admin\/logout/.test(form.action || '') ? undefined : 'error');
      return;
    }

    let historyUrl = url;
    const resultUrl = form.dataset.ajaxResultUrl || '';
    if (status < 400 && resultUrl !== '') {
      try {
        const next = new URL(resultUrl, location.href);
        if (next.origin === location.origin) historyUrl = next.href;
      } catch (error) {
        // 非法回跳地址保留服务端返回的地址。
      }
    }
    if (historyUrl && status < 400) {
      try {
        history.pushState({}, '', historyUrl);
      } catch (error) {
        // 跨源或非法 URL 不写入历史，交给浏览器处理。
      }
    }
    hideLoader();
    if (status >= 400) {
      toast('请检查表单内容。', 'error');
    } else if (!form.hasAttribute('data-ajax-silent')) {
      toast('已完成。');
    }
  };

  const submitWithFetch = async (form) => {
    const method = (form.method || 'GET').toUpperCase();
    const data = new FormData(form);
    let requestUrl = form.action;
    let options = { method, credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } };

    if (method === 'GET') {
      const params = new URLSearchParams();
      data.forEach((value, key) => { if (typeof value === 'string') params.append(key, value); });
      const url = new URL(form.action, location.href);
      url.search = params.toString();
      requestUrl = url.toString();
      options = { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } };
    } else {
      options.body = data;
    }

    showLoader(method === 'GET' ? '正在筛选…' : '正在保存…', 'MINI-BLOGPLANNER');
    try {
      const response = await fetch(requestUrl, options);
      const text = await response.text();
      if (isHtml(text, response.headers.get('content-type') || '')) {
        applyResponse(text, response.status, response.url, form);
      } else {
        toast(text.trim() || '请求失败。', 'error');
        hideLoader();
      }
    } catch (error) {
      toast('网络连接失败，请稍后重试。', 'error');
      hideLoader();
    }
  };

  const submitWithXhr = (form) => new Promise((resolve, reject) => {
    const data = new FormData(form);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'text/html');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    showLoader('正在上传…', '0%');
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        const percent = Math.round((event.loaded / event.total) * 100);
        showLoader('正在上传…', percent + '%');
      }
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 400) {
        applyResponse(xhr.responseText, xhr.status, xhr.responseURL, form);
        resolve();
      } else {
        const type = xhr.getResponseHeader('content-type') || '';
        if (isHtml(xhr.responseText, type)) {
          applyResponse(xhr.responseText, xhr.status, xhr.responseURL, form);
        } else {
          toast(xhr.responseText.trim() || '上传失败。', 'error');
          hideLoader();
        }
        resolve();
      }
    };
    xhr.onerror = () => {
      toast('上传失败，请检查网络。', 'error');
      hideLoader();
      reject(new Error('upload failed'));
    };
    xhr.send(data);
  });

  document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;
    const shell = input.closest('.cream-file');
    if (!shell) return;
    const name = shell.querySelector('[data-file-name]');
    const file = input.files && input.files[0];
    if (name) name.textContent = file ? file.name : '还没有选择文件';
    shell.classList.toggle('has-file', Boolean(file));
  });

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form');
    if (!form || event.defaultPrevented || form.dataset.ajax === 'off') return;
    if (form.target && form.target !== '_self') return;
    event.preventDefault();
    if (inFlight) return;
    inFlight = true;
    const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
    buttons.forEach((button) => { button.disabled = true; });

    const isUpload = form.enctype === 'multipart/form-data' || form.querySelector('input[type="file"]') !== null;
    const done = () => {
      inFlight = false;
      buttons.forEach((button) => { button.disabled = false; });
    };

    if (isUpload) {
      submitWithXhr(form).then(done, done);
    } else {
      submitWithFetch(form).finally(done);
    }
  });
})();

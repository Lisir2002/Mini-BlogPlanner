// 版本页负责确认、提交和短轮询；真正的下载、校验与切换由 root 助手完成。
(() => {
  const panel = document.getElementById('update-panel');
  if (!panel) return;

  const dialog = document.getElementById('update-dialog');
  const form = document.getElementById('update-apply-form');
  const submitButton = form?.querySelector('button[type="submit"]');
  const cancelButton = dialog?.querySelector('[data-update-cancel]');
  const flash = document.getElementById('update-flash');
  const statusUrl = panel.dataset.statusUrl || '/admin/updates/status';
  const regions = ['current', 'action', 'progress', 'details'];
  const steps = {
    queued: ['排队', 6],
    checking: ['检查发布', 16],
    download: ['下载发布包', 30],
    verify: ['校验签名', 46],
    install: ['安装文件', 60],
    plan: ['生成计划', 74],
    apply: ['备份并切换', 90],
    done: ['更新完成', 100],
    failed: ['更新失败', 90]
  };
  let polling = false;
  let finishing = false;
  let emptyPolls = 0;
  let refreshAttempts = 0;
  let pollTimer = 0;

  // 局部替换会换掉进度节点，所以每次从当前 DOM 重新取，不能缓存旧引用。
  const ui = () => ({
    statusLabel: document.getElementById('update-job-status'),
    stepLabel: document.getElementById('update-step-label'),
    progressBar: document.getElementById('update-progress-bar'),
    message: document.getElementById('update-progress-message'),
    errorBox: document.getElementById('update-error')
  });

  const showFlash = (text, tone = 'good') => {
    if (!flash) return;
    flash.textContent = text;
    flash.className = 'update-flash is-' + tone;
    flash.hidden = false;
  };

  const hideFlash = () => {
    if (!flash) return;
    flash.textContent = '';
    flash.className = 'update-flash is-hidden';
    flash.hidden = true;
  };

  const setStatus = (text, tone = '') => {
    const { statusLabel } = ui();
    if (!statusLabel) return;
    statusLabel.textContent = text;
    statusLabel.className = 'update-job-status' + (tone ? ' ' + tone : '');
  };

  const setStep = (job) => {
    const { stepLabel, progressBar, message, errorBox } = ui();
    const key = String(job?.step || 'queued');
    const [label, percent] = steps[key] || ['处理中', 50];
    if (stepLabel) stepLabel.textContent = label;
    if (progressBar) progressBar.style.width = String(percent) + '%';
    if (message && typeof job?.message === 'string' && job.message) message.textContent = job.message;
    if (errorBox) {
      const hasError = job?.status === 'failed' && job?.error;
      errorBox.textContent = hasError ? String(job.error) : '';
      errorBox.classList.toggle('is-hidden', !hasError);
    }
  };

  const openDialog = () => {
    if (!dialog) return;
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = '开始更新';
    }
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      dialog.setAttribute('open', 'open');
    }
  };

  // 操作区会被局部替换，事件挂在不会变的 panel 上，替换后按钮仍然可用。
  panel.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-update-open]');
    if (!trigger || trigger.disabled) return;
    openDialog();
  });

  cancelButton?.addEventListener('click', () => dialog?.close());
  dialog?.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });

  const errorFromPage = (html) => {
    try {
      const documentNext = new DOMParser().parseFromString(html, 'text/html');
      const node = documentNext.querySelector('.alert.bad, .alert, .update-error');
      return node?.textContent?.trim() || '提交更新失败，请刷新后重试。';
    } catch (error) {
      return '提交更新失败，请刷新后重试。';
    }
  };

  const replaceRegions = (documentNext) => {
    let changed = 0;
    for (const name of regions) {
      const current = document.querySelector(`[data-update-region="${name}"]`);
      const next = documentNext.querySelector(`[data-update-region="${name}"]`);
      if (!current || !next) continue;

      if (name === 'details') {
        const wasOpen = Array.from(current.querySelectorAll('details')).map((item) => item.open);
        current.replaceWith(next);
        Array.from(next.querySelectorAll('details')).forEach((item, index) => {
          if (typeof wasOpen[index] === 'boolean') item.open = wasOpen[index];
        });
      } else {
        current.replaceWith(next);
      }

      next.classList.add('is-refreshed');
      window.setTimeout(() => next.classList.remove('is-refreshed'), 700);
      changed += 1;
    }

    const nextPanel = documentNext.querySelector('#update-panel');
    if (nextPanel) panel.dataset.panelStatus = nextPanel.dataset.panelStatus || 'idle';
    return changed;
  };

  // 只替换会变化的版本卡片、操作区、任务进度和版本构成，保留页面位置与表单状态。
  const refreshRegions = async () => {
    try {
      const response = await fetch('/admin/updates.html', {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'text/html' }
      });
      if (!response.ok) throw new Error('page ' + response.status);
      const documentNext = new DOMParser().parseFromString(await response.text(), 'text/html');
      return replaceRegions(documentNext);
    } catch (error) {
      return 0;
    }
  };

  const finishUpdate = async (job) => {
    const changed = await refreshRegions();
    if (changed > 0) {
      showFlash('更新完成：' + String(job?.message || '站点已恢复访问。'), 'good');
      return;
    }

    refreshAttempts += 1;
    const { message } = ui();
    if (refreshAttempts >= 6) {
      if (message) message.textContent = '更新已完成，但版本信息同步失败；可点“检查更新”后重试。';
      showFlash('更新完成：' + String(job?.message || '站点已恢复访问。'), 'good');
      return;
    }

    if (message) message.textContent = '更新已完成，正在同步变化的版本信息…';
    window.setTimeout(() => finishUpdate(job), 1800);
  };

  const schedule = (delay = 1200) => {
    window.clearTimeout(pollTimer);
    pollTimer = window.setTimeout(poll, delay);
  };

  const poll = async () => {
    if (polling || finishing) return;
    window.clearTimeout(pollTimer);
    pollTimer = 0;
    polling = true;
    let delay = 1200;
    try {
      const response = await fetch(statusUrl, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      if (!response.ok) throw new Error('status ' + response.status);
      const data = await response.json();
      const job = data?.job || null;

      if (!job) {
        emptyPolls += 1;
        const { message } = ui();
        if (message) message.textContent = '正在等待更新助手接管任务…';
        setStatus('排队中', '');
        if (emptyPolls >= 8) {
          finishing = true;
          setStatus('未启动', 'bad');
          if (message) message.textContent = '没有发现更新任务，请刷新页面后重试。';
          showFlash('没有发现更新任务，更新未启动。', 'bad');
          return;
        }
      } else {
        emptyPolls = 0;
        setStep(job);
        if (job.status === 'done') {
          setStatus('已完成', 'good');
          const { progressBar } = ui();
          if (progressBar) progressBar.style.width = '100%';
          showFlash('更新完成：' + String(job.message || '站点已恢复访问。'), 'good');
          finishing = true;
          window.setTimeout(() => finishUpdate(job), 250);
          return;
        }
        if (job.status === 'failed') {
          setStatus('失败', 'bad');
          setStep(job);
          showFlash('更新失败：' + String(job.error || job.message || '请查看任务详情。'), 'bad');
          finishing = true;
          return;
        }
        setStatus(job.status === 'queued' ? '排队中' : '更新中', '');
      }
    } catch (error) {
      const { message } = ui();
      if (message) message.textContent = '状态读取失败，正在自动重试…';
      setStatus('重试中', '');
      delay = 1800;
    } finally {
      polling = false;
    }
    if (!finishing) schedule(delay);
  };

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form || (submitButton && submitButton.disabled)) return;

    finishing = false;
    refreshAttempts = 0;
    emptyPolls = 0;
    hideFlash();

    const originalLabel = submitButton?.textContent || '开始更新';
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = '正在提交…';
    }

    const { stepLabel, progressBar, message } = ui();
    setStatus('排队中', '');
    if (stepLabel) stepLabel.textContent = '启动助手';
    if (progressBar) progressBar.style.width = '6%';
    if (message) message.textContent = '已提交，正在启动更新助手…';

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'text/html' }
      });

      if (response.status >= 500) {
        dialog?.close();
        panel.dataset.panelStatus = 'running';
        if (message) message.textContent = '提交结果未知，正在自动确认任务状态…';
        setStatus('确认中', '');
        schedule(0);
        return;
      }

      if (!response.ok) {
        const text = await response.text();
        const error = errorFromPage(text);
        setStatus('失败', 'bad');
        if (message) message.textContent = error;
        showFlash(error, 'bad');
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalLabel;
        }
        return;
      }

      dialog?.close();
      const html = await response.text();
      if (html) {
        try {
          replaceRegions(new DOMParser().parseFromString(html, 'text/html'));
        } catch (error) {
          // 页面结构刷新失败不影响后面的状态轮询。
        }
      }
      panel.dataset.panelStatus = 'running';
      setStatus('排队中', '');
      schedule(0);
    } catch (error) {
      // 切换版本时 PHP-FPM 可能短暂重启；请求断开不代表任务没启动，继续查状态。
      dialog?.close();
      panel.dataset.panelStatus = 'running';
      if (message) message.textContent = '提交结果未知，正在自动确认任务状态…';
      setStatus('确认中', '');
      schedule(0);
    }
  });

  const wake = () => {
    if (document.visibilityState !== 'visible' || finishing || polling) return;
    if ((panel.dataset.panelStatus || '') !== 'running') return;
    schedule(0);
  };

  document.addEventListener('visibilitychange', wake);
  window.addEventListener('pageshow', wake);
  window.addEventListener('focus', wake);
  window.addEventListener('online', wake);

  if (!['running', 'failed'].includes(panel.dataset.panelStatus || '')) return;
  schedule(0);
})();

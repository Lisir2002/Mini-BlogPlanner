// 版本页只负责确认和轮询；真正的下载、校验与切换由 root 助手完成。
(() => {
  const panel = document.getElementById('update-panel');
  if (!panel) return;

  const dialog = document.getElementById('update-dialog');
  const openButton = panel.querySelector('[data-update-open]');
  const cancelButton = dialog?.querySelector('[data-update-cancel]');
  const form = document.getElementById('update-apply-form');
  const submitButton = form?.querySelector('button[type="submit"]');
  const statusUrl = panel.dataset.statusUrl || '/admin/updates/status';
  const statusLabel = document.getElementById('update-job-status');
  const stepLabel = document.getElementById('update-step-label');
  const progressBar = document.getElementById('update-progress-bar');
  const message = document.getElementById('update-progress-message');
  const errorBox = document.getElementById('update-error');
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
  let reloading = false;
  let emptyPolls = 0;

  if (openButton && dialog) {
    openButton.addEventListener('click', () => {
      if (typeof dialog.showModal === 'function') {
        dialog.showModal();
      } else {
        dialog.setAttribute('open', 'open');
      }
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
  }
  cancelButton?.addEventListener('click', () => dialog?.close());
  form?.addEventListener('submit', () => {
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = '正在排队…';
    }
  });

  if (!['running', 'failed'].includes(panel.dataset.panelStatus || '')) return;

  const setStatus = (text, tone = '') => {
    if (!statusLabel) return;
    statusLabel.textContent = text;
    statusLabel.className = 'update-job-status' + (tone ? ' ' + tone : '');
  };

  const setStep = (job) => {
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

  const poll = async () => {
    if (polling || reloading) return;
    polling = true;
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
        if (message && emptyPolls < 5) message.textContent = '正在等待更新助手接管任务…';
        setStatus('排队中', '');
      } else {
        emptyPolls = 0;
        setStep(job);
        if (job.status === 'done') {
          setStatus('已完成', 'good');
          if (progressBar) progressBar.style.width = '100%';
          reloading = true;
          window.setTimeout(() => window.location.reload(), 1200);
          return;
        }
        if (job.status === 'failed') {
          setStatus('失败', 'bad');
          setStep(job);
          return;
        }
        setStatus('更新中', '');
      }
    } catch (error) {
      if (message) message.textContent = '状态读取失败，正在重试…';
    } finally {
      polling = false;
    }
    if (!reloading) window.setTimeout(poll, 1800);
  };

  window.setTimeout(poll, 600);
})();

(() => {
  'use strict';

  const widget = document.querySelector('[data-reaction-widget]');
  if (!widget) return;

  const forms = Array.from(widget.querySelectorAll('[data-reaction-form]'));
  const buttons = Array.from(widget.querySelectorAll('[data-reaction-button]'));
  const likeCount = widget.querySelector('[data-reaction-count="likes"]');
  const dislikeCount = widget.querySelector('[data-reaction-count="dislikes"]');
  const status = widget.querySelector('[data-reaction-status]');
  if (forms.length === 0 || buttons.length === 0 || !likeCount || !dislikeCount || !status) return;

  let current = {
    likes: Number.parseInt(likeCount.textContent || '0', 10) || 0,
    dislikes: Number.parseInt(dislikeCount.textContent || '0', 10) || 0,
    mine: 0,
  };
  buttons.forEach((button) => {
    if (button.getAttribute('aria-pressed') === 'true') {
      current.mine = Number.parseInt(button.getAttribute('data-reaction-choice') || '0', 10) || 0;
    }
  });
  let inFlight = false;

  const bump = (button) => {
    button.classList.remove('is-bumping');
    void button.offsetWidth;
    button.classList.add('is-bumping');
    window.setTimeout(() => button.classList.remove('is-bumping'), 260);
  };

  const paint = (state, changedChoice) => {
    current = {
      likes: Math.max(0, Number(state.likes) || 0),
      dislikes: Math.max(0, Number(state.dislikes) || 0),
      mine: Number(state.mine) || 0,
    };
    likeCount.textContent = String(current.likes);
    dislikeCount.textContent = String(current.dislikes);

    buttons.forEach((button) => {
      const choice = Number.parseInt(button.getAttribute('data-reaction-choice') || '0', 10) || 0;
      const active = current.mine === choice;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      const form = button.closest('[data-reaction-form]');
      const value = form ? form.querySelector('[data-reaction-value]') : null;
      if (value instanceof HTMLInputElement) {
        value.value = active ? '0' : String(choice);
      }
      if (choice === changedChoice) bump(button);
    });
  };

  const setStatus = (message, isError) => {
    status.textContent = message;
    status.hidden = message === '';
    status.classList.toggle('is-error', isError === true);
  };

  const optimistic = (choice) => {
    const previous = { ...current };
    const next = { ...current };
    if (previous.mine === 1) next.likes = Math.max(0, next.likes - 1);
    if (previous.mine === -1) next.dislikes = Math.max(0, next.dislikes - 1);
    next.mine = previous.mine === choice ? 0 : choice;
    if (next.mine === 1) next.likes += 1;
    if (next.mine === -1) next.dislikes += 1;
    paint(next, next.mine === 0 ? previous.mine : next.mine);

    return { previous, next };
  };

  const setBusy = (busy) => {
    inFlight = busy;
    buttons.forEach((button) => {
      button.disabled = busy;
      button.setAttribute('aria-busy', busy ? 'true' : 'false');
    });
  };

  forms.forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (inFlight) return;

      const button = form.querySelector('[data-reaction-button]');
      if (!(button instanceof HTMLButtonElement)) return;
      const choice = Number.parseInt(button.getAttribute('data-reaction-choice') || '0', 10);
      if (choice !== 1 && choice !== -1) return;

      const { previous } = optimistic(choice);
      setStatus('', false);
      setBusy(true);

      const payload = new FormData(form);
      payload.set('value', String(current.mine));

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: payload,
        });
        const body = await response.json().catch(() => null);
        if (!response.ok || !body || !body.data) {
          if (response.status === 419) throw new Error('页面已过期，请刷新后再试。');
          if (response.status === 429) throw new Error('慢一点，稍后再试。');
          throw new Error((body && body.detail) || '刚才没记上，请再试一次。');
        }
        paint(body.data, 0);
        setStatus(body.message || '已记下。', false);
      } catch (error) {
        paint(previous, 0);
        setStatus(error instanceof Error ? error.message : '刚才没记上，请再试一次。', true);
      } finally {
        setBusy(false);
      }
    });
  });
})();

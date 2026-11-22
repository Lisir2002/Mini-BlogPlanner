'use strict';

(function () {
  var button = document.querySelector('[data-copy-token]');
  if (button) {
    button.addEventListener('click', function () {
      var id = button.getAttribute('data-copy-token');
      var target = id ? document.getElementById(id) : null;
      if (!target) {
        return;
      }
      var value = target.textContent || '';
      var done = function () {
        var original = button.innerHTML;
        button.textContent = '已复制';
        window.setTimeout(function () { button.innerHTML = original; }, 1600);
      };

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(done).catch(function () { selectFallback(target, done); });
      } else {
        selectFallback(target, done);
      }
    });
  }

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-confirm') || '确定继续吗？')) {
        event.preventDefault();
      }
    });
  });

  function selectFallback(target, done) {
    var range = document.createRange();
    range.selectNodeContents(target);
    var selection = window.getSelection();
    if (selection) {
      selection.removeAllRanges();
      selection.addRange(range);
    }
    try {
      document.execCommand('copy');
      done();
    } catch (error) {
      // 浏览器拒绝自动复制时保留选中状态，用户仍可手动复制。
    }
  }
})();

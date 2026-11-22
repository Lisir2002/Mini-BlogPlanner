(function () {
  'use strict';

  function setup() {
    var bar = document.querySelector('[data-comment-bulk-bar]');
    if (!bar) {
      return;
    }

    var form = document.getElementById('comment-bulk-form');
    var selectAll = bar.querySelector('[data-comment-select-all]');
    var count = bar.querySelector('[data-comment-selected-count]');
    var boxes = Array.prototype.slice.call(bar.parentElement.querySelectorAll('[data-comment-checkbox]'));
    var buttons = Array.prototype.slice.call(bar.querySelectorAll('[data-comment-bulk-button]'));
    var deleteButton = bar.querySelector('[data-comment-bulk-delete]');

    if (!form || boxes.length === 0) {
      return;
    }

    function selected() {
      return boxes.filter(function (box) { return box.checked; });
    }

    function refresh() {
      var total = selected().length;
      if (count) {
        count.textContent = '已选 ' + total + ' 条';
      }
      if (selectAll) {
        selectAll.checked = total === boxes.length;
        selectAll.indeterminate = total > 0 && total < boxes.length;
      }
      buttons.forEach(function (button) {
        button.disabled = total === 0;
      });
    }

    boxes.forEach(function (box) {
      box.addEventListener('change', refresh);
    });

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        boxes.forEach(function (box) { box.checked = selectAll.checked; });
        refresh();
      });
    }

    if (deleteButton) {
      deleteButton.addEventListener('click', function (event) {
        if (selected().length === 0) {
          event.preventDefault();
          return;
        }
        if (!window.confirm('确认删除选中的留言？相关回复也会一起删除。')) {
          event.preventDefault();
        }
      });
    }

    refresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setup);
  } else {
    setup();
  }
})();

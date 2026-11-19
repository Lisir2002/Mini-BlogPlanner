<div id="cream-loader" role="status" aria-live="polite" aria-label="页面加载中">
  <div class="cream-loader-card">
    <span class="cream-loader-mark" aria-hidden="true"></span>
    <strong id="cream-loader-label">正在翻开手账…</strong>
    <small id="cream-loader-detail">MINI-BLOGPLANNER</small>
  </div>
</div>
<script>
  window.setTimeout(function () {
    var loader = document.getElementById('cream-loader');
    if (!loader || loader.hidden) return;
    loader.classList.add('is-hiding');
    window.setTimeout(function () { loader.hidden = true; }, 260);
  }, 1500);
</script>

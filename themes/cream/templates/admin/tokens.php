<?php
$tokens = is_array($tokens ?? null) ? $tokens : [];
$scopes = is_array($scopes ?? null) ? $scopes : [];
$expiryOptions = is_array($expiryOptions ?? null) ? $expiryOptions : [];
$created = is_array($created ?? null) ? $created : null;
$total = (int) ($total ?? 0);
$activeCount = (int) ($activeCount ?? 0);
$createdCount = (int) ($createdCount ?? 0);
$pager_page = $page ?? 1;
$pager_pages = $pages ?? 1;
$pager_total = $total;
$pager_prev = $prevUrl ?? null;
$pager_next = $nextUrl ?? null;
$pager_links = $pageLinks ?? [];
$pager_label = 'API 令牌分页';
?>
<section class="token-page">
  <header class="token-hero">
    <span class="tape tape-mint token-tape" aria-hidden="true"></span>
    <div class="token-hero-copy">
      <span class="eyebrow"><?= cream_icon('plug', 'icon-sm') ?> API TOKENS</span>
      <h2>给接口一把<br><span class="marker">可收回的钥匙。</span></h2>
      <p>令牌只用于管理接口。创建时显示一次明文，之后只保留前缀和哈希，撤销后立即失效。</p>
    </div>
    <aside class="token-hero-note">
      <span>调用方式</span>
      <code>Authorization: Bearer &lt;token&gt;</code>
      <a href="/admin/api-docs.html">查看接口文档 <?= cream_icon('arrow-right', 'icon-xs') ?></a>
    </aside>
  </header>

  <div class="token-stats">
    <article class="token-stat"><span>全部令牌</span><strong><?= number_format($total) ?></strong><small>创建后长期保留记录</small></article>
    <article class="token-stat"><span>当前有效</span><strong><?= number_format($activeCount) ?></strong><small>未撤销且未过期</small></article>
    <article class="token-stat"><span>累计签发</span><strong><?= number_format($createdCount) ?></strong><small>来自审计事件</small></article>
  </div>

<?php if ($created !== null): ?>
  <section class="token-secret" role="status" aria-live="polite">
    <div class="token-secret-head">
      <span class="token-secret-icon"><?= cream_icon('lock', 'icon-sm') ?></span>
      <div>
        <strong>“<?= cream_e((string) $created['name']) ?>” 已创建</strong>
        <p>这是唯一一次显示完整令牌。复制后妥善保存，离开本页后无法再次查看。</p>
      </div>
    </div>
    <div class="token-secret-value">
      <code id="new-api-token"><?= cream_e((string) $created['token']) ?></code>
      <button class="button button-primary" type="button" data-copy-token="new-api-token"><?= cream_icon('link') ?> 复制令牌</button>
    </div>
    <p class="token-secret-hint"><?= cream_icon('shield', 'icon-xs') ?> 不要把令牌写进前端代码、公开仓库或截图。</p>
  </section>
<?php endif; ?>

  <div class="token-grid">
    <section class="token-panel token-list-panel">
      <header class="token-panel-head">
        <div><span class="eyebrow">ISSUED</span><h3>令牌列表</h3></div>
        <p>固定 10 条</p>
      </header>

    <?php if ($tokens === []): ?>
      <div class="token-empty">
        <span><?= cream_icon('plug') ?></span>
        <h4>还没有 API 令牌</h4>
        <p>在右侧创建第一枚，按需要勾选作用域。</p>
      </div>
    <?php else: ?>
      <div class="token-list">
<?php foreach ($tokens as $token): ?>
<?php $tokenStatus = (string) ($token['status'] ?? 'active'); ?>
        <article class="token-row token-row--<?= cream_e($tokenStatus) ?>">
          <div class="token-row-main">
            <div class="token-row-title">
              <strong><?= cream_e((string) $token['name']) ?></strong>
              <span class="token-status token-status--<?= cream_e($tokenStatus) ?>"><?= $tokenStatus === 'active' ? '有效' : ($tokenStatus === 'revoked' ? '已撤销' : '已过期') ?></span>
            </div>
            <code class="token-prefix"><?= cream_e((string) $token['prefix']) ?>…</code>
            <div class="token-scopes">
<?php foreach (($token['scope_labels'] ?? []) as $label): ?>
              <span><?= cream_e((string) $label) ?></span>
<?php endforeach; ?>
            </div>
            <dl class="token-meta">
              <div><dt>创建</dt><dd><?= $token['created_at'] ? cream_e(cream_date((string) $token['created_at'])) : '—' ?></dd></div>
              <div><dt>最近使用</dt><dd><?= $token['last_used_at'] ? cream_e(cream_date((string) $token['last_used_at'])) : '尚未使用' ?></dd></div>
              <div><dt>有效期</dt><dd><?= $token['expires_at'] ? cream_e(cream_date((string) $token['expires_at'])) : '长期有效' ?></dd></div>
            </dl>
          </div>
<?php if ((int) $token['id'] > 0 && $token['revoked_at'] === null): ?>
          <form method="post" action="/admin/tokens/<?= (int) $token['id'] ?>/revoke.html" class="token-revoke" data-confirm="撤销后，使用这枚令牌的接口会立即返回 401。确定撤销吗？">
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <button class="admin-button ghost" type="submit"><?= cream_icon('close', 'icon-xs') ?> 撤销</button>
          </form>
<?php endif; ?>
        </article>
<?php endforeach; ?>
      </div>
      <?php require dirname(__DIR__) . '/partials/page-pager.php'; ?>
    <?php endif; ?>
    </section>

    <aside class="token-panel token-create-panel">
      <header class="token-panel-head">
        <div><span class="eyebrow">NEW TOKEN</span><h3>创建令牌</h3></div>
      </header>
      <form method="post" action="/admin/tokens.html" class="token-form">
        <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
        <div class="token-field">
          <label for="token-name">名称</label>
          <input id="token-name" name="name" type="text" maxlength="60" placeholder="例如：服务器监控" required>
          <small>写清用途，撤销时更容易判断。</small>
        </div>
        <fieldset class="token-field token-scope-field">
          <legend>作用域</legend>
<?php foreach ($scopes as $scope => $label): ?>
          <label class="token-check">
            <input type="checkbox" name="scopes[]" value="<?= cream_e((string) $scope) ?>">
            <span><strong><?= cream_e((string) $label) ?></strong><small><?= cream_e((string) $scope) ?></small></span>
          </label>
<?php endforeach; ?>
        </fieldset>
        <div class="token-field">
          <label for="token-expiry">有效期</label>
          <select id="token-expiry" name="expires">
<?php foreach ($expiryOptions as $value => $label): ?>
            <option value="<?= cream_e((string) $value) ?>"<?= (string) $value === '90' ? ' selected' : '' ?>><?= cream_e((string) $label) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <button class="button button-primary token-submit" type="submit"><?= cream_icon('lock') ?> 生成令牌</button>
      </form>
      <p class="token-create-note"><?= cream_icon('shield', 'icon-xs') ?> 创建动作会写入审计日志，只记录前缀，不记录完整令牌。</p>
    </aside>
  </div>

  <footer class="token-boundary">
    <span><?= cream_icon('shield') ?></span>
    <div>
      <strong>令牌边界</strong>
      <p>公开文章、搜索、标签与站点状态保持匿名可读；管理接口必须携带 Bearer 令牌，且只允许访问已勾选的作用域。</p>
    </div>
  </footer>
</section>

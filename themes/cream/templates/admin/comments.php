<?php
$comments = is_array($comments ?? null) ? $comments : [];
$total = (int) ($total ?? count($comments));
$counts = is_array($counts ?? null) ? $counts : [];
$pending = (int) ($counts['pending'] ?? ($pending ?? 0));
$approved = (int) ($counts['approved'] ?? 0);
$spam = (int) ($counts['spam'] ?? 0);
$allCount = (int) ($counts['all'] ?? $total);
$status = (string) ($status ?? '');
$q = (string) ($q ?? '');
$postId = (int) ($postId ?? 0);
$postOptions = is_array($postOptions ?? null) ? $postOptions : [];
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$prevUrl = $prevUrl ?? null;
$nextUrl = $nextUrl ?? null;
$baseUrl = (string) ($baseUrl ?? '/admin/comments.html');
$ok = (string) ($ok ?? '');
$tabs = [
    '' => ['全部', $allCount],
    'pending' => ['待审核', $pending],
    'approved' => ['已通过', $approved],
    'spam' => ['垃圾', $spam],
];
$tabUrl = static function (string $key) use ($baseUrl, $q, $postId): string {
    $params = [];
    if ($key !== '') {
        $params['status'] = $key;
    }
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($postId > 0) {
        $params['post_id'] = $postId;
    }

    return $baseUrl . ($params === [] ? '' : '?' . http_build_query($params));
};
$okMessages = [
    'approved' => '留言已通过，已经显示在文章页。',
    'pending' => '留言已撤回待审队列。',
    'spam' => '留言已标为垃圾，不再公开展示。',
    'delete' => '留言已删除，关联回复一并移除。',
    'bulk-approved' => '选中的留言已通过。',
    'bulk-pending' => '选中的留言已撤回待审。',
    'bulk-spam' => '选中的留言已标为垃圾。',
    'bulk-delete' => '选中的留言已删除。',
    'bulk-empty' => '没有选中任何留言，请先勾选。',
];
$tone = static fn (string $name, int $id): string => ['butter', 'mint', 'coral', 'sky'][abs((int) crc32($name . $id)) % 4];
$excerpt = static function (string $text, int $limit = 90): string {
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));

    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
};
?>
<section class="admin-page comment-admin-page">
  <header class="comment-inbox-hero">
    <div class="comment-inbox-hero-copy">
      <span class="eyebrow"><?= cream_icon('comment', 'icon-sm') ?> COMMENTS · INBOX</span>
      <h2>把留言一张张看过。</h2>
      <p>先处理待审核，再回看已通过和垃圾。通过后的留言才会出现在文章页，删除父留言会一并移除回复。</p>
    </div>
    <aside class="comment-inbox-hero-note" aria-label="留言概览">
      <span>待审核</span>
      <strong><?= number_format($pending) ?></strong>
      <small>共 <?= number_format($allCount) ?> 条留言</small>
      <?php if ($pending > 0): ?><a data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="<?= cream_e($tabUrl('pending')) ?>">进入待审队列 <?= cream_icon('arrow-right', 'icon-xs') ?></a><?php endif; ?>
    </aside>
  </header>

  <div class="comment-inbox-tabs" role="tablist" aria-label="留言状态">
    <?php foreach ($tabs as $key => [$label, $count]): ?>
    <a class="comment-inbox-tab<?= $status === $key ? ' is-current' : '' ?>" data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="<?= cream_e($tabUrl($key)) ?>" role="tab" aria-selected="<?= $status === $key ? 'true' : 'false' ?>">
      <span><?= cream_e($label) ?></span><strong><?= number_format((int) $count) ?></strong>
    </a>
    <?php endforeach; ?>
    <span class="comment-inbox-tab-note"><?= cream_icon('shield', 'icon-xs') ?> 默认先审后发</span>
  </div>

  <div class="comment-inbox-filterbar<?= $q !== '' || $postId > 0 ? ' has-filter' : '' ?>">
    <form class="comment-inbox-filter" method="get" action="<?= cream_e($baseUrl) ?>" role="search" data-ajax-silent>
      <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= cream_e($status) ?>"><?php endif; ?>
      <label class="comment-inbox-search">
        <span class="visually-hidden">搜索留言</span>
        <?= cream_icon('search', 'icon-sm') ?>
        <input name="q" value="<?= cream_e($q) ?>" maxlength="80" placeholder="搜索称呼、正文或文章" autocomplete="off">
      </label>
      <label class="comment-inbox-post">
        <span class="visually-hidden">按文章筛选</span>
        <select name="post_id" aria-label="按文章筛选">
          <option value="0">全部文章</option>
          <?php foreach ($postOptions as $post): ?>
          <option value="<?= (int) $post['id'] ?>"<?= $postId === (int) $post['id'] ? ' selected' : '' ?>><?= cream_e((string) $post['title']) ?>（<?= (int) $post['total'] ?>）</option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="admin-button primary comment-inbox-filter-submit" type="submit">筛选</button>
      <?php if ($q !== '' || $postId > 0): ?>
      <a class="comment-inbox-clear" data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="<?= cream_e($baseUrl . ($status !== '' ? '?status=' . rawurlencode($status) : '')) ?>">清空条件</a>
      <?php endif; ?>
    </form>
    <div class="comment-inbox-result"><strong><?= number_format($total) ?></strong> 条当前结果</div>
  </div>

  <?php if ($ok !== '' && isset($okMessages[$ok])): ?>
  <div class="admin-flash comment-inbox-flash" role="status" data-auto-dismiss="5000"><?= cream_icon('spark', 'icon-sm') ?> <?= cream_e($okMessages[$ok]) ?></div>
  <?php endif; ?>

<?php if ($comments === []): ?>
  <div class="admin-empty comment-inbox-empty">
    <?= cream_icon('heart') ?>
    <h2><?= $status === 'pending' ? '待审队列已经清空' : ($status === 'spam' ? '垃圾箱是空的' : ($status === 'approved' ? '还没有通过的留言' : '收件箱还是空的')) ?></h2>
    <p><?= $q !== '' || $postId > 0 ? '换个关键词、文章或状态再试。' : '读者在文章页提交留言后，会先进入待审核队列。' ?></p>
    <?php if ($q !== '' || $postId > 0): ?><a class="admin-button ghost" data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="<?= cream_e($tabUrl($status)) ?>">清空条件</a><?php endif; ?>
  </div>
<?php else: ?>
  <form id="comment-bulk-form" class="comment-bulk-form" method="post" action="/admin/comments/bulk.html">
    <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
    <input type="hidden" name="return_status" value="<?= cream_e($status) ?>">
    <input type="hidden" name="return_q" value="<?= cream_e($q) ?>">
    <input type="hidden" name="return_post_id" value="<?= (int) $postId ?>">
    <input type="hidden" name="return_page" value="<?= (int) $page ?>">
  </form>

  <div class="comment-bulk-bar" data-comment-bulk-bar>
    <label class="comment-bulk-selectall">
      <input type="checkbox" data-comment-select-all>
      <span>全选本页</span>
    </label>
    <span class="comment-bulk-count" data-comment-selected-count>已选 0 条</span>
    <div class="comment-bulk-actions">
      <button class="admin-button" type="submit" form="comment-bulk-form" name="action" value="approved" data-comment-bulk-button>通过</button>
      <button class="admin-button ghost" type="submit" form="comment-bulk-form" name="action" value="pending" data-comment-bulk-button>待审</button>
      <button class="admin-button ghost" type="submit" form="comment-bulk-form" name="action" value="spam" data-comment-bulk-button>垃圾</button>
      <button class="link-button danger" type="submit" form="comment-bulk-form" name="action" value="delete" data-comment-bulk-delete data-comment-bulk-button>删除</button>
    </div>
  </div>

  <div class="comment-inbox-list">
  <?php foreach ($comments as $comment): ?>
    <?php $toneName = $tone($comment->authorName, $comment->id); $initial = mb_strtoupper(mb_substr($comment->authorName, 0, 1)); ?>
    <article class="comment-inbox-card comment-inbox-card--<?= cream_e($comment->status) ?>" id="comment-<?= (int) $comment->id ?>">
      <label class="comment-inbox-check" title="选择这条留言">
        <input type="checkbox" name="ids[]" value="<?= (int) $comment->id ?>" form="comment-bulk-form" data-comment-checkbox aria-label="选择 <?= cream_e($comment->authorName) ?> 的留言">
      </label>
      <div class="comment-inbox-card-main">
        <header class="comment-inbox-card-head">
          <div class="comment-inbox-author">
            <span class="comment-inbox-avatar comment-inbox-avatar--<?= cream_e($toneName) ?>"><?= cream_e($initial !== '' ? $initial : '?') ?></span>
            <div>
              <strong><?= cream_e($comment->authorName) ?></strong>
              <small><?= $comment->authorEmail !== '' ? cream_e($comment->authorEmail) : '未留邮箱' ?> · #<?= (int) $comment->id ?></small>
            </div>
          </div>
          <div class="comment-inbox-state">
            <span class="status-badge status-<?= cream_e($comment->status) ?>"><?= cream_e(match ($comment->status) { 'pending' => '待审核', 'approved' => '已通过', 'spam' => '垃圾', default => $comment->status }) ?></span>
            <time datetime="<?= cream_e($comment->createdAt) ?>"><?= cream_e(str_replace('-', '.', substr($comment->createdAt, 0, 16))) ?></time>
          </div>
        </header>

        <div class="comment-inbox-context">
          <span>来自</span>
          <a href="/posts/<?= cream_e(rawurlencode($comment->postSlug)) ?>.html" target="_blank" rel="noopener"><?= cream_e($comment->postTitle) ?> <?= cream_icon('external', 'icon-xs') ?></a>
          <?php if ($comment->parentId !== null): ?><span class="comment-inbox-reply-tag">回复 #<?= (int) $comment->parentId ?></span><?php endif; ?>
        </div>

        <?php if ($comment->parentId !== null && ($comment->parentAuthor !== '' || $comment->parentBody !== '')): ?>
        <div class="comment-inbox-quote">
          <span><?= cream_icon('quote', 'icon-xs') ?> <?= cream_e($comment->parentAuthor !== '' ? $comment->parentAuthor : '原留言') ?></span>
          <p><?= cream_e($excerpt($comment->parentBody)) ?></p>
        </div>
        <?php endif; ?>

        <p class="comment-inbox-body"><?= nl2br(cream_e($comment->body)) ?></p>

        <footer class="comment-inbox-actions">
          <?php if ($comment->status !== 'approved'): ?>
          <form method="post" action="/admin/comments/<?= (int) $comment->id ?>/approve.html" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <input type="hidden" name="return_status" value="<?= cream_e($status) ?>">
            <input type="hidden" name="return_q" value="<?= cream_e($q) ?>">
            <input type="hidden" name="return_post_id" value="<?= (int) $postId ?>">
            <input type="hidden" name="return_page" value="<?= (int) $page ?>">
            <button class="admin-button" type="submit"><?= cream_icon('check', 'icon-xs') ?> 通过</button>
          </form>
          <?php endif; ?>
          <?php if ($comment->status !== 'pending'): ?>
          <form method="post" action="/admin/comments/<?= (int) $comment->id ?>/pending.html" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <input type="hidden" name="return_status" value="<?= cream_e($status) ?>">
            <input type="hidden" name="return_q" value="<?= cream_e($q) ?>">
            <input type="hidden" name="return_post_id" value="<?= (int) $postId ?>">
            <input type="hidden" name="return_page" value="<?= (int) $page ?>">
            <button class="admin-button ghost" type="submit"><?= cream_icon('refresh', 'icon-xs') ?> 撤回待审</button>
          </form>
          <?php endif; ?>
          <?php if ($comment->status !== 'spam'): ?>
          <form method="post" action="/admin/comments/<?= (int) $comment->id ?>/spam.html" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <input type="hidden" name="return_status" value="<?= cream_e($status) ?>">
            <input type="hidden" name="return_q" value="<?= cream_e($q) ?>">
            <input type="hidden" name="return_post_id" value="<?= (int) $postId ?>">
            <input type="hidden" name="return_page" value="<?= (int) $page ?>">
            <button class="admin-button ghost" type="submit"><?= cream_icon('alert', 'icon-xs') ?> 标为垃圾</button>
          </form>
          <?php endif; ?>
          <form method="post" action="/admin/comments/<?= (int) $comment->id ?>/delete.html" class="inline-form" onsubmit="return confirm('确认删除这条留言？相关回复也会一起删除。')">
            <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
            <input type="hidden" name="return_status" value="<?= cream_e($status) ?>">
            <input type="hidden" name="return_q" value="<?= cream_e($q) ?>">
            <input type="hidden" name="return_post_id" value="<?= (int) $postId ?>">
            <input type="hidden" name="return_page" value="<?= (int) $page ?>">
            <button class="link-button danger" type="submit"><?= cream_icon('trash', 'icon-xs') ?> 删除</button>
          </form>
        </footer>
      </div>
    </article>
  <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="audit-pager comment-inbox-pager" aria-label="留言分页">
    <?php if ($prevUrl !== null): ?><a class="admin-button ghost" data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="<?= cream_e($prevUrl) ?>">上一页</a><?php endif; ?>
    <span>第 <?= $page ?> / <?= $pages ?> 页 · 共 <?= number_format($total) ?> 条</span>
    <?php if ($nextUrl !== null): ?><a class="admin-button ghost" data-ajax-link data-ajax-target="#admin-main" data-ajax-silent href="<?= cream_e($nextUrl) ?>">下一页</a><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
</section>

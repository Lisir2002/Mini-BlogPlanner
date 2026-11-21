<?php
$comments = is_array($comments ?? null) ? $comments : [];
$total = (int) ($total ?? count($comments));
$pending = (int) ($pending ?? 0);
$status = (string) ($status ?? '');
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$prevUrl = $prevUrl ?? null;
$nextUrl = $nextUrl ?? null;
$baseUrl = (string) ($baseUrl ?? '/admin/comments.html');
$ok = (string) ($ok ?? '');
$tabs = [
    '' => ['全部', $total],
    'pending' => ['待审核', $pending],
];
?>
<section class="admin-page">
  <header class="admin-page-head">
    <div>
      <span class="eyebrow">COMMENTS</span>
      <p>共 <?= $total ?> 条当前筛选结果。读者留言默认进入待审核，通过后才会显示在文章页。</p>
    </div>
    <div class="comment-tabs" role="tablist" aria-label="留言状态">
      <?php foreach ($tabs as $key => [$label, $count]): ?>
      <a class="comment-tab <?= $status === $key ? 'is-current' : '' ?>" href="<?= cream_e($baseUrl . ($key !== '' ? '?status=' . $key : '')) ?>">
        <?= cream_e($label) ?> <span><?= (int) $count ?></span>
      </a>
      <?php endforeach; ?>
      <a class="comment-tab <?= $status === 'approved' ? 'is-current' : '' ?>" href="<?= cream_e($baseUrl . '?status=approved') ?>">已通过</a>
      <a class="comment-tab <?= $status === 'spam' ? 'is-current' : '' ?>" href="<?= cream_e($baseUrl . '?status=spam') ?>">垃圾</a>
    </div>
  </header>

  <?php if ($ok !== ''): ?>
  <div class="admin-flash"><?= cream_icon('spark', 'icon-sm') ?> 操作已完成。</div>
  <?php endif; ?>

<?php if ($comments === []): ?>
  <div class="admin-empty"><?= cream_icon('heart') ?><h2><?= $status === 'pending' ? '没有待审核留言' : '还没有留言' ?></h2><p><?= $status === 'pending' ? '新的读者留言会出现在这里。' : '读者在文章页提交留言后，会先进入待审核队列。' ?></p></div>
<?php else: ?>
  <div class="comment-admin-list">
  <?php foreach ($comments as $comment): ?>
    <article class="comment-admin-card">
      <header class="comment-admin-head">
        <div>
          <span class="status-badge status-<?= cream_e($comment->status) ?>"><?= cream_e(match ($comment->status) { 'pending' => '待审核', 'approved' => '已通过', 'spam' => '垃圾', default => $comment->status }) ?></span>
          <strong><?= cream_e($comment->authorName) ?></strong>
          <?php if ($comment->authorEmail !== ''): ?><span class="comment-email"><?= cream_e($comment->authorEmail) ?></span><?php endif; ?>
        </div>
        <time><?= cream_e($comment->createdAt) ?></time>
      </header>
      <div class="comment-admin-meta">
        来自 <a href="/posts/<?= cream_e(rawurlencode($comment->postSlug)) ?>.html" target="_blank" rel="noopener"><?= cream_e($comment->postTitle) ?> <?= cream_icon('external', 'icon-xs') ?></a>
        <?php if ($comment->parentId !== null): ?><span>回复 #<?= (int) $comment->parentId ?></span><?php endif; ?>
      </div>
      <p class="comment-admin-body"><?= nl2br(cream_e($comment->body)) ?></p>
      <div class="comment-admin-actions">
        <?php if ($comment->status !== 'approved'): ?>
        <form method="post" action="/admin/comments/<?= (int) $comment->id ?>/approve.html" class="inline-form">
          <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
          <input type="hidden" name="status" value="<?= cream_e($status) ?>">
          <button class="admin-button" type="submit">通过</button>
        </form>
        <?php endif; ?>
        <?php if ($comment->status !== 'spam'): ?>
        <form method="post" action="/admin/comments/<?= (int) $comment->id ?>/spam.html" class="inline-form">
          <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
          <input type="hidden" name="status" value="<?= cream_e($status) ?>">
          <button class="admin-button ghost" type="submit">标为垃圾</button>
        </form>
        <?php endif; ?>
        <form method="post" action="/admin/comments/<?= (int) $comment->id ?>/delete.html" class="inline-form" onsubmit="return confirm('确认删除这条留言？相关回复也会一起删除。')">
          <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
          <input type="hidden" name="status" value="<?= cream_e($status) ?>">
          <button class="link-button danger" type="submit">删除</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="audit-pager" aria-label="留言分页">
    <?php if ($prevUrl !== null): ?><a class="admin-button ghost" href="<?= cream_e($prevUrl) ?>">上一页</a><?php endif; ?>
    <span>第 <?= $page ?> / <?= $pages ?> 页</span>
    <?php if ($nextUrl !== null): ?><a class="admin-button ghost" href="<?= cream_e($nextUrl) ?>">下一页</a><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
</section>

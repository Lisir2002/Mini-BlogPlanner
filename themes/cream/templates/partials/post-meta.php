<?php
$post = $post ?? null;
if (!is_object($post)) {
    return;
}
$compact = ($compact ?? false) === true;
$showUpdated = ($show_updated ?? true) === true;
$commentCount = is_int($comment_count ?? null) ? $comment_count : null;
$wordCount = cream_word_count((string) $post->body);
$updatedAt = is_string($post->updatedAt ?? null) && $post->updatedAt !== ''
    ? $post->updatedAt
    : (string) $post->publishedAt;
?>
<div class="post-meta<?= $compact ? ' post-meta--compact' : '' ?>" aria-label="文章信息">
  <span class="meta-item" title="发布日期">
    <span class="meta-icon meta-icon--butter"><?= cream_icon('calendar', 'icon-xs') ?></span>
    <span class="meta-text"><span class="meta-label">发布</span><time datetime="<?= cream_e($post->publishedAt) ?>"><?= cream_e(cream_date($post->publishedAt)) ?></time></span>
  </span>
<?php if ($showUpdated): ?>
  <span class="meta-item" title="最后更新日期">
    <span class="meta-icon meta-icon--mint"><?= cream_icon('refresh', 'icon-xs') ?></span>
    <span class="meta-text"><span class="meta-label">更新</span><time datetime="<?= cream_e($updatedAt) ?>"><?= cream_e(cream_date($updatedAt)) ?></time></span>
  </span>
<?php endif; ?>
  <span class="meta-item" title="预计阅读时长">
    <span class="meta-icon meta-icon--blue"><?= cream_icon('clock', 'icon-xs') ?></span>
    <span class="meta-text"><span class="meta-label">阅读</span><span class="meta-data"><?= (int) $post->readingMinutes ?> 分钟</span></span>
  </span>
  <span class="meta-item" title="正文总字数">
    <span class="meta-icon meta-icon--peach"><?= cream_icon('book', 'icon-xs') ?></span>
    <span class="meta-text"><span class="meta-label">字数</span><span class="meta-data"><?= number_format($wordCount) ?> 字</span></span>
  </span>
<?php if ($commentCount !== null): ?>
  <span class="meta-item" title="已通过审核的留言数">
    <span class="meta-icon meta-icon--lavender"><?= cream_icon('comment', 'icon-xs') ?></span>
    <span class="meta-text"><span class="meta-label">留言</span><span class="meta-data"><?= number_format($commentCount) ?> 条</span></span>
  </span>
<?php endif; ?>
</div>

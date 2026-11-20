<?php
$post = $post ?? null;
if (!is_object($post)) {
    return;
}
$threads = is_array($comment_threads ?? null) ? $comment_threads : [];
$count = (int) ($comment_count ?? 0);
$errors = is_array($comment_errors ?? null) ? $comment_errors : [];
$values = is_array($comment_values ?? null) ? $comment_values : [];
$notice = is_string($comment_notice ?? null) ? $comment_notice : null;
$replyTo = isset($comment_reply_to) && is_int($comment_reply_to) ? $comment_reply_to : null;
$replyName = is_string($comment_reply_name ?? null) ? $comment_reply_name : '';
$formAt = (string) ($comment_form_at ?? '');
$csrf = (string) ($comment_csrf ?? '');
$composerOpen = $replyTo !== null || $errors !== [];
$emailEnabled = ($values['email_enabled'] ?? false) === true;
$slug = rawurlencode((string) $post->slug);
$value = static fn (string $key): string => (string) ($values[$key] ?? '');
?>
<section class="comments" id="comments" aria-labelledby="comments-title">
  <header class="comments-head">
    <span class="tape tape-butter comments-tape" aria-hidden="true"></span>
    <div>
      <span class="eyebrow"><?= cream_icon('heart', 'icon-sm') ?> MARGIN NOTES</span>
      <h2 id="comments-title">留言与回复</h2>
      <p><?= $count > 0 ? '已经有 ' . $count . ' 条留言。' : '还没有留言，来写第一条。' ?></p>
    </div>
  </header>

  <?php if ($notice !== null): ?>
  <div class="comment-notice" role="status"><?= cream_icon('spark', 'icon-sm') ?> <?= cream_e($notice) ?></div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
  <div class="comment-errors" role="alert">
    <strong>留言没有提交成功</strong>
    <ul>
      <?php foreach ($errors as $error): ?><li><?= cream_e((string) $error) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($threads !== []): ?>
  <div class="comment-list">
    <?php foreach ($threads as $thread): $comment = $thread['comment']; ?>
    <article class="comment-item" id="comment-<?= (int) $comment->id ?>">
      <div class="comment-meta">
        <strong><?= cream_e($comment->authorName) ?></strong>
        <time datetime="<?= cream_e($comment->createdAt) ?>"><?= cream_e(cream_date($comment->createdAt)) ?></time>
      </div>
      <div class="comment-body"><?= nl2br(cream_e($comment->body)) ?></div>
      <a class="comment-reply-link" href="/posts/<?= $slug ?>.html?reply=<?= (int) $comment->id ?>#comment-form"><?= cream_icon('arrow-right', 'icon-xs') ?> 回复</a>

      <?php if ($thread['replies'] !== []): ?>
      <div class="comment-replies">
        <?php foreach ($thread['replies'] as $reply): ?>
        <article class="comment-item comment-reply" id="comment-<?= (int) $reply->id ?>">
          <div class="comment-meta">
            <strong><?= cream_e($reply->authorName) ?></strong>
            <time datetime="<?= cream_e($reply->createdAt) ?>"><?= cream_e(cream_date($reply->createdAt)) ?></time>
          </div>
          <div class="comment-body"><?= nl2br(cream_e($reply->body)) ?></div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="comment-empty">
    <?= cream_icon('pen') ?>
    <p>这一页还很安静。留下第一张便签吧。</p>
  </div>
  <?php endif; ?>

  <details class="comment-composer" id="comment-form"<?= $composerOpen ? ' open' : '' ?>>
    <summary class="comment-composer-toggle">
      <span class="comment-composer-mark"><?= cream_icon('pen', 'icon-sm') ?></span>
      <span class="comment-composer-copy">
        <strong>写一张留言便签</strong>
        <small><?= $replyTo !== null ? '回复 ' . cream_e($replyName) : '审核后显示 · 邮箱不会公开' ?></small>
      </span>
      <span class="comment-composer-action">
        <span class="composer-open-label">写留言</span>
        <span class="composer-close-label">收起</span>
        <?= cream_icon('arrow-right', 'icon-xs') ?>
      </span>
    </summary>
  <form class="comment-form" method="post" action="/posts/<?= $slug ?>/comments.html" novalidate>
    <span class="tape tape-mint comment-form-tape" aria-hidden="true"></span>
    <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
    <input type="hidden" name="_form_at" value="<?= cream_e($formAt) ?>">
    <input type="hidden" name="parent_id" value="<?= $replyTo !== null ? (int) $replyTo : '' ?>">
    <div class="comment-honeypot" aria-hidden="true">
      <label for="comment-website">网站</label>
      <input id="comment-website" name="website" type="text" tabindex="-1" autocomplete="off">
    </div>

    <?php if ($replyTo !== null): ?>
    <div class="comment-reply-context">
      <span><?= cream_icon('arrow-right', 'icon-xs') ?> 正在回复 <strong><?= cream_e($replyName) ?></strong></span>
      <a class="link-button" href="/posts/<?= $slug ?>.html#comment-form">取消回复</a>
    </div>
    <?php endif; ?>

    <div class="comment-fields">
      <label class="field">
        <span>称呼</span>
        <input name="author_name" maxlength="40" value="<?= cream_e($value('author_name')) ?>" placeholder="怎么称呼你？" autocomplete="name" aria-invalid="<?= in_array('称呼至少需要 2 个字。', $errors, true) ? 'true' : 'false' ?>">
      </label>
    </div>

    <div class="comment-email-row">
      <label class="comment-email-toggle" for="comment-notify">
        <span class="comment-email-title">邮箱 <small>只用于回复提醒</small></span>
        <input class="comment-notify-input" id="comment-notify" name="notify_email" type="checkbox" value="1" role="switch" aria-label="邮箱回复提醒" aria-describedby="comment-notify-hint"<?= $emailEnabled ? ' checked' : '' ?>>
        <span class="comment-notify-track" aria-hidden="true"><span class="comment-notify-thumb"></span></span>
        <span class="comment-notify-state" aria-hidden="true">
          <span class="comment-notify-off">关闭</span>
          <span class="comment-notify-on">开启</span>
        </span>
        <span class="comment-honeypot" id="comment-notify-hint">开启后填写邮箱，地址不会公开。</span>
      </label>
    </div>

    <div class="comment-email-field">
      <input id="comment-email" name="author_email" type="email" maxlength="190" value="<?= cream_e($value('author_email')) ?>" placeholder="你的邮箱地址" autocomplete="email" aria-label="邮箱" aria-describedby="comment-notify-hint" aria-invalid="<?= in_array('邮箱格式不正确。', $errors, true) ? 'true' : 'false' ?>">
    </div>

    <label class="field">
      <span>留言</span>
      <textarea name="body" rows="4" maxlength="1000" placeholder="写下想说的话，最多 1000 字。" aria-invalid="<?= in_array('留言内容至少需要 2 个字。', $errors, true) ? 'true' : 'false' ?>"><?= cream_e($value('body')) ?></textarea>
    </label>
    <div class="comment-form-foot">
      <p>留言会在审核后显示。邮箱只用于回复提醒，不会公开。</p>
      <button class="button button-primary" type="submit"><?= cream_icon('pen', 'icon-sm') ?> 提交留言</button>
    </div>
  </form>
  </details>
</section>

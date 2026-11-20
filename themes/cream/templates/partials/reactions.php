<?php
$likes = is_int($reaction_likes ?? null) ? $reaction_likes : 0;
$dislikes = is_int($reaction_dislikes ?? null) ? $reaction_dislikes : 0;
$mine = is_int($reaction_mine ?? null) ? $reaction_mine : 0;
$csrf = (string) ($reaction_csrf ?? '');
$notice = is_string($reaction_notice ?? null) ? $reaction_notice : null;
$slug = rawurlencode((string) ($post->slug ?? ''));
$likeActive = $mine === 1;
$dislikeActive = $mine === -1;
?>
<section class="post-reactions" id="echo" aria-labelledby="echo-title" data-reaction-widget>
  <span class="tape tape-mint reaction-tape" aria-hidden="true"></span>
  <header class="reaction-head">
    <span class="eyebrow"><?= cream_icon('heart', 'icon-xs') ?> READER ECHO</span>
    <h2 id="echo-title"><span class="reaction-title-long">这张便签，合你口味吗？</span><span class="reaction-title-short">合口味吗？</span></h2>
    <p>赞或踩只记一次，可以改，也可以撤回。</p>
  </header>
  <div class="reaction-controls">
    <form class="reaction-form" method="post" action="/posts/<?= $slug ?>/reaction.html" data-reaction-form>
      <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
      <input type="hidden" name="value" value="<?= $likeActive ? 0 : 1 ?>" data-reaction-value>
      <button class="reaction-button reaction-like<?= $likeActive ? ' is-active' : '' ?>" type="submit" data-reaction-button data-reaction-choice="1" aria-pressed="<?= $likeActive ? 'true' : 'false' ?>" aria-label="赞这篇文章">
        <span class="reaction-mark" aria-hidden="true"><?= cream_icon('thumb-up', 'icon-sm') ?></span>
        <span class="reaction-label">赞</span>
        <span class="reaction-count" data-reaction-count="likes"><?= $likes ?></span>
      </button>
    </form>
    <form class="reaction-form" method="post" action="/posts/<?= $slug ?>/reaction.html" data-reaction-form>
      <input type="hidden" name="_csrf" value="<?= cream_e($csrf) ?>">
      <input type="hidden" name="value" value="<?= $dislikeActive ? 0 : -1 ?>" data-reaction-value>
      <button class="reaction-button reaction-dislike<?= $dislikeActive ? ' is-active' : '' ?>" type="submit" data-reaction-button data-reaction-choice="-1" aria-pressed="<?= $dislikeActive ? 'true' : 'false' ?>" aria-label="踩这篇文章">
        <span class="reaction-mark reaction-mark-down" aria-hidden="true"><?= cream_icon('thumb-up', 'icon-sm') ?></span>
        <span class="reaction-label">踩</span>
        <span class="reaction-count" data-reaction-count="dislikes"><?= $dislikes ?></span>
      </button>
    </form>
  </div>
  <p class="reaction-status" data-reaction-status aria-live="polite"<?= $notice === null ? ' hidden' : '' ?>><?= $notice === null ? '' : cream_e($notice) ?></p>
</section>

<?php
$emojiGroups = [
    'agree' => [
        'label' => '赞同',
        'kind' => 'emoji',
        'items' => [
            ['value' => '👍', 'name' => '点赞'],
            ['value' => '👏', 'name' => '鼓掌'],
            ['value' => '✅', 'name' => '可以'],
            ['value' => '💯', 'name' => '满分'],
            ['value' => '🙌', 'name' => '叫好'],
            ['value' => '🤝', 'name' => '握手'],
        ],
    ],
    'funny' => [
        'label' => '好笑',
        'kind' => 'emoji',
        'items' => [
            ['value' => '😄', 'name' => '开心'],
            ['value' => '😂', 'name' => '笑哭'],
            ['value' => '🤣', 'name' => '笑倒'],
            ['value' => '😆', 'name' => '好笑'],
            ['value' => '😹', 'name' => '猫笑'],
            ['value' => '😅', 'name' => '汗笑'],
        ],
    ],
    'sorry' => [
        'label' => '抱歉',
        'kind' => 'emoji',
        'items' => [
            ['value' => '🙏', 'name' => '拜托'],
            ['value' => '😔', 'name' => '低落'],
            ['value' => '🥺', 'name' => '求求'],
            ['value' => '😞', 'name' => '抱歉'],
            ['value' => '😣', 'name' => '为难'],
            ['value' => '😖', 'name' => '纠结'],
        ],
    ],
    'tired' => [
        'label' => '累了',
        'kind' => 'emoji',
        'items' => [
            ['value' => '😴', 'name' => '睡着'],
            ['value' => '🥱', 'name' => '打哈欠'],
            ['value' => '🫠', 'name' => '融化'],
            ['value' => '😪', 'name' => '困了'],
            ['value' => '😵', 'name' => '晕了'],
            ['value' => '😩', 'name' => '累瘫'],
        ],
    ],
    'surprise' => [
        'label' => '惊喜',
        'kind' => 'emoji',
        'items' => [
            ['value' => '😮', 'name' => '惊讶'],
            ['value' => '🤩', 'name' => '星星眼'],
            ['value' => '🎉', 'name' => '庆祝'],
            ['value' => '✨', 'name' => '闪光'],
            ['value' => '😍', 'name' => '喜欢'],
            ['value' => '🥳', 'name' => '开派对'],
        ],
    ],
    'kaomoji' => [
        'label' => '颜文字',
        'kind' => 'text',
        'items' => [
            ['value' => '(≧▽≦)', 'name' => '开心'],
            ['value' => '(´･ω･`)', 'name' => '委屈'],
            ['value' => '(￣▽￣)', 'name' => '轻松'],
            ['value' => '(๑•̀ㅂ•́)و✧', 'name' => '加油'],
            ['value' => '(•̀ᴗ•́)و', 'name' => '努力'],
            ['value' => '(づ｡◕‿‿◕｡)づ', 'name' => '抱抱'],
            ['value' => '(；一_一)', 'name' => '无语'],
            ['value' => 'orz', 'name' => '趴了'],
            ['value' => '(・_・;)', 'name' => '出汗'],
            ['value' => '_(:3」∠)_', 'name' => '躺平'],
        ],
    ],
];
$groupKeys = array_keys($emojiGroups);
$firstGroup = (string) ($groupKeys[0] ?? 'agree');
?>
<div class="field comment-body-field">
  <div class="comment-body-head">
    <label for="comment-body">留言</label>
    <button class="comment-emoji-trigger" type="button" data-comment-emoji-trigger aria-expanded="false" aria-controls="comment-emoji-panel">
      <span aria-hidden="true">☺</span>
      <span>贴表情</span>
    </button>
  </div>
  <textarea id="comment-body" name="body" rows="4" maxlength="1000" placeholder="写下想说的话，最多 1000 字。" aria-invalid="<?= $bodyInvalid ? 'true' : 'false' ?>"><?= cream_e($bodyValue) ?></textarea>

  <section class="comment-emoji-panel" id="comment-emoji-panel" data-comment-emoji-panel aria-label="表情面板" hidden>
    <div class="comment-emoji-head">
      <div>
        <strong>挑一张语气便签</strong>
        <small>点一下，插到光标处</small>
      </div>
      <button class="comment-emoji-close" type="button" data-comment-emoji-close aria-label="关闭表情面板"><?= cream_icon('close', 'icon-xs') ?></button>
    </div>

    <div class="comment-emoji-recent" data-comment-emoji-recent hidden>
      <span>最近</span>
      <div class="comment-emoji-recent-list" data-comment-emoji-recent-list></div>
    </div>

    <div class="comment-emoji-tabs" role="tablist" aria-label="表情分类">
      <?php foreach ($emojiGroups as $key => $group): ?>
      <button class="comment-emoji-tab<?= $key === $firstGroup ? ' is-active' : '' ?>" type="button" role="tab" id="comment-emoji-tab-<?= cream_e((string) $key) ?>" aria-controls="comment-emoji-group-<?= cream_e((string) $key) ?>" aria-selected="<?= $key === $firstGroup ? 'true' : 'false' ?>" tabindex="<?= $key === $firstGroup ? '0' : '-1' ?>" data-comment-emoji-tab="<?= cream_e((string) $key) ?>"><?= cream_e($group['label']) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="comment-emoji-groups">
      <?php foreach ($emojiGroups as $key => $group): ?>
      <div class="comment-emoji-group<?= $group['kind'] === 'text' ? ' is-text' : '' ?>" role="tabpanel" id="comment-emoji-group-<?= cream_e((string) $key) ?>" aria-labelledby="comment-emoji-tab-<?= cream_e((string) $key) ?>" data-comment-emoji-group="<?= cream_e((string) $key) ?>"<?= $key === $firstGroup ? '' : ' hidden' ?>>
        <?php foreach ($group['items'] as $item): ?>
        <button class="comment-emoji-item" type="button" data-comment-emoji="<?= cream_e($item['value']) ?>" data-comment-emoji-name="<?= cream_e($item['name']) ?>" data-comment-emoji-group="<?= cream_e($group['label']) ?>" data-comment-emoji-kind="<?= cream_e($group['kind']) ?>" aria-label="插入<?= cream_e($group['label']) ?>：<?= cream_e($item['name']) ?>" title="<?= cream_e($item['name']) ?>"><?= cream_e($item['value']) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="comment-emoji-status sr-only" aria-live="polite" data-comment-emoji-status></p>
  </section>
</div>

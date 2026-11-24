# 内置贴图素材

这些文件只用于评论面板的内置贴图，不开放访客上传。

## 来源与许可

- 原始动画：Google Noto Emoji，<https://googlefonts.github.io/noto-emoji-files/>
- 仓库：<https://github.com/googlefonts/noto-emoji>
- 许可：Creative Commons Attribution 4.0 International（CC BY 4.0）
- 许可全文：<https://creativecommons.org/licenses/by/4.0/>
- 署名：Noto Emoji © Google，使用 CC BY 4.0。

## 本地修改

- 从官方 CDN 的 `512.gif` 缩放到 72×72，供评论正文和选择面板使用。
- 动图按隔帧取样降到约 15fps，设置 60ms 帧延迟，使用 64 色透明调色板并执行图层优化。
- 每张动图另生成 96×96 PNG 静态替代，供 `prefers-reduced-motion: reduce` 时显示。
- 静态图不使用固定首帧，改为从可见帧中抽取；`hands.png` 取举手动作完整的一帧，避免空白图。
- 中文名称和短标记由主题目录 `themes/cream/catalog.php` 维护，服务端只接受白名单名称到本地文件的映射。

## 文件对应

| 文件 | 名称 | Noto codepoint |
| --- | --- | --- |
| `like.gif` / `like.png` | 点赞 | `1f44d` |
| `clap.gif` / `clap.png` | 鼓掌 | `1f44f` |
| `hands.gif` / `hands.png` | 举手 | `1f64c` |
| `handshake.gif` / `handshake.png` | 握手 | `1f91d` |
| `peace.gif` / `peace.png` | 比耶 | `270c` |
| `power.gif` / `power.png` | 加油 | `1f4aa` |
| `joy.gif` / `joy.png` | 爆笑 | `1f602` |
| `moved.gif` / `moved.png` | 感动 | `1f97a` |
| `cry.gif` / `cry.png` | 大哭 | `1f62d` |
| `angry.gif` / `angry.png` | 生气 | `1f621` |
| `mindblown.gif` / `mindblown.png` | 震惊 | `1f92f` |
| `speechless.gif` / `speechless.png` | 无语 | `1f611` |
| `party.gif` / `party.png` | 庆祝 | `1f389` |
| `heart.gif` / `heart.png` | 比心 | `2764` |
| `fire.gif` / `fire.png` | 火了 | `1f525` |
| `night.gif` / `night.png` | 晚安 | `1f634` |
| `coffee.gif` / `coffee.png` | 喝口 | `2615` |
| `luck.gif` / `luck.png` | 好运 | `1f340` |

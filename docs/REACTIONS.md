# 文章回声

文章末尾的“赞 / 踩”是匿名、可撤回的单选态度，不是投票排行。

## 规则

- 同一访客对同一篇文章只保留一条记录，值只能是 `1`（赞）、`-1`（踩）或删除。
- 赞踩计数都公开；不计算净分值，也不进入热门排行。
- 点击当前已选项会撤回，选择另一项会直接改为另一项。
- 访客令牌使用 64 位随机 Cookie，数据库只保存 HMAC 哈希。
- IP 只保存哈希，用于每分钟最多 20 条新记录的限流，不展示给作者。
- 所有写请求校验 CSRF；接口支持 JSON，也保留普通表单提交兜底。

## 数据

迁移 `2026112006_create_post_reactions.php` 建立 `post_reactions`：

- 唯一键 `(post_id, visitor_hash)`：阻止重复态度。
- `value TINYINT`：`1` 或 `-1`。
- `idx_post_reactions_tally (post_id, value)`：汇总计数。
- `idx_post_reactions_ip (ip_hash, updated_at)`：频率限制。

## 接口

- `POST /posts/{slug}/reaction.html`
- 表单字段：`_csrf`、`value`（`1`、`-1`、`0`）。
- `Accept: application/json` 时返回：

```json
{
  "data": {"slug": "hello-cream-journal", "likes": 3, "dislikes": 1, "mine": 1},
  "message": "已记下这一赞。"
}
```

- 普通表单提交返回 `303` 到文章页 `?reaction=liked|disliked|cleared#echo`。

## 前端

- 先乐观更新按钮和计数，再以服务端返回的计数为准。
- 网络或校验失败时回滚，并显示状态提示。
- 不刷新页面；无脚本时由表单提交兜底。

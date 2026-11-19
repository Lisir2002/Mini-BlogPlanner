# 独立页面

## 文件位置

```text
content/pages/{slug}.php
```

`slug` 只允许小写字母、数字、短横线，长度 1–64。对外地址为 `/{slug}.html`，例如 `content/pages/about.php` → `/about.html`。

## 返回值

页面文件由站长维护，可以执行任意 PHP；返回值支持：

```php
return [
    'title' => '页面标题',
    'template' => 'page',
    'styles' => ['pages/about.css'],
    'scripts' => ['pages/about.js'],
    'content' => '<section>...</section>',
];
```

也支持返回字符串、`Core\Page\PageResult`，或返回一个接收 `Core\Page\PageContext` 的 callable：

```php
use Core\Page\PageContext;

return static function (PageContext $context): array {
    return [
        'title' => '关于',
        'content' => '<p>' . htmlspecialchars($context->slug) . '</p>',
    ];
};
```

`PageContext` 提供 `slug`、`root`、`version`。页面可以直接用 `Core\Database\Connection` 查询数据，但只应读取，不要把连接信息写进页面。

## 样式与脚本

- 路径相对 `public/assets/`，例如 `pages/about.css`。
- 也允许以 `/` 开头的站内绝对路径。
- 主题布局会自动注入 `<link>` 和 `<script defer>`，页面不需要自己写 `<head>`。
- 页面 CSS 必须以页面根类名作用域化，避免污染全局主题。

## 安全边界

- 页面 PHP 只从文件系统加载，**不提供网页端编辑**。
- 能写 `content/pages/` 的人等价于能执行服务端代码；部署时该目录权限应收紧。
- 页面内容默认视为可信 HTML；如果接用户输入，必须自行转义。
- 删除页面文件即可下线；不存在时返回 404，不会回退首页。

## 示例

`content/pages/about.php` + `public/assets/pages/about.css` 是当前示例，演示页面级 PHP 日期计算与独立样式。

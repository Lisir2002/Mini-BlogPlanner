# 品牌规范

项目名与站点名分开使用，避免公开页出现软件名，也避免仓库里只有站点昵称。

## 命名

| 场景 | 名称 |
| --- | --- |
| GitHub 仓库、发布包、CLI、安装器 | Mini-BlogPlanner |
| 公开站点标题、页头、页脚、后台抬头 | 日记Planner |

## 展示层级

- 页头左侧：计划本图标 + 双行字标。第一行 `MINI-BLOGPLANNER` 是软件身份，第二行 `日记Planner` 是站点身份。
- 页脚：`日记Planner · 把日子写进计划里。`，版本行注明 `Mini-BlogPlanner v...`。
- 后台：标题为 `日记Planner 后台`，上方保留 `MINI-BLOGPLANNER` 小字。
- 安装器与 API 文档：使用项目名 Mini-BlogPlanner。
- 站点标题通过 `APP_NAME` 配置，默认 `日记Planner`。

## 图形

计划本轮廓 + 横线 + 珊瑚色铅笔，配合奶油纸底、墨色描边和黄色圆点。图形随文字使用 `currentColor`，站点图标为独立 SVG。

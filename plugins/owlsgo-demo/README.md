# owlsgo-demo · owlsgo 示例插件

这是一个**可直接启用的最小可用插件**，用来演示 owlsgo 插件机制的全部主要能力，
同时可以作为自研插件的骨架直接复制改名。

完整的插件开发规范见 **[`forum/docs/插件开发规范.md`](../../docs/插件开发规范.md)**。

## 目录结构

```
owlsgo-demo/
├── plugin.json            清单：元数据、钩子声明、静态资源、配置项
├── Plugin.php             入口：注册自动加载 + register() 里登记能力
├── Service.php            业务：配置读取、自建表读写、钩子回调实现
├── AdminController.php    处理器：前台路由 + 后台页面
├── sql/
│   ├── sqlite.sql         启用插件时按当前引擎自动执行
│   ├── mysql.sql
│   └── pgsql.sql
├── assets/
│   ├── plugin.css         合并进 /plugin-assets/css
│   └── plugin.js          合并进 /plugin-assets/js（type="module"）
└── templates/
    ├── hello.php          前台页 /hello
    ├── greet.php          前台页 /hello/{name}
    └── admin.php          后台页 /admin/plugin/owlsgo-demo
```

## 它演示了什么

| 能力 | 实现位置 | 观察方式 |
| --- | --- | --- |
| 内容过滤钩子 | `content_rendered` | 后台填写「正文脚注」后查看任意帖子正文末尾 |
| 前置拦截钩子 | `before_thread_create`、`before_post_create` | 后台填写「禁止词」后尝试发帖 / 回复 |
| 业务动作钩子 | `after_thread_create`、`after_post_create` | 发帖 / 回复后到插件首页或后台页看记录 |
| 页面资源钩子 | `head_assets`、`footer_assets` | 查看页面源码中的 `<meta name="owlsgo-plugin">`、`window.OwlsgoDemoPlugin` |
| 导航钩子 | `nav_links` | 登录后顶部导航出现「打招呼」 |
| 用户中心钩子 | `user_profile_tabs` | 个人主页标签栏出现「插件标签页」 |
| 前台路由（含参数） | `/hello`、`/hello/{name}` | 直接访问地址 |
| 带权限的写路由 | `POST /hello/clear` | 后台页「清空记录」按钮 |
| 后台页面 | `/admin/plugin/owlsgo-demo` | 后台侧栏「示例插件」 |
| 插件配置 | `plugin.json` 的 `settings` | 插件管理 → 配置 |
| 自建数据表 | `sql/*.sql` + `plugin_owlsgo_demo_activity` | 启用插件时自动建表 |
| 计划任务 | `Plugin::cron('owlsgo_demo_cleanup', 86400, ...)` | 计划任务页，可手动触发 |
| 插件自带模板 | `templates/*.php` | 通过 `view('plugin/owlsgo-demo/xxx')` 复用站点布局 |
| 插件静态资源 | `assets/*` | 经 `/plugin-file/owlsgo-demo/assets/plugin.css` 单独访问 |

## 启用步骤

1. 确认 `forum/plugins/owlsgo-demo/` 已就位。
2. 进入后台 **插件管理**，此时列表里会出现「owlsgo 示例插件」（新发现的插件默认未启用）。
3. 点击 **启用**：核心会执行 `sql/{当前引擎}.sql` 建表并登记计划任务。
4. 访问 `/hello` 查看前台页，访问 `/admin/plugin/owlsgo-demo` 查看后台页。
5. 在 **插件管理 → 配置** 中调整问候语、脚注、资源开关等，观察页面变化。

## 卸载说明

后台「卸载」只会删除 `plugins` 表记录与对应的计划任务，**不会**删除：

- 插件目录本身
- 插件自建表 `plugin_owlsgo_demo_activity`

如需彻底清理，请手动删除以上两项。

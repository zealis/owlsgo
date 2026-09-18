# owlsgo（owlsgo）

一个**纯原生 PHP** 的轻量论坛系统：不依赖任何第三方框架与 Composer 包，无构建步骤，

- **零依赖**：核心引擎、路由、ORM 风格查询构造器、模板渲染、插件机制全部自研
- **三种数据库**：SQLite / MySQL / PostgreSQL，同一套代码切换 DSN 即可
- **单入口 + MVC**：所有请求经 `public/index.php` 分发，业务代码按模块组织
- **开箱即用的社区功能**：版块与分类、发帖与回帖、点赞与收藏、@提及与通知、审核、附件、搜索
- **完整的权限体系**：用户组 + 版块级白名单 + 版主，权限清单集中在 `Core\Permission`
- **插件机制**：Hook、路由、后台页面、导航菜单、计划任务、自建表、资源合并、独立配置
- **安全默认值**：CSRF 强制校验、输出统一转义、全占位符绑定、上传白名单 + 随机重命名、限流、审计日志
- **经典配色**：基于 OATUI 组件库，响应式，兼容 PC 与移动端

---

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | **8.1 及以上** |
| 必需扩展 | `pdo`、`mbstring`、`json`、`fileinfo` |
| 数据库扩展 | `pdo_sqlite` **或** `pdo_mysql` **或** `pdo_pgsql` |
| 数据库 | SQLite 3 / MySQL 8.0+ / PostgreSQL 13+ |
| Web 服务 | Nginx 或 Apache（需支持 URL 重写） |

> 没有 URL 重写也能跑：核心会自动降级为 `/index.php?r=/t/12` 形态，无需改代码。

---

## 快速开始

1. **上传代码**：只需上传 `forum/` 目录（`storage/` 下运行时产生的内容不必上传）。
   然后把 Web 服务器的站点根目录指向 `forum/public/`（**不是** `forum/`）。

   仓库已附带两份 `.htaccess`，Apache 用户只要开启 `AllowOverride` 与 `mod_rewrite` 即自动生效；
   Nginx 用户请按下文「Web 服务器配置」粘贴对应配置。

2. **赋予写权限**：`forum/storage/` 需要 Web 进程可写。子目录会在首次请求时自动创建。

   ```bash
   chmod -R 755 storage
   # 若 Web 进程与文件属主不同，改为让 Web 用户拥有写权限
   chown -R www-data:www-data storage
   ```

3. **访问站点**：首次访问会自动跳转到安装向导 `/install`。

4. **按向导完成安装**：环境自检 → 选择数据库并填写连接信息 → 创建管理员账号。
   安装完成后会生成：

   - `storage/config/database.php` —— 含真实凭据的数据库配置（**不在版本库中**）
   - `storage/install.lock` —— 安装锁，此后 `/install` 会自动跳回首页

5. **配置计划任务**（可选，用于清理与维护）：见下文「计划任务」。

---

## 目录结构

```
forum/
├── public/                 唯一 Web 根目录
│   ├── index.php           唯一入口
│   └── assets/             OATUI 组件库、主题样式、站点脚本
├── core/                   核心引擎
│   ├── bootstrap.php       引导：常量、自动加载、配置、辅助函数
│   ├── App.php             请求分发与统一错误处理
│   ├── Router.php          路由匹配与 URL 生成
│   ├── Database.php        PDO 封装（占位符绑定、跨引擎兼容）
│   ├── Query.php           查询构造器
│   ├── Controller.php / Model.php / View.php
│   ├── Security.php        密码、CSRF、限流
│   ├── Text.php            Markdown / UBB 安全解析
│   ├── Upload.php / Avatar.php
│   ├── Permission.php      权限清单与判定
│   ├── Hook.php            钩子注册表
│   ├── Plugin.php          插件开发门面
│   └── PluginManager.php   插件发现、加载、资源合并、计划任务
├── modules/                业务模块
│   ├── User/               认证、用户中心、通知、媒体、上传
│   ├── Forum/              版块
│   ├── Thread/             主题
│   ├── Post/               回帖
│   ├── Admin/              后台管理
│   └── Install/            安装向导
├── templates/              视图模板
│   ├── layouts/            main / admin / auth / install / error
│   ├── partials/           header、editor、floor、flash、icon…
│   ├── forum/ thread/ post/ user/ auth/ admin/ install/ errors/
├── config/
│   ├── app.php             应用配置（非敏感）
│   ├── database.php        数据库配置模板（环境变量优先）
│   └── routes.php          路由表
├── sql/                    建表脚本：sqlite.sql / mysql.sql / pgsql.sql
├── plugins/                插件目录（含示例插件 owlsgo-demo）
├── docs/                   插件开发规范等文档
└── storage/                运行时目录（必须可写）
    ├── cache/              插件资源合并产物
    ├── logs/               运行日志
    ├── sessions/           会话文件
    ├── database/           SQLite 数据库文件
    ├── uploads/            附件
    ├── avatars/            头像
    └── config/             安装向导生成的凭据
```

---

## Web 服务器配置

### phpStudy Pro / 小皮面板（Windows 图形化，最省事）

phpStudy Pro 自带 PHP 8.x 与 Apache/Nginx，是 Windows 下最快的跑法。

1. **安装并启动 phpStudy Pro**（v8 / v9 均可）。

2. **挑一个 ≥ 8.1 的 PHP 版本**：「软件管理 → PHP」确认版本满足要求（如 `php8.2.9nts`），
   再到「设置 → 扩展」勾选下列扩展：

   | 扩展 | 用途 |
   | --- | --- |
   | `pdo_sqlite` | 默认数据库（零配置，开箱即用） |
   | `pdo_mysql` | 只有改用 MySQL 时才需要 |
   | `mbstring`、`fileinfo`、`json` | 必需 |
   | `gd` | 头像 / 验证码等图片处理（建议开启） |

   > **常见坑**：phpStudy 的 `php.ini` 里 GD 那行常被写成 `extension=gd2`，而它实际
   > 自带的是 `php_gd.dll`。名字对不上会导致 PHP 启动告警且 GD 不加载，改成
   > `extension=php_gd.dll` 即可。用 `php -m` 确认能看到 `gd` 就对了。

3. **新建网站**：「网站 → 创建网站」，按下表填写：

   | 项目 | 填写内容 |
   | --- | --- |
   | 域名 | 例如 `owlsgo.test`（勾选「同步 hosts」省去手动改 hosts） |
   | 根目录 | `<项目路径>\forum\public` ← **必须指向 `public/`，不要指向 `forum/`** |
   | PHP 版本 | 第 2 步选定的版本 |

4. **Apache 用户**：确认该站点的 vhost 允许 `.htaccess` 覆盖，否则伪静态（`/t/12`）会 404。
   打开面板「网站 → 管理 → 修改 → 配置文件」，确认 `<Directory>` 段内是：

   ```apache
   <Directory "D:/www/owlsgo/forum/public">
       AllowOverride All      # 关键：默认可能是 None
       Require all granted
   </Directory>
   ```

   保存后在面板里**重启 Apache** 生效。

5. **Nginx 用户（phpStudy 默认用的就是 Nginx）**：必须配伪静态，否则首页能开、
   但 `/install`、`/login`、`/t/12` 这类地址全部 404。

   **推荐的图形化做法**：面板「网站 → 管理 → 伪静态」，粘贴下面这一行后保存
   （保存会自动重载 Nginx）：

   ```nginx
   try_files $uri $uri/ /index.php?$query_string;
   ```

   这个输入框写的就是**站点根目录下的 `nginx.htaccess`**——phpStudy 生成的 vhost 里
   已经有一行 `include ".../public/nginx.htaccess";` 在等它。**本仓库已附带该文件**
   （`forum/public/nginx.htaccess`），所以代码复制过去后只要**重启/重载一次 Nginx** 即可。

   > ⚠️ 千万别往「伪静态」框里粘完整的 `location { ... }` 块。该文件是被 include 在
   > `location /` **内部**的，嵌套 `location` 会让 Nginx 直接启动失败。

   若不想用面板，也可以直接把规则抄进站点自己的 `vhosts/*.conf`，见下文「Nginx」一节。

6. **确认站点监听的端口**。phpStudy 给 `localhost` 建的默认站点可能**只监听 443**，
   这时 `http://localhost/` 是打不开的，请用 `https://localhost/`（自签证书需点「继续访问」），
   或在「网站 → 管理 → 修改」里把端口改成 `80`。

7. **访问站点**，会自动跳转到安装向导 `/install`，按向导走完即可。

> **只想先跑起来看看、不想动 vhost？** 直接用面板自带的 PHP CLI 启动内置服务器，一条命令搞定
> （在 CMD 里执行，`php.exe` 路径见面板「设置 → 环境」）：
>
> ```bat
> cd /d "D:\Project files\owlsgo\forum"
> "D:\Program Files (x86)\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe" -S 127.0.0.1:8080 -t public router.php
> ```
>
> 然后浏览器打开 `http://127.0.0.1:8080/`。这个方式**不需要** Apache/Nginx，也不依赖 `.htaccess`。

### Nginx

```nginx
server {
    listen 80;
    server_name forum.example.com;

    # 站点根目录必须指向 public/
    root /var/www/owlsgo/forum/public;
    index index.php;

    # 伪静态：不存在的文件全部交给入口
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # 按实际 PHP-FPM 地址调整
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # 核心目录即使被误暴露也拒绝访问
    location ~ ^/(core|modules|templates|config|sql|plugins|storage|docs)/ {
        deny all;
        return 404;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }

    client_max_body_size 8m;   # 需不小于后台设置的附件上限
}
```

> **`try_files $uri $uri/ /index.php?$query_string;` 是唯一不能少的一行。**
> 少了它，首页因为 `index index.php` 还能打开，但 `/install`、`/login`、`/t/12`
> 这些地址会因为「文件不存在」而由 Nginx 直接返回 **404 Not Found**（请求根本进不了 PHP）。
> 末尾的 `$query_string` 也不能丢，否则分页、搜索参数会被吞掉。

> **用 phpStudy / 小皮面板的话**：不用改本文件，伪静态规则写进站点根目录的
> `nginx.htaccess` 即可（面板「网站 → 管理 → 伪静态」）。仓库已附带该文件，
> 详见上文「phpStudy Pro / 小皮面板」一节。

### Apache

仓库已附带两份可直接使用的 `.htaccess`，无需自己写：

| 场景 | 站点根目录 | 生效文件 |
| --- | --- | --- |
| 推荐 | `forum/public/` | `public/.htaccess` |
| 降级：主机商不允许修改站点根目录 | `forum/` | `forum/.htaccess` |

前置条件：已启用 `mod_rewrite`，且目录允许 `.htaccess` 覆盖
（`<Directory>` 中 `AllowOverride All`，或至少 `AllowOverride FileInfo`）。

降级模式下自动探测出的基础路径是 `/public`，页面 URL 会带前缀（如 `/public/t/12`）。
想要干净的 URL，在 `config/app.php` 中显式声明基础路径为空即可：

```php
'base_path' => '',   // 请求已由 forum/.htaccess 转发到 public/，故基础路径为空
```

若使用子目录部署（如 `https://example.com/forum/`），请解开 `public/.htaccess`
中的 `RewriteBase` 一行并填写实际路径。

### 临时试用：PHP 内置服务器

```bash
cd forum
php -S 127.0.0.1:8080 -t public router.php
```

内置服务器没有 URL 重写能力，因此仓库附了一个开发用的 `router.php`：静态资源交回内置服务器，
其余请求（如 `/t/12`）转发给入口，伪静态在本地同样可用。

不想用 `router.php` 也可以，改为在 `config/app.php` 中设置 `'pretty_url' => false`，
全站切换到 `/index.php?r=/t/12` 形态（注意必须带 `-t public` 启动，否则请自行调整路径）。

---

## 数据库配置

优先级（高 → 低）：

1. `storage/config/database.php` —— 安装向导生成，含真实凭据
2. 环境变量 `OWLSGO_DB_*`
3. `config/database.php` 中的默认值

### 使用 SQLite（默认，零配置）

```php
'driver'   => 'sqlite',
'database' => 'owlsgo.sqlite',   // 实际文件落在 storage/database/ 下
```

### 使用 MySQL / PostgreSQL

```php
'driver'   => 'mysql',          // 或 pgsql
'host'     => '127.0.0.1',
'port'     => 3306,             // 0 表示按引擎取默认端口
'database' => 'owlsgo',
'username' => 'owlsgo',
'password' => '……',
```

### 使用环境变量

```bash
OWLSGO_DB_DRIVER=mysql
OWLSGO_DB_HOST=127.0.0.1
OWLSGO_DB_PORT=3306
OWLSGO_DB_NAME=owlsgo
OWLSGO_DB_USER=owlsgo
OWLSGO_DB_PASS=……
```

> **说明**：本系统不使用表前缀。需要在同一数据库部署多套论坛时，请使用不同的库或 schema。
>
> 三种引擎的建表脚本分别位于 `sql/sqlite.sql`、`sql/mysql.sql`、`sql/pgsql.sql`，
> 所有时间字段统一为 `INTEGER`（Unix 时间戳），删除统一为软删除（`deleted_at IS NULL`）。

---

## 计划任务配置

系统的计划任务（维护清理 + 插件任务）通过一个带令牌的 HTTP 入口触发。

1. 进入后台 **计划任务** 页，点击生成触发令牌，复制页面上的触发地址。
2. 在服务器上添加 crontab：

```bash
# 每 5 分钟触发一次（也可改为每 10 分钟，任务本身会判断是否到期）
*/5 * * * * curl -s "https://forum.example.com/cron/run?token=粘贴你的令牌" > /dev/null
```

令牌可在后台随时重置，重置后旧地址立即失效。
也可以不配置 crontab，改为在后台页手动点「立即执行」。

---

## 安全清单

上线前建议逐项确认：

- [ ] 站点根目录指向 `public/`，其余目录无法通过 URL 访问
- [ ] `storage/` 仅 Web 进程可写，且不在版本库中
- [ ] `config/app.php` 中 `debug` 为 `false`（生产环境关闭错误回显）
- [ ] `storage/config/database.php` 权限收紧，不含在版本库中
- [ ] php.ini 中 `display_errors = Off`
- [ ] php.ini 中 `disable_functions` 至少包含 `exec,passthru,shell_exec,system,proc_open`
- [ ] 站点启用 HTTPS，会话 Cookie 会自动带上 `Secure`
- [ ] 计划任务令牌已生成且未外泄
- [ ] 后台管理员账号使用强密码，并已删除安装期间创建的临时账号

系统内建的防护：

| 风险 | 措施 |
| --- | --- |
| SQL 注入 | 全部查询走 `Core\Database` / `Core\Query`，一律占位符绑定，禁止拼接 |
| XSS | 统一 `e()` 转义；正文只解析 Markdown / UBB 白名单语法，**绝不**解析用户 HTML |
| CSRF | 所有写方法在分发前强制校验 Token，缺失直接 419 |
| 会话固定 | 登录成功后 `session_regenerate_id(true)` |
| 密码泄露 | `password_hash` + bcrypt，登录失败信息统一化，不区分账号是否存在 |
| 暴力破解 | 登录 / 注册 / 发帖 / 上传 / 搜索分别限流（`config/app.php` 中的 `rate_limit`） |
| 上传 WebShell | 扩展名 + MIME 双重白名单、随机重命名、存储目录不解析脚本 |
| 目录穿越 | 模板、插件入口、插件资源、上传路径全部经 realpath 校验 |
| 越权 | 权限判定集中在 `Core\Permission`，路由表逐条声明所需权限 |
| 敏感信息泄露 | 生产环境只记日志不回显；错误页不暴露表结构与文件路径 |
| 审计 | 后台关键操作写入 `logs` 表，可在后台检索与导出 |

---

## 插件开发

插件机制支持 Hook、路由、后台页面、前台菜单、用户中心标签页、计划任务、自建数据表、
独立配置表单与静态资源合并。

- 完整规范：**[`docs/插件开发规范.md`](docs/插件开发规范.md)**
- 可直接启用的示例插件：**[`plugins/owlsgo-demo/`](plugins/owlsgo-demo/README.md)**

最短路径：

```php
// plugins/my-plugin/Plugin.php
namespace MyPlugin;

use Core\Plugin as PluginApi;

final class Plugin
{
    public static function register(): void
    {
        PluginApi::filter('content_rendered', static fn (string $html): string => $html . '<p>—— 来自我的插件</p>');
    }
}
```

配套一个声明 `namespace` 的 `plugin.json`，然后到后台 **插件管理** 里启用即可。

---

## 常见问题

**访问首页跳到了 `/install`**
说明 `storage/install.lock` 不存在。完成安装向导即可；若是迁移过来的站点，请确认 `storage/` 已一并拷贝且有写权限。

**想重新安装（重置站点）**
删除 `storage/install.lock`，再删除 `storage/config/database.php`（换库时）或直接清空数据库，
然后重新访问 `/install`。`storage/` 下的附件、头像与日志不会自动清除，需要手动处理。

**页面能打开，但点任何链接都 404**
URL 重写没生效。Apache 检查 `mod_rewrite` 与 `AllowOverride`；Nginx 检查 `try_files` 一行；
或直接改用兼容形态：`config/app.php` 中设 `'pretty_url' => false`。

**URL 里多出一段 `/public/`**
站点根目录被指向了 `forum/`（走了降级方案）。要么把根目录改为 `forum/public/`，
要么在 `config/app.php` 中设 `'base_path' => ''` 去掉前缀。

**部署在子目录，样式和链接全部错位**
在 `config/app.php` 中显式指定前缀，例如 `'base_path' => '/forum'`，
同时解开 `public/.htaccess` 的 `RewriteBase` 一行。

**页面样式或图标丢失**
确认 Web 根目录指向的是 `public/`，且 `public/assets/` 已完整上传。

**合并后的插件资源没有更新**
核心仅在源文件修改时间变化时重建。可删除 `storage/cache/plugins.css`、`storage/cache/plugins.js`
与 `storage/cache/.signature` 强制重建；插件未启用时不会生成合并资源。

**接口返回 419**
表单缺少 CSRF 字段。请确认表单内有 `<?= csrf_field() ?>`。

**接口返回 429**
触发了限流，等待窗口期结束即可；阈值在 `config/app.php` 的 `rate_limit` 中调整。

**上传失败**
检查 PHP 的 `upload_max_filesize`、`post_max_size`，以及 Nginx 的 `client_max_body_size`，
三者都要不小于后台上传设置中的单文件上限。

**在 phpStudy Pro（小皮面板）上部署时踩坑**
三个高频问题：① 站点根目录要指向 `forum/public/`，指向 `forum/` 会走降级方案、URL 多出 `/public/`；
② Apache 需要 `AllowOverride All`，否则 `.htaccess` 不生效、所有伪静态链接 404；
③ `php.ini` 里 GD 若写成 `extension=gd2`，应改为 `extension=php_gd.dll`（phpStudy 自带的 DLL 名是后者）。
完整步骤见上文「phpStudy Pro / 小皮面板（Windows 图形化）」。

**打开就 404 Not Found，怎么快速定位是哪一层出的问题**
先看 404 页面的**样子**，一眼就能分清：

| 你看到的 | 谁返回的 | 含义与处理 |
| --- | --- | --- |
| 英文 **404 Not Found**（小皮/nginx 自带的错误页，通常带 `nginx` 或面板样式） | Nginx | 请求**根本没进 PHP**。典型原因：站点根目录指错、Nginx 缺伪静态规则（`nginx.htaccess` 为空）、或面板「伪静态」没配。 |
| 中文 **404 页面不存在**（论坛自己的蓝白主题页面） | 本程序 | 请求**已经进 PHP**，只是路由没匹配上（URL 写错，或该内容已被删除）。此时伪静态是正常的。 |
| 中文 **403 没有权限** | 本程序 | 伪静态正常，是权限判定拦下的（如版块不允许发帖）。 |

用命令行判定更准（把域名换成你的）：

```bash
curl -sk -o /dev/null -w "%{http_code}\n" https://localhost/install
# 200 -> 正常；404 -> 卡在 Nginx 层
```

若确认是 Nginx 层：首页能开、但 `/install`、`/login` 这类地址 404，
**几乎一定是缺 `try_files`**。小皮面板用户到「网站 → 管理 → 伪静态」填一行
`try_files $uri $uri/ /index.php?$query_string;` 保存即可（详见上文 phpStudy 一节）。

**点击「开始安装」弹出「服务器返回了无法解析的内容」；控制台 POST 403，URL 是 `[object HTMLButtonElement]`**

安装表单里的「测试数据库连接」按钮用了 `name="action" value="test"`，而 JS 旧代码
用 `form.action` 取提交地址。HTML 表单控件会按 `name` 变成 `form` 的属性，于是
`form.action` 返回的是那个**按钮元素**，而不是 `/install`，fetch 把它字符串化后就成了
`[object HTMLButtonElement]`，POST 到了 `/[object HTMLButtonElement]` → 403。

新版 `public/assets/js/app.js` 已改为 `form.getAttribute('action')`，并把提交按钮传给
`FormData(form, submitter)`，确保 `action=test` 能正确送到后端。
刷新页面加载新的 `app.js?v=...` 即可。


<?php
/**
 * 应用基础配置
 *
 * 说明：本文件仅存放「非敏感」的应用级常量。
 * 数据库账号密码等敏感信息一律由环境变量或 storage/config/database.php 提供，
 * 绝不写入版本控制中的文件。
 */

declare(strict_types=1);

return [
    /* 站点标识 */
    'name'    => 'owlsgo',
    /* ⚠️ 每次修改代码后必须递增（约定 2026-09-23）：manifest.json / 后台仪表盘 / asset() 兜底
       都取这里；manifest 由 Actions 在 push 后自动重算，无需手改。 */
    'version' => '1.3.0',

    /*
     * 在线升级的 GitHub 仓库（owner/repo，如 'acme/owlsgo'）。
     * 留空时后台「在线升级」页会提示未配置；填写后从该仓库的
     * GitHub Releases 检测最新版本（release 需附带 .zip 附件）。
     */
    'upgrade_repo' => 'zealis/owlsgo',
    /* 升级清单所在的分支（manifest.json 所在分支，由 Actions 自动维护） */
    'upgrade_branch' => 'main',

    /* 调试开关：生产环境必须为 false（会关闭错误显示、打开日志记录） */
    'debug'   => false,

    /* 时区 */
    'timezone' => 'Asia/Shanghai',

    /* 内部编码，全站统一 UTF-8 */
    'charset' => 'UTF-8',

    /* URL 重写：null 表示自动探测；true 强制伪静态；false 强制 index.php?r= 形式 */
    'pretty_url' => null,

    /*
     * 基础路径：null 表示自动探测（推荐）。
     * 以下两种情况需要显式指定：
     *   - 站点根目录只能指向 forum/（URL 带 /public 前缀，且已配置 .htaccess 转发）→ 填 ''
     *   - 自动探测结果不对（反向代理、脚本别名等）→ 填实际前缀，如 '/forum'
     */
    'base_path' => null,

    /* 会话与 Cookie */
    'session_name'    => 'owlsgo_sid',
    'cookie_prefix'   => 'owlsgo_',
    'cookie_ttl'      => 2592000,    // 30 天，用于「记住我」（2592000 = 30 * 86400）
    'cookie_secure'   => 'auto',     // auto | true | false

    /* 分页 */
    'per_page'        => 20,         // 列表每页条数
    'post_per_page'   => 15,         // 帖子内每页楼层数
    'max_page'        => 500,        // 分页上限，防止深分页拖垮数据库

    /* 缓存（站点设置 / 版块 / 用户组的懒加载缓存秒数，0 表示仅请求内缓存） */
    'cache_ttl'       => 300,
    'cache_driver'    => 'file',     // file | null（null 表示仅内存缓存）

    /* 发帖限制 */
    'post_interval'   => 15,         // 两次发帖最小间隔（秒）
    'thread_title_min' => 4,
    'thread_title_max' => 80,
    'post_content_min' => 2,
    'post_content_max' => 20000,

    /* 注册控制 */
    'register_enabled' => true,
    'register_verify'  => false,     // 是否要求邮箱验证（零依赖下仅作开关记录）

    /* 附件上传 */
    'upload' => [
        'enabled'      => true,
        'max_size'     => 20 * 1024 * 1024,  // 单文件 20MB
        'image_ext'    => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'file_ext'     => ['zip', 'rar', '7z', 'txt', 'pdf', 'md', 'log'],
        'image_mime'   => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'file_mime'    => [
            'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed',
            'application/vnd.rar', 'application/x-7z-compressed', 'text/plain',
            'application/pdf', 'text/markdown', 'application/octet-stream',
        ],
        'avatar_size'  => 2 * 1024 * 1024,
    ],

    /* 接口限流：滑动窗口内的最大请求数 */
    'rate_limit' => [
        'login'    => ['max' => 8,   'window' => 300],
        'register' => ['max' => 5,   'window' => 3600],
        'post'     => ['max' => 30,  'window' => 600],
        'upload'   => ['max' => 20,  'window' => 600],
        'upload_burst' => ['max' => 10, 'window' => 60],
        'search'   => ['max' => 1,   'window' => 20],
        'default'  => ['max' => 120, 'window' => 60],
    ],

    /* 头像风格（无外部依赖：使用内置 SVG 生成） */
    'avatar_styles' => ['owl', 'geometric', 'initial', 'robot', 'pixel'],

    /* 内容解析：markdown | ubb | plain */
    'content_parser' => 'markdown',
];

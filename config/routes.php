<?php
/**
 * 路由表
 *
 * 每行格式：[HTTP 方法, 路径, 处理器, 需要的权限, 是否校验 CSRF]
 *  - 路径支持 {name} 与 {name:正则} 两种占位符
 *  - 处理器写法为 "命名空间\类@方法"
 *  - 权限为空表示不额外校验（登录校验由控制器自行决定）
 *  - CSRF 校验对写方法默认开启，只有极少数回调类接口才应关闭
 *
 * 说明：所有控制器方法都会收到一个 array $params 参数（路由参数）。
 */

declare(strict_types=1);

return [
    /* ==================== 安装向导 ==================== */
    ['GET',  '/install', 'Modules\Install\InstallController@index', '', false],
    ['POST', '/install', 'Modules\Install\InstallController@run', '', false],

    /* ==================== 首页与版块 ==================== */
    ['GET', '/',        'Modules\Forum\ForumController@index'],
    ['GET', '/f/{id}',  'Modules\Forum\ForumController@show'],
    ['GET', '/f/{id}/page/{page:\d+}', 'Modules\Forum\ForumController@show'],

    /* ==================== 帖子 ==================== */
    ['GET',  '/t/{id}',                 'Modules\Thread\ThreadController@show'],
    ['GET',  '/t/{id}/page/{page:\d+}', 'Modules\Thread\ThreadController@show'],
    ['GET',  '/new',                    'Modules\Thread\ThreadController@create'],
    ['POST', '/new',                    'Modules\Thread\ThreadController@store'],
    ['GET',  '/t/{id}/edit',            'Modules\Thread\ThreadController@edit'],
    ['POST', '/t/{id}/edit',            'Modules\Thread\ThreadController@update'],
    ['POST', '/t/{id}/delete',          'Modules\Thread\ThreadController@destroy'],
    ['POST', '/t/{id}/moderate',        'Modules\Thread\ThreadController@moderate'],
    ['POST', '/t/{id}/like',            'Modules\Thread\ThreadController@toggleLike'],
    ['POST', '/t/{id}/favorite',        'Modules\Thread\ThreadController@toggleFavorite'],
    ['GET',  '/search',                 'Modules\Thread\ThreadController@search'],

    /* ==================== 评论 ==================== */
    ['POST', '/t/{id}/reply',  'Modules\Post\PostController@store'],
    ['GET',  '/p/{id}/edit',   'Modules\Post\PostController@edit'],
    ['POST', '/p/{id}/edit',   'Modules\Post\PostController@update'],
    ['POST', '/p/{id}/delete', 'Modules\Post\PostController@destroy'],
    ['POST', '/p/{id}/like',   'Modules\Post\PostController@toggleLike'],

    /* ==================== 编辑器 ==================== */
    /*
     * 实时预览：把编辑器里的正文交给服务端渲染，返回 HTML 片段。
     * 走服务端是为了与发布后的成稿逐字一致（正文由 Core\Text::toHtml 渲染后缓存）。
     * 登录校验在控制器内完成；CSRF 默认开启，前端提交时带 _token。
     */
    ['POST', '/editor/preview', 'Modules\Editor\EditorController@preview'],

    /* ==================== 认证 ==================== */
    ['GET',  '/login',    'Modules\User\AuthController@showLogin'],
    ['POST', '/login',    'Modules\User\AuthController@login', '', false],
    ['GET',  '/captcha',  'Modules\User\AuthController@captcha'],
    ['GET',  '/register', 'Modules\User\AuthController@showRegister'],
    ['POST', '/register', 'Modules\User\AuthController@register', '', false],
    ['GET',  '/logout',   'Modules\User\AuthController@logout'],

    /* ==================== 用户中心 ==================== */
    ['GET',  '/u/{id:\d+}',            'Modules\User\UserController@show'],
    ['GET',  '/u/{id:\d+}/threads',    'Modules\User\UserController@threads'],
    ['GET',  '/u/{id:\d+}/posts',      'Modules\User\UserController@posts'],
    ['GET',  '/u/{id:\d+}/favorites',  'Modules\User\UserController@favorites'],
    ['GET',  '/settings',              'Modules\User\UserController@settings'],
    ['GET',  '/settings/appearance',   'Modules\User\UserController@appearance'],
    ['POST', '/settings/account',      'Modules\User\UserController@updateAccount'],
    ['POST', '/settings/profile',      'Modules\User\UserController@updateProfile'],
    ['POST', '/settings/privacy',      'Modules\User\UserController@updatePrivacy'],
    ['POST', '/settings/password',     'Modules\User\UserController@updatePassword'],
    ['POST', '/settings/avatar',       'Modules\User\UserController@uploadAvatar'],
    ['POST', '/settings/avatar/dice',  'Modules\User\UserController@applyDicebearAvatar'],
    ['POST', '/settings/avatar/preset','Modules\User\UserController@applyPresetAvatar'],
    ['GET',  '/notifications',         'Modules\User\NotificationController@index'],
    ['POST', '/notifications/read',    'Modules\User\NotificationController@markRead'],

    /*
     * 通知中心的公告管理（全站通知）。
     * 权限由控制器统一校验：列表/查看对所有登录用户开放，
     * 增删改与两个管理页要求 notice.manage（用户组里可勾选「发布公告」）。
     * 注意 {id:\d+} 只吃数字，所以 /notices/resources、/notices/settings 不会与之冲突。
     */
    ['GET',  '/notices/create',          'Modules\Notice\NoticeController@create'],
    ['POST', '/notices',                 'Modules\Notice\NoticeController@store'],
    ['GET',  '/notices/resources',       'Modules\Notice\NoticeController@resources'],
    ['GET',  '/notices/settings',        'Modules\Notice\NoticeController@settings'],
    ['POST', '/notices/settings',        'Modules\Notice\NoticeController@saveSettings'],
    ['GET',  '/notices/{id:\d+}/edit',   'Modules\Notice\NoticeController@edit'],
    ['POST', '/notices/{id:\d+}',        'Modules\Notice\NoticeController@update'],
    ['POST', '/notices/{id:\d+}/delete', 'Modules\Notice\NoticeController@destroy'],

    /* ==================== 媒体资源 ==================== */
    ['POST', '/upload',              'Modules\User\UploadController@store'],
    /*
     * DiceBear 头像代理必须排在 /avatar/{seed}.svg 前面：
     * 否则 `/avatar/dice/xxx.svg` 会被当成「seed = dice/xxx」的本地头像。
     */
    ['GET', '/avatar/dice/{seed:[A-Za-z0-9_-]+}.svg', 'Modules\User\MediaController@dicebear'],
    ['GET', '/avatar/candidates.json', 'Modules\User\MediaController@dicebearCandidates'],
    ['GET', '/avatar/{seed}.svg',   'Modules\User\MediaController@avatar'],
    ['GET', '/media/{path:.+}',     'Modules\User\MediaController@media'],
    ['GET', '/attachment/{id:\d+}', 'Modules\User\MediaController@attachment'],

    /* ==================== 后台：概览与设置 ==================== */
    ['GET',  '/admin',          'Modules\Admin\AdminController@dashboard', 'admin.access'],
    /*
     * 站点设置拆成「一个分组一页」（分组清单见 Modules\Admin\SettingsPages）：
     * 裸地址 /admin/settings 由控制器 302 到默认分组，旧书签不会失效；
     * 未知分组在控制器里 abort 404。
     */
    ['GET',  '/admin/settings',            'Modules\Admin\AdminController@settings',     'admin.settings'],
    ['GET',  '/admin/settings/{group:[a-z-]+}', 'Modules\Admin\AdminController@settings', 'admin.settings'],
    ['POST', '/admin/settings',            'Modules\Admin\AdminController@saveSettings', 'admin.settings'],
    ['POST', '/admin/settings/{group:[a-z-]+}', 'Modules\Admin\AdminController@saveSettings', 'admin.settings'],
    ['POST', '/admin/maintenance/opcache', 'Modules\Admin\AdminController@clearOpcache', 'admin.settings'],
    ['GET',  '/admin/upgrade',         'Modules\Admin\UpgradeController@index', 'admin.settings'],
    ['POST', '/admin/upgrade/apply',   'Modules\Admin\UpgradeController@apply', 'admin.settings'],

    /* ==================== 后台：版块 ==================== */
    ['GET',  '/admin/forums',              'Modules\Admin\ForumController@index',   'admin.forum'],
    ['GET',  '/admin/forums/create',       'Modules\Admin\ForumController@create',  'admin.forum'],
    ['POST', '/admin/forums',              'Modules\Admin\ForumController@store',   'admin.forum'],
    ['GET',  '/admin/forums/{id:\d+}/edit',   'Modules\Admin\ForumController@edit',    'admin.forum'],
    ['POST', '/admin/forums/{id:\d+}',        'Modules\Admin\ForumController@update',  'admin.forum'],
    ['POST', '/admin/forums/{id:\d+}/delete', 'Modules\Admin\ForumController@destroy', 'admin.forum'],

    /* ==================== 后台：用户组 ==================== */
    ['GET',  '/admin/groups',              'Modules\Admin\GroupController@index',   'admin.group'],
    ['GET',  '/admin/groups/create',       'Modules\Admin\GroupController@create',  'admin.group'],
    ['POST', '/admin/groups',              'Modules\Admin\GroupController@store',   'admin.group'],
    ['GET',  '/admin/groups/{id:\d+}/edit',   'Modules\Admin\GroupController@edit',    'admin.group'],
    ['POST', '/admin/groups/{id:\d+}',        'Modules\Admin\GroupController@update',  'admin.group'],
    ['POST', '/admin/groups/{id:\d+}/delete', 'Modules\Admin\GroupController@destroy', 'admin.group'],

    /* ==================== 后台：用户 ==================== */
    ['GET',  '/admin/users',                 'Modules\Admin\UserController@index',  'admin.user'],
    ['POST', '/admin/users/bulk',            'Modules\Admin\UserController@bulk',   'admin.user'],
    ['GET',  '/admin/users/{id:\d+}',        'Modules\Admin\UserController@edit',   'admin.user'],
    ['POST', '/admin/users/{id:\d+}',        'Modules\Admin\UserController@update', 'admin.user'],
    ['POST', '/admin/users/{id:\d+}/moderates', 'Modules\Admin\UserController@moderates', 'admin.user'],
    ['POST', '/admin/users/{id:\d+}/ban',    'Modules\Admin\UserController@ban',    'user.ban'],
    ['POST', '/admin/users/{id:\d+}/unban',  'Modules\Admin\UserController@unban',  'user.ban'],

    /* ==================== 后台：内容与附件 ==================== */
    ['GET',  '/admin/threads',               'Modules\Admin\ContentController@threads',      'admin.content'],
    ['POST', '/admin/threads/bulk',          'Modules\Admin\ContentController@bulkThreads',  'admin.content'],
    ['POST', '/admin/threads/{id:\d+}/delete',  'Modules\Admin\ContentController@deleteThread', 'admin.content'],
    ['POST', '/admin/threads/{id:\d+}/approve', 'Modules\Admin\ContentController@approveThread', 'post.approve'],
    ['GET',  '/admin/posts',                 'Modules\Admin\ContentController@posts',        'admin.content'],
    ['POST', '/admin/posts/bulk',            'Modules\Admin\ContentController@bulkPosts',    'admin.content'],
    ['POST', '/admin/posts/{id:\d+}/delete',    'Modules\Admin\ContentController@deletePost',   'admin.content'],
    ['POST', '/admin/posts/{id:\d+}/approve',   'Modules\Admin\ContentController@approve',      'post.approve'],
    /* 回收站：与帖子/评论同属「内容管理」，权限沿用 admin.content */
    ['GET',  '/admin/recycle',               'Modules\Admin\ContentController@recycle',      'admin.content'],
    ['POST', '/admin/recycle/bulk',          'Modules\Admin\ContentController@bulkRecycle',  'admin.content'],
    ['GET',  '/admin/attachments',           'Modules\Admin\AttachmentController@index',     'attachment.manage'],
    ['POST', '/admin/attachments/bulk',      'Modules\Admin\AttachmentController@bulk',      'attachment.manage'],
    ['POST', '/admin/attachments/{id:\d+}/delete', 'Modules\Admin\AttachmentController@destroy', 'attachment.manage'],

    /* ==================== 后台：插件 ==================== */
    ['GET',  '/admin/plugins',                    'Modules\Admin\PluginController@index',      'admin.plugin'],
    ['POST', '/admin/plugins/{id}/enable',        'Modules\Admin\PluginController@enable',     'admin.plugin'],
    ['POST', '/admin/plugins/{id}/disable',       'Modules\Admin\PluginController@disable',    'admin.plugin'],
    ['POST', '/admin/plugins/{id}/uninstall',     'Modules\Admin\PluginController@uninstall',  'admin.plugin'],
    ['GET',  '/admin/plugins/{id}/config',        'Modules\Admin\PluginController@config',     'admin.plugin'],
    ['POST', '/admin/plugins/{id}/config',        'Modules\Admin\PluginController@saveConfig', 'admin.plugin'],
    ['POST', '/admin/plugins/upload',             'Modules\Admin\PluginController@upload',     'admin.plugin'],
    ['GET',  '/admin/plugin/{slug}',              'Modules\Admin\PluginController@page',       'admin.access'],
    ['GET',  '/plugin-file/{id}/{path:.+}',       'Modules\Admin\PluginController@asset'],

    /* ==================== 后台：计划任务与日志 ==================== */
    ['GET',  '/admin/cron',             'Modules\Admin\CronController@index',  'admin.cron'],
    ['POST', '/admin/cron/run',         'Modules\Admin\CronController@run',    'admin.cron'],
    ['POST', '/admin/cron/token',       'Modules\Admin\CronController@token',  'admin.cron'],
    ['POST', '/admin/cron/{id:\d+}/toggle', 'Modules\Admin\CronController@toggle', 'admin.cron'],
    ['GET',  '/admin/logs',             'Modules\Admin\LogController@index',   'admin.logs'],
    ['GET',  '/admin/logs/export',      'Modules\Admin\LogController@export',  'admin.logs'],
    ['GET',  '/admin/logs/system',      'Modules\Admin\LogController@system',  'admin.logs'],
    ['POST', '/admin/logs/system/clear', 'Modules\Admin\LogController@clearSystemLog', 'admin.logs'],

    /* ==================== 计划任务外部触发（供系统 crontab 调用） ==================== */
    ['GET',  '/cron/run',               'Modules\Admin\CronController@external', '', false],

    /* ==================== 插件合并资源 ==================== */
    ['GET', '/plugin-assets/{type}', 'Modules\Admin\PluginController@bundle'],
];

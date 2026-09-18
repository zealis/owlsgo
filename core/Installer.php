<?php
/**
 * 安装器
 *
 * 负责：环境自检 → 测试数据库连接 → 写入数据源配置 → 导入建表脚本 →
 *       初始化用户组/设置/版块 → 创建管理员 → 生成安装锁。
 *
 * 安全：
 *  - 数据库配置写入 storage/config/database.php，并提示用户该目录不应对外暴露
 *  - 安装完成后写 install.lock；锁存在时安装入口自动关闭
 *  - 管理员密码强制走 Security::hashPassword（bcrypt）
 */

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class Installer
{
    /** 安装锁 */
    public static function isLocked(): bool
    {
        return is_file(App::lockFile());
    }

    /* ------------------------------------------------------------------ */
    /*  环境自检                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 检查运行环境
     *
     * 每项包含两组信息，必须分开：
     *   - `level`    判定级别：必须 / 建议 / 可选。决定是否阻断安装、如何排序、用什么颜色。
     *   - `required` 展示文案：写给用户看的「要求」，例如 ">= 8.1"、"至少一个"、"必须"。
     *
     * 早期版本只有 `required` 一个字段，且拿它同时当级别用（判断是否等于「必须」），
     * 于是 required 写成 ">= 8.1" 的 PHP 版本项与写成 "至少一个" 的 PDO 驱动项
     * 被当成非必须项 —— PHP 版本不达标也允许安装。这里拆开后不会再出现该问题。
     *
     * @return list<array{name:string, ok:bool, current:string, required:string, level:string, hint:string}>
     */
    public static function requirements(): array
    {
        $drivers = class_exists('PDO') ? \PDO::getAvailableDrivers() : [];

        $dirs = [
            'storage'            => APP_STORAGE_DIR,
            'storage/cache'      => APP_STORAGE_DIR . '/cache',
            'storage/logs'       => APP_STORAGE_DIR . '/logs',
            'storage/sessions'   => APP_STORAGE_DIR . '/sessions',
            'storage/config'     => APP_STORAGE_DIR . '/config',
            'storage/uploads'    => APP_STORAGE_DIR . '/uploads',
        ];

        // 图像处理二选一：GD 与 Imagick 只要有一个即可
        $imageDrivers = [];
        if (extension_loaded('gd')) {
            $imageDrivers[] = 'GD';
        }
        if (extension_loaded('imagick')) {
            $imageDrivers[] = 'Imagick';
        }

        $checks = [
            [
                'name'     => 'PHP 版本',
                'ok'       => version_compare(PHP_VERSION, '8.1.0', '>='),
                'current'  => PHP_VERSION,
                'required' => '>= 8.1',
                'level'    => '必须',
                'hint'     => '本项目使用了 readonly、枚举、纤程等 PHP 8.1+ 特性，请升级 PHP。',
            ],
            [
                'name'     => 'PDO 扩展',
                'ok'       => class_exists('PDO'),
                'current'  => class_exists('PDO') ? '已启用' : '未启用',
                'required' => '必须',
                'level'    => '必须',
                'hint'     => '请在 php.ini 中启用 extension=pdo。',
            ],
            [
                'name'     => 'PDO 数据库驱动',
                'ok'       => count(array_intersect(['sqlite', 'mysql', 'pgsql'], $drivers)) > 0,
                'current'  => $drivers === [] ? '无' : implode(', ', $drivers),
                'required' => '至少一个',
                'level'    => '必须',
                'hint'     => '启用 pdo_sqlite / pdo_mysql / pdo_pgsql 之一。',
            ],
            [
                'name'     => 'mbstring 扩展（中文处理）',
                'ok'       => function_exists('mb_strlen'),
                'current'  => function_exists('mb_strlen') ? '已启用' : '未启用',
                'required' => '必须',
                'level'    => '必须',
                'hint'     => '标题与正文的中文长度计算、截断都直接调用 mb_* 函数，缺失会报错。',
            ],
            [
                'name'     => 'json 扩展',
                'ok'       => function_exists('json_encode'),
                'current'  => function_exists('json_encode') ? '已启用' : '未启用',
                'required' => '必须',
                'level'    => '必须',
                'hint'     => 'PHP 8 默认内置，若缺失请检查编译参数。',
            ],
            [
                'name'     => 'fileinfo 扩展（上传 MIME 检测）',
                'ok'       => class_exists('finfo'),
                'current'  => class_exists('finfo') ? '已启用' : '未启用',
                'required' => '建议',
                'level'    => '建议',
                'hint'     => '缺少时退化为基于 getimagesize 的 MIME 判断，附件类型校验会变宽松。',
            ],
            [
                'name'     => 'session 支持',
                'ok'       => function_exists('session_start'),
                'current'  => function_exists('session_start') ? '已启用' : '未启用',
                'required' => '必须',
                'level'    => '必须',
                'hint'     => '用于登录态与 CSRF 令牌。',
            ],
            [
                'name'     => 'curl 扩展（远程拉图）',
                'ok'       => function_exists('curl_init'),
                'current'  => function_exists('curl_init') ? '已启用' : '未启用',
                'required' => '可选',
                'level'    => '可选',
                'hint'     => '用于抓取远程图片、调用外部接口；未启用时相关功能自动跳过。',
            ],
            [
                'name'     => '图像处理（GD 或 Imagick）',
                'ok'       => $imageDrivers !== [],
                'current'  => $imageDrivers === [] ? '均未安装' : implode(' + ', $imageDrivers),
                'required' => '可选',
                'level'    => '可选',
                'hint'     => '当前版本并不依赖它：上传的图片一律按原文件保存（不解码、不转码），'
                    . '头像由内置 SVG 生成，未安装不影响任何功能。',
            ],
            [
                'name'     => 'openssl 扩展（邮件加密）',
                'ok'       => extension_loaded('openssl'),
                'current'  => extension_loaded('openssl') ? '已启用' : '未启用',
                'required' => '可选',
                'level'    => '可选',
                'hint'     => '用于 SMTP over TLS/SSL 发信；未启用时只能发无加密邮件。',
            ],
        ];

        foreach ($dirs as $label => $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }

            $checks[] = [
                'name'     => '目录可写：' . $label,
                'ok'       => is_dir($path) && is_writable($path),
                'current'  => is_dir($path) ? (is_writable($path) ? '可写' : '不可写') : '不存在',
                'required' => '必须',
                'level'    => '必须',
                'hint'     => '请给该目录 755/775 权限：' . $path,
            ];
        }

        /*
         * 「必须」项排到最前面，让用户一眼看到真正会拦截安装的问题，
         * 再去关心「建议 / 可选」那些锦上添花的扩展。
         * PHP 8 的排序是稳定的，所以同级别之间保持上面定义的原有顺序。
         */
        $order = ['必须' => 0, '建议' => 1, '可选' => 2];

        usort($checks, static function (array $a, array $b) use ($order): int {
            return ($order[$a['level']] ?? 9) <=> ($order[$b['level']] ?? 9);
        });

        return $checks;
    }

    /**
     * 环境是否满足安装条件
     *
     * 只认 `level === '必须'`。注意不能用 `required` 判断：
     * 那一列是给人看的文案（">= 8.1"、"至少一个"），拿它当级别会漏掉 PHP 版本这类硬性条件。
     */
    public static function requirementsPassed(): bool
    {
        foreach (self::requirements() as $check) {
            if (!$check['ok'] && $check['level'] === '必须') {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  数据库                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * 规范化安装表单提交的数据库配置
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function buildConfig(array $input): array
    {
        $driver = (string)($input['driver'] ?? 'sqlite');

        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new RuntimeException('不支持的数据库类型。');
        }

        $config = [
            'driver'   => $driver,
            'database' => trim((string)($input['database'] ?? '')),
            'host'     => trim((string)($input['host'] ?? '127.0.0.1')),
            'port'     => (int)($input['port'] ?? 0),
            'username' => trim((string)($input['username'] ?? '')),
            'password' => (string)($input['password'] ?? ''),
        ];

        if ($driver === 'sqlite') {
            /*
             * SQLite 统一使用固定文件名，不接受自定义。
             *
             * 理由：库文件名一旦可改，插件、备份脚本、文档与迁移工具之间就会出现
             * 不一致（它们都按默认名 owlsgo.sqlite 查找），反而制造故障点。
             * 想换路径只需改 storage/config/database.php 或 OWLSGO_DB_NAME 环境变量。
             */
            $config['database'] = 'owlsgo.sqlite';
            $config['host']     = '';
            $config['port']     = 0;
            $config['username'] = '';
            $config['password'] = '';
        } else {
            if ($config['database'] === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $config['database'])) {
                throw new RuntimeException('数据库名不合法（只允许字母、数字、下划线与短横线）。');
            }
            if ($config['username'] === '') {
                throw new RuntimeException('请填写数据库用户名。');
            }
            if ($config['port'] <= 0) {
                $config['port'] = $driver === 'mysql' ? 3306 : 5432;
            }
            if ($config['host'] === '') {
                $config['host'] = '127.0.0.1';
            }
        }

        return Database::normalizeConfig($config);
    }

    /**
     * 测试数据库连接
     *
     * @param array<string, mixed> $config
     * @return array{ok:bool, message:string, version:string}
     */
    public static function testConnection(array $config): array
    {
        try {
            Database::configure($config);
            $pdo = Database::connection();

            return [
                'ok'      => true,
                'message' => '连接成功。',
                'version' => (string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'message' => $e->getMessage(),
                'version' => '',
            ];
        }
    }

    /**
     * 执行完整安装流程
     *
     * @param array<string, mixed> $input 表单数据
     * @return array{ok:bool, message:string, admin_id?:int}
     */
    public static function install(array $input): array
    {
        if (self::isLocked()) {
            return ['ok' => false, 'message' => '站点已完成安装。如需重装，请先删除 storage/install.lock。'];
        }

        if (!self::requirementsPassed()) {
            return ['ok' => false, 'message' => '服务器环境不满足安装要求，请先解决红色项目。'];
        }

        /*
         * 许可与风险确认必须显式勾选。
         *
         * 前端把「开始安装」按钮设为禁用只是易用性提示，任何人改一下 DOM 就能绕过，
         * 所以真正的关卡放在这里：未经确认的安装请求一律拒绝。
         */
        if ((string)($input['agree_license'] ?? '') !== '1') {
            return ['ok' => false, 'message' => '请先完整阅读并同意软件使用条款。'];
        }

        if ((string)($input['confirm_fresh'] ?? '') !== '1') {
            return ['ok' => false, 'message' => '请确认这是一次全新安装。'];
        }

        try {
            $config = self::buildConfig($input);

            // 1) 连接测试
            $test = self::testConnection($config);
            if (!$test['ok']) {
                return ['ok' => false, 'message' => '数据库连接失败：' . $test['message']];
            }

            // 2) 校验管理员信息
            $adminName    = trim((string)($input['admin_username'] ?? ''));
            $adminMail    = trim((string)($input['admin_email'] ?? ''));
            $adminPass    = (string)($input['admin_password'] ?? '');
            $adminPass2   = (string)($input['admin_password_confirm'] ?? '');

            $validator = Validator::make(
                [
                    'username'         => $adminName,
                    'email'            => $adminMail,
                    'password'         => $adminPass,
                    'password_confirm' => $adminPass2,
                ],
                [
                    'username'         => 'required|username',
                    'email'            => 'required|email|max:191',
                    'password'         => 'required',
                    // 两次输入必须一致：管理员账号是整站的最高权限，
                    // 密码敲错一个字符就会把自己锁在门外，这里必须挡住
                    'password_confirm' => 'required|same:password',
                ],
                [
                    'username'         => '管理员用户名',
                    'email'            => '管理员邮箱',
                    'password'         => '管理员密码',
                    'password_confirm' => '管理员密码',
                ]
            )->validate();

            if ($validator->fails()) {
                return ['ok' => false, 'message' => $validator->firstError()];
            }

            // 一次列出全部未满足的密码要求，避免用户「改一条、报一条」来回试错
            $strengthErrors = Security::passwordStrengthErrors($adminPass);
            if ($strengthErrors !== []) {
                return ['ok' => false, 'message' => implode('；', $strengthErrors)];
            }

            // 3) 导入建表脚本
            self::importSchema($config['driver']);

            // 4) 写入数据源配置
            self::writeDatabaseConfig($config);

            // 5) 初始化基础数据
            Database::configure($config);

            self::seedUsergroups();
            self::seedSettings($input);
            self::seedForums();
            $adminId = self::createAdmin($adminName, $adminMail, $adminPass);
            self::seedWelcomeThread($adminId);

            // 6) 生成应用签名密钥
            Security::authKey();

            // 7) 写安装锁
            self::writeLock($config, $adminId);

            Cache::flush();

            return ['ok' => true, 'message' => '安装完成，已为你打开站点首页。', 'admin_id' => $adminId];
        } catch (\Throwable $e) {
            Logger::exception($e, 'install');

            return ['ok' => false, 'message' => '安装失败：' . $e->getMessage()];
        }
    }

    /**
     * 导入 sql/{driver}.sql
     */
    public static function importSchema(string $driver): void
    {
        $file = APP_ROOT . '/sql/' . $driver . '.sql';

        if (!is_file($file)) {
            throw new RuntimeException('找不到建表脚本：sql/' . $driver . '.sql');
        }

        $sql = (string)file_get_contents($file);

        foreach (PluginManager::splitStatements($sql) as $statement) {
            try {
                Database::statement($statement);
            } catch (\Throwable $e) {
                // 已存在的对象（重复安装）可以忽略
                $message = strtolower($e->getMessage());
                if (str_contains($message, 'already exists') || str_contains($message, 'duplicate')) {
                    continue;
                }

                throw new RuntimeException('执行建表语句失败：' . $e->getMessage());
            }
        }
    }

    /**
     * 写出数据库配置到 storage/config/database.php
     *
     * @param array<string, mixed> $config
     */
    public static function writeDatabaseConfig(array $config): void
    {
        $dir = APP_STORAGE_DIR . '/config';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建配置目录：' . $dir);
        }

        $export = var_export($config, true);

        $content = "<?php\n"
            . "/**\n"
            . " * 由安装向导自动生成的数据库配置。\n"
            . " * 本文件含真实凭据，请勿提交到版本库，也不要通过 Web 直接访问。\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "return " . $export . ";\n";

        $file = $dir . '/database.php';

        // 原子写入，避免并发安装写出半截文件
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException('无法写入数据库配置文件，请检查 storage/config 目录权限。');
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('保存数据库配置失败。');
        }

        @chmod($file, 0600);
    }

    /* ------------------------------------------------------------------ */
    /*  初始数据                                                            */
    /* ------------------------------------------------------------------ */

    /** 初始化用户组 */
    private static function seedUsergroups(): void
    {
        $now = time();

        $groups = [
            ['id' => 1, 'name' => '管理员', 'slug' => 'administrators', 'color' => '#F5222D', 'description' => '拥有全部权限，可进入后台', 'is_system' => 1, 'sort' => 1],
            ['id' => 2, 'name' => '版主', 'slug' => 'moderators', 'color' => '#FF7D00', 'description' => '负责版块内容管理与审核', 'is_system' => 1, 'sort' => 2],
            ['id' => 3, 'name' => '注册用户', 'slug' => 'members', 'color' => '#00A0E9', 'description' => '通过注册的普通用户', 'is_system' => 1, 'sort' => 3],
            ['id' => 4, 'name' => '游客', 'slug' => 'guests', 'color' => '#999999', 'description' => '未登录访客', 'is_system' => 1, 'sort' => 4],
            ['id' => 5, 'name' => '禁言用户', 'slug' => 'muted', 'color' => '#666666', 'description' => '被限制发言的用户', 'is_system' => 1, 'sort' => 5],
        ];

        foreach ($groups as $group) {
            $permissions = Permission::ofGroup((int)$group['id']);

            Database::insertIgnore('usergroups', [
                'id'          => $group['id'],
                'name'        => $group['name'],
                'slug'        => $group['slug'],
                'description' => $group['description'],
                'color'       => $group['color'],
                'icon'        => '',
                'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
                'is_system'   => $group['is_system'],
                'sort_order'  => $group['sort'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ], ['id']);
        }
    }

    /** 初始化站点设置 */
    private static function seedSettings(array $input): void
    {
        Settings::seedDefaults();

        $values = [
            'installed_at' => date('Y-m-d H:i:s'),
            'app_version'  => (string)config('app.version', '1.0.0'),
        ];

        if (trim((string)($input['site_name'] ?? '')) !== '') {
            $values['site_name'] = trim((string)$input['site_name']);
        }

        $values['register_enabled'] = (string)($input['register_enabled'] ?? '1');

        Settings::save($values);
    }

    /** 初始化默认版块 */
    private static function seedForums(): void
    {
        if (!Database::tableExists('forums')) {
            return;
        }

        $exists = Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('forums') . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
        );

        if ((int)$exists > 0) {
            return;
        }

        $now = time();

        $forums = [
            ['name' => '站务公告', 'slug' => 'notice', 'description' => '站点公告、规则与更新日志', 'icon' => 'megaphone', 'sort' => 1, 'allow_thread' => 0],
            ['name' => '技术交流', 'slug' => 'tech', 'description' => 'PHP、前端、数据库与运维经验分享', 'icon' => 'code', 'sort' => 2, 'allow_thread' => 1],
            ['name' => '经验分享', 'slug' => 'share', 'description' => '踩坑记录、工具推荐与效率技巧', 'icon' => 'bulb', 'sort' => 3, 'allow_thread' => 1],
            ['name' => '灌水闲聊', 'slug' => 'chat', 'description' => '轻松话题，请勿刷屏', 'icon' => 'coffee', 'sort' => 4, 'allow_thread' => 1],
        ];

        foreach ($forums as $forum) {
            Database::insert('forums', [
                'parent_id'        => 0,
                'name'             => $forum['name'],
                'slug'             => $forum['slug'],
                'description'      => $forum['description'],
                'icon'             => $forum['icon'],
                'announcement'     => '',
                'sort_order'       => $forum['sort'],
                'status'           => 1,
                'allow_thread'     => $forum['allow_thread'],
                'allow_reply'      => 1,
                'allow_attachment' => 1,
                'group_view'       => '',
                'group_thread'     => $forum['allow_thread'] === 1 ? '' : '1,2',
                'group_reply'      => '',
                'moderators'       => '',
                'thread_count'     => 0,
                'post_count'       => 0,
                'last_thread_id'   => 0,
                'last_thread_name' => '',
                'last_reply_at'    => 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }

    /** 创建管理员账号 */
    private static function createAdmin(string $username, string $email, string $password): int
    {
        $now = time();

        $existing = Database::value(
            'SELECT ' . Database::identifier('id') . ' FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('username') . ' = ?',
            [$username]
        );

        if ($existing !== null) {
            throw new RuntimeException('用户名「' . e($username) . '」已存在，请换一个。');
        }

        return Database::insert('users', [
            'username'       => $username,
            'email'          => $email,
            'password_hash'  => Security::hashPassword($password),
            'group_id'       => Permission::SUPER_GROUP,
            'avatar'         => '',
            'signature'      => '',
            'bio'            => '',
            'location'       => '',
            'points'         => 100,
            'thread_count'   => 0,
            'post_count'     => 0,
            'favorite_count' => 0,
            'status'         => 1,
            'register_ip'    => Request::ip(),
            'last_login_ip'  => '',
            'last_login_at'  => 0,
            'last_active_at' => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }

    /** 发布欢迎主题，让首页不至于是空的 */
    private static function seedWelcomeThread(int $adminId): void
    {
        $forum = Database::first(
            'SELECT * FROM ' . Database::identifier('forums')
            . ' WHERE ' . Database::identifier('slug') . ' = ? LIMIT 1',
            ['notice']
        );

        if ($forum === null) {
            return;
        }

        $now = time();

        $content = "欢迎使用 **owlsgo**！\n\n"
            . "这是一个纯原生 PHP、零依赖的轻量论坛系统：\n\n"
            . "- 单核心代码架构，无 Composer 依赖，上传即用\n"
            . "- 同时支持 SQLite、MySQL 与 PostgreSQL\n"
            . "- 内置用户组权限、版块权限、插件机制与计划任务\n"
            . "- 前端基于 OATUI，QQ 经典蓝白配色，完整适配移动端\n\n"
            . "请第一时间前往后台修改站点名称、关闭注册或调整权限：[进入后台](/admin)";

        $threadId = Database::insert('threads', [
            'forum_id'           => (int)$forum['id'],
            'user_id'            => $adminId,
            'title'              => '欢迎来到 owlsgo（新手指引）',
            'views'              => 0,
            'reply_count'        => 0,
            'like_count'         => 0,
            'favorite_count'     => 0,
            'is_pinned'          => 1,
            'is_essence'         => 1,
            'is_locked'          => 0,
            'is_recommended'     => 1,
            'status'             => 1,
            'last_reply_at'      => $now,
            'last_reply_user_id' => $adminId,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        Database::insert('posts', [
            'thread_id'    => $threadId,
            'forum_id'     => (int)$forum['id'],
            'user_id'      => $adminId,
            'parent_id'    => 0,
            'floor'        => 1,
            'is_first'     => 1,
            'content'      => $content,
            'content_html' => Text::toHtml($content),
            'like_count'   => 0,
            'status'       => 1,
            'ip'           => Request::ip(),
            'device'       => 'desktop',
            'user_agent'   => 'Installer',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        Database::update('forums', [
            'thread_count'     => 1,
            'post_count'       => 1,
            'last_thread_id'   => $threadId,
            'last_thread_name' => '欢迎来到 owlsgo（新手指引）',
            'last_reply_at'    => $now,
            'updated_at'       => $now,
        ], Database::identifier('id') . ' = ?', [(int)$forum['id']]);
    }

    /** 写入安装锁 */
    private static function writeLock(array $config, int $adminId): void
    {
        $payload = [
            'installed_at' => date('c'),
            'php_version'  => PHP_VERSION,
            'app_version'  => (string)config('app.version', '1.0.0'),
            'driver'       => $config['driver'],
            'admin_id'     => $adminId,
            'admin_ip'     => Request::ip(),
        ];

        $file = App::lockFile();
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (@file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new RuntimeException('无法写入安装锁文件 storage/install.lock');
        }
    }
}

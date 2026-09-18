<?php
/**
 * 插件管理器
 *
 * 生命周期：
 *   1. discover()      扫描 plugins/ 目录下的 plugin.json
 *   2. syncDatabase()  与 plugins 表对齐（新增/更新元数据，保留启用状态与配置）
 *   3. loadEnabled()   加载已启用插件的入口文件并调用 register()，完成 Hook / 路由 / 菜单注册
 *   4. mergeAssets()   把启用插件的 CSS/JS 合并为单文件，减少请求数
 *   5. install()/uninstall()/enable()/disable()  后台管理动作
 *   6. runCron()       执行插件注册的计划任务
 *
 * 安全边界：插件入口文件在执行前必须通过目录校验（必须位于 plugins/ 下），
 * 且只能通过 Core\Plugin 门面注册能力。
 */

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class PluginManager
{
    /** @var array<string, array<string, mixed>> 已发现的插件清单 */
    private static array $discovered = [];

    /** @var list<string> 已加载的插件 ID */
    private static array $loaded = [];

    /** @var array<string, int> 每个插件目录的文件指纹，用于判断是否需要重建合并资源 */
    private static array $assetSignature = [];

    /** 合并后的插件资源文件名 */
    private const CSS_BUNDLE = 'plugins.css';
    private const JS_BUNDLE  = 'plugins.js';

    /* ------------------------------------------------------------------ */
    /*  发现与同步                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * 扫描插件目录
     *
     * @return array<string, array<string, mixed>>
     */
    public static function discover(bool $refresh = false): array
    {
        if (self::$discovered !== [] && !$refresh) {
            return self::$discovered;
        }

        self::$discovered = [];

        $base = APP_ROOT . '/plugins';
        if (!is_dir($base)) {
            return self::$discovered;
        }

        foreach ((array)glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            $id = basename($dir);

            // 插件 ID 只允许小写字母、数字、下划线、短横线，防止目录穿越
            if (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,31}$/', $id)) {
                Logger::warning('忽略非法插件目录：' . $id);
                continue;
            }

            $manifest = $dir . '/plugin.json';
            if (!is_file($manifest)) {
                continue;
            }

            $meta = json_decode((string)file_get_contents($manifest), true);
            if (!is_array($meta)) {
                Logger::warning('插件清单解析失败：' . $id);
                continue;
            }

            $meta['id']   = $id;
            $meta['path'] = $dir;

            self::$discovered[$id] = self::normalizeMeta($meta);
        }

        return self::$discovered;
    }

    /**
     * 规范化插件清单字段，填充默认值
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function normalizeMeta(array $meta): array
    {
        return [
            'id'          => (string)$meta['id'],
            'name'        => (string)($meta['name'] ?? $meta['id']),
            'version'     => (string)($meta['version'] ?? '1.0.0'),
            'description' => (string)($meta['description'] ?? ''),
            'author'      => (string)($meta['author'] ?? ''),
            'url'         => (string)($meta['url'] ?? ''),
            'entry'       => (string)($meta['entry'] ?? 'Plugin.php'),
            'namespace'   => trim((string)($meta['namespace'] ?? ''), '\\'),
            'hooks'       => is_array($meta['hooks'] ?? null) ? $meta['hooks'] : [],
            'assets'      => is_array($meta['assets'] ?? null) ? $meta['assets'] : ['css' => [], 'js' => []],
            'settings'    => is_array($meta['settings'] ?? null) ? $meta['settings'] : [],
            'requires'    => (string)($meta['requires'] ?? '1.0.0'),
            'path'        => (string)$meta['path'],
        ];
    }

    /**
     * 把磁盘上的插件与数据库记录对齐
     *
     * @return array<string, array<string, mixed>> 插件清单（含 enabled 状态）
     */
    public static function syncDatabase(): array
    {
        $plugins = self::discover();

        /*
         * 未安装时直接返回磁盘清单，不碰数据库。
         *
         * 原因：SQLite 的驱动在连接一个不存在的文件时会「自动创建」它，
         * 所以只要安装前访问过一次页面，storage/database/ 下就会凭空多出一个
         * 空库文件，让「干净的未安装状态」失效。这里提前返回即可避免。
         */
        if (!App::isInstalled()) {
            return $plugins;
        }

        try {
            $rows = Database::select(
                'SELECT ' . implode(',', array_map([Database::class, 'identifier'], ['id', 'enabled', 'config']))
                . ' FROM ' . Database::identifier('plugins')
            );
        } catch (\Throwable $e) {
            // 数据库尚未初始化（安装阶段）时直接返回磁盘清单。
            // 安装前「表不存在」是预期状态，只记 debug，避免用无意义的 warning 吓人；
            // 已安装却读不到插件表，那才是真问题，如实记 warning。
            if (App::isInstalled()) {
                Logger::warning('插件表不可用：' . $e->getMessage());
            } else {
                Logger::debug('插件表尚未创建（安装阶段）：' . $e->getMessage());
            }

            return $plugins;
        }

        $existing = [];
        foreach ($rows as $row) {
            $existing[(string)$row['id']] = $row;
        }

        $now = time();

        foreach ($plugins as $id => &$meta) {
            if (isset($existing[$id])) {
                $meta['enabled'] = (int)$existing[$id]['enabled'] === 1;
                $meta['config']  = json_decode((string)$existing[$id]['config'], true) ?: [];

                // 同步元数据（版本升级后名称、描述可能变化）
                Database::update('plugins', [
                    'name'        => $meta['name'],
                    'version'     => $meta['version'],
                    'description' => $meta['description'],
                    'author'      => $meta['author'],
                    'url'         => $meta['url'],
                    'hooks'       => implode(',', array_map('strval', $meta['hooks'])),
                    'updated_at'  => $now,
                ], Database::identifier('id') . ' = ?', [$id]);
            } else {
                // 新发现的插件默认不启用，须由管理员在后台显式启用
                Database::insertIgnore('plugins', [
                    'id'           => $id,
                    'name'         => $meta['name'],
                    'version'      => $meta['version'],
                    'description'  => $meta['description'],
                    'author'       => $meta['author'],
                    'url'          => $meta['url'],
                    'enabled'      => 0,
                    'is_system'    => 0,
                    'config'       => '{}',
                    'hooks'        => implode(',', array_map('strval', $meta['hooks'])),
                    'sort_order'   => 0,
                    'installed_at' => $now,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ], ['id']);

                $meta['enabled'] = false;
                $meta['config']  = [];
            }
        }
        unset($meta);

        // 数据库中已不存在于磁盘的插件标记为禁用（不删数据，便于恢复）
        foreach ($existing as $id => $row) {
            if (!isset($plugins[$id]) && (int)$row['enabled'] === 1) {
                Database::update('plugins', ['enabled' => 0, 'updated_at' => $now], Database::identifier('id') . ' = ?', [$id]);
            }
        }

        self::$discovered = $plugins;

        return $plugins;
    }

    /**
     * 已启用的插件
     *
     * @return array<string, array<string, mixed>>
     */
    public static function enabled(): array
    {
        return array_filter(self::syncDatabase(), static fn (array $meta): bool => (bool)($meta['enabled'] ?? false));
    }

    /* ------------------------------------------------------------------ */
    /*  加载                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 加载所有已启用插件的入口文件并执行 register()
     */
    public static function loadEnabled(): void
    {
        if (self::$loaded !== []) {
            return;
        }

        foreach (self::enabled() as $id => $meta) {
            self::load($id, $meta);
        }
    }

    /**
     * 加载单个插件
     *
     * @param array<string, mixed> $meta
     */
    public static function load(string $id, array $meta): bool
    {
        if (in_array($id, self::$loaded, true)) {
            return true;
        }

        $entry = self::safePath($id, (string)$meta['entry']);

        if ($entry === null) {
            Logger::error('插件入口文件不安全或不存在：' . $id);

            return false;
        }

        Plugin::setCurrent($id);

        try {
            require_once $entry;

            // 若清单声明了入口类，调用它的 register() 静态方法
            $class = self::resolveClass($meta);
            if ($class !== '' && class_exists($class) && method_exists($class, 'register')) {
                $class::register();
            }

            // 插件可通过 register.php 直接使用 Plugin 门面注册（无需类）
            $register = self::safePath($id, 'register.php');
            if ($register !== null) {
                require_once $register;
            }

            self::$loaded[] = $id;
            do_action('plugin_loaded', ['plugin' => $id]);

            return true;
        } catch (\Throwable $e) {
            Logger::exception($e, 'plugin:' . $id);

            return false;
        } finally {
            Plugin::setCurrent('');
        }
    }

    /** 解析插件入口类名 */
    private static function resolveClass(array $meta): string
    {
        $namespace = (string)($meta['namespace'] ?? '');
        if ($namespace === '') {
            return '';
        }

        return $namespace . '\\Plugin';
    }

    /** 已加载的插件 ID */
    public static function loadedIds(): array
    {
        return self::$loaded;
    }

    /**
     * 校验并解析插件内文件路径（阻断目录穿越）
     */
    public static function safePath(string $pluginId, string $relative): ?string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,31}$/', $pluginId)) {
            return null;
        }

        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '..') || !preg_match('#^[A-Za-z0-9_\-./]+$#', $relative)) {
            return null;
        }

        $base     = realpath(APP_ROOT . '/plugins/' . $pluginId);
        if ($base === false) {
            return null;
        }

        $absolute = realpath($base . '/' . ltrim($relative, '/'));

        if ($absolute === false || !str_starts_with($absolute, $base)) {
            return null;
        }

        return is_file($absolute) ? $absolute : null;
    }

    /* ------------------------------------------------------------------ */
    /*  资源合并                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 合并启用插件的所有 CSS / JS
     *
     * 仅在插件资源发生变化时重建，避免每次请求都做磁盘写入。
     */
    public static function mergeAssets(bool $force = false): void
    {
        /*
         * 未安装时直接返回。
         *
         * 这里是「未安装也要写文件」的另一个源头：即使一个插件都没启用，
         * 下面的代码也会往 storage/cache/ 写下 plugins.css、plugins.js
         * 和 .signature 三个只有注释头的空文件。
         *
         * 注意不能改成「无插件就返回」——已安装状态下，那三个文件的空写
         * 是禁用全部插件后清理旧内容的必要动作（hasAssets() 靠文件大小
         * 判断是否输出 <link>），必须保留。
         */
        if (!App::isInstalled()) {
            return;
        }

        $plugins = self::enabled();

        $signature = '';
        foreach ($plugins as $id => $meta) {
            foreach (['css', 'js'] as $type) {
                foreach ((array)($meta['assets'][$type] ?? []) as $file) {
                    $path = self::safePath($id, (string)$file);
                    if ($path !== null) {
                        $signature .= $path . ':' . filemtime($path) . ';';
                    }
                }
            }
        }

        $signature = md5($signature);
        $stampFile = self::assetDir() . '/.signature';

        if (!$force && is_file($stampFile) && trim((string)file_get_contents($stampFile)) === $signature) {
            return;
        }

        $dir = self::assetDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $css = ["/* owlsgo 插件样式合并文件，由系统自动生成，请勿手动修改 */\n"];
        $js  = ["/* owlsgo 插件脚本合并文件，由系统自动生成，请勿手动修改 */\n"];

        foreach ($plugins as $id => $meta) {
            foreach (['css', 'js'] as $type) {
                foreach ((array)($meta['assets'][$type] ?? []) as $file) {
                    $path = self::safePath($id, (string)$file);
                    if ($path === null) {
                        continue;
                    }

                    $content = (string)file_get_contents($path);
                    $banner  = "\n/* ---- plugin: " . $id . " / " . basename($path) . " ---- */\n";

                    if ($type === 'css') {
                        // 简单收敛：把插件样式限制在 body 作用域之外不做处理，但禁止 @import 远程资源
                        $content = preg_replace('/@import[^;]+;/i', '', $content) ?? $content;
                        $css[]   = $banner . $content;
                    } else {
                        $js[] = $banner . $content;
                    }
                }
            }
        }

        @file_put_contents($dir . '/' . self::CSS_BUNDLE, implode("\n", $css), LOCK_EX);
        @file_put_contents($dir . '/' . self::JS_BUNDLE, implode("\n", $js), LOCK_EX);
        @file_put_contents($stampFile, $signature, LOCK_EX);
    }

    /** 合并后的资源文件绝对路径 */
    public static function bundlePath(string $type): ?string
    {
        $file = self::assetDir() . '/' . ($type === 'js' ? self::JS_BUNDLE : self::CSS_BUNDLE);

        return is_file($file) ? $file : null;
    }

    /** 合并资源是否非空（用于决定是否输出 <link>/<script>） */
    public static function hasAssets(): bool
    {
        foreach (['css', 'js'] as $type) {
            $file = self::bundlePath($type);
            if ($file !== null && filesize($file) > 120) {
                return true;
            }
        }

        return false;
    }

    private static function assetDir(): string
    {
        return APP_ROOT . '/storage/cache';
    }

    /* ------------------------------------------------------------------ */
    /*  后台管理动作                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * 启用插件（会执行插件的 install 流程：建表 + 初始化配置）
     */
    public static function enable(string $id): void
    {
        $plugins = self::discover(true);

        if (!isset($plugins[$id])) {
            throw new RuntimeException('插件不存在：' . $id);
        }

        self::runInstallSql($id);

        Database::update('plugins', ['enabled' => 1, 'updated_at' => time()], Database::identifier('id') . ' = ?', [$id]);

        self::load($id, $plugins[$id]);
        self::mergeAssets(true);
        self::syncCronTasks();

        Logger::info('启用插件：' . $id);
    }

    /** 停用插件（保留数据与配置） */
    public static function disable(string $id): void
    {
        Database::update('plugins', ['enabled' => 0, 'updated_at' => time()], Database::identifier('id') . ' = ?', [$id]);

        self::mergeAssets(true);
        self::syncCronTasks();

        Logger::info('停用插件：' . $id);
    }

    /**
     * 卸载插件（删除数据库记录与计划任务；插件自建表需由插件自行说明是否清理）
     */
    public static function uninstall(string $id): void
    {
        Database::delete('plugins', Database::identifier('id') . ' = ?', [$id]);
        Database::delete('cron_tasks', Database::identifier('plugin') . ' = ?', [$id]);

        self::mergeAssets(true);

        Logger::info('卸载插件：' . $id);
    }

    /**
     * 执行插件自带的建表脚本 sql/{driver}.sql
     */
    private static function runInstallSql(string $id): void
    {
        $file = self::safePath($id, 'sql/' . Database::driver() . '.sql');

        if ($file === null) {
            return;
        }

        $sql = (string)file_get_contents($file);

        foreach (self::splitStatements($sql) as $statement) {
            try {
                Database::statement($statement);
            } catch (\Throwable $e) {
                Logger::exception($e, 'plugin-install:' . $id);
            }
        }
    }

    /**
     * 将 SQL 脚本拆分为可独立执行的语句
     *
     * 说明：本系统不使用触发器/存储过程，因此按分号拆分是安全的；
     * 同时会跳过注释行与空语句。
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines     = preg_split('/\r\n|\n|\r/', $sql) ?: [];
        $buffer    = [];
        $statements = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $buffer[] = $line;

            if (str_ends_with($trimmed, ';')) {
                $statement = trim(implode("\n", $buffer));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = [];
            }
        }

        $remainder = trim(implode("\n", $buffer));
        if ($remainder !== '') {
            $statements[] = $remainder;
        }

        return $statements;
    }

    /* ------------------------------------------------------------------ */
    /*  计划任务                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 把插件注册的计划任务同步到 cron_tasks 表
     */
    public static function syncCronTasks(): void
    {
        $now = time();

        foreach (Plugin::crons() as $task) {
            Database::upsert('cron_tasks', [
                'plugin'      => $task['plugin'],
                'name'        => $task['name'],
                'interval'    => (int)$task['interval'],
                'next_run_at' => $now + (int)$task['interval'],
                'enabled'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ], ['plugin', 'name']);
        }
    }

    /**
     * 执行所有到期的计划任务
     *
     * @return list<array{name:string, status:string, message:string, duration:int}>
     */
    public static function runCron(int $limit = 10): array
    {
        $results = [];
        $now     = time();

        $tasks = Database::select(
            'SELECT * FROM ' . Database::identifier('cron_tasks')
            . ' WHERE ' . Database::identifier('enabled') . ' = 1 AND ' . Database::identifier('next_run_at') . ' <= ?'
            . ' ORDER BY ' . Database::identifier('next_run_at') . ' ASC LIMIT ' . max(1, min(50, $limit)),
            [$now]
        );

        // 以「任务名 => 处理器」建立索引
        $handlers = [];
        foreach (Plugin::crons() as $task) {
            $handlers[$task['plugin'] . '::' . $task['name']] = $task['handler'];
        }

        foreach ($tasks as $task) {
            $key     = $task['plugin'] . '::' . $task['name'];
            $started = microtime(true);
            $status  = 'ok';
            $message = '';

            if (!isset($handlers[$key])) {
                $status  = 'skip';
                $message = '处理器未注册（插件可能已停用）';
            } else {
                try {
                    self::callHandler($handlers[$key]);
                } catch (\Throwable $e) {
                    $status  = 'error';
                    $message = $e->getMessage();
                    Logger::exception($e, 'cron:' . $key);
                }
            }

            $duration = (int)round((microtime(true) - $started) * 1000);
            $interval = max(60, (int)$task['interval']);

            Database::update('cron_tasks', [
                'last_run_at' => $now,
                'next_run_at' => $now + $interval,
                'last_status' => $status,
                'run_count'   => (int)$task['run_count'] + 1,
                'updated_at'  => $now,
            ], Database::identifier('id') . ' = ?', [(int)$task['id']]);

            Database::insert('cron_logs', [
                'name'       => $key,
                'status'     => $status,
                'message'    => mb_substr($message, 0, 400),
                'duration'   => $duration,
                'created_at' => $now,
            ]);

            $results[] = [
                'name'     => $key,
                'status'   => $status,
                'message'  => $message,
                'duration' => $duration,
            ];
        }

        return $results;
    }

    /**
     * 调用插件提供的处理器（支持 callable 与 "Class@method" 字符串）
     */
    public static function callHandler(callable|string $handler): mixed
    {
        if (is_callable($handler)) {
            return $handler();
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            if (class_exists($class) && method_exists($class, $method)) {
                return $class::$method();
            }
        }

        throw new RuntimeException('无法调用插件处理器。');
    }

    /** 清理旧的计划任务日志 */
    public static function pruneCronLogs(int $days = 7): int
    {
        return Database::delete(
            'cron_logs',
            Database::identifier('created_at') . ' < ?',
            [time() - max(1, $days) * 86400]
        );
    }
}

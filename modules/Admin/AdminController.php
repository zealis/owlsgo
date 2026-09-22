<?php
/**
 * 后台：概览与站点设置
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\App;
use Core\Auth;
use Core\Cache;
use Core\Database;
use Core\PluginManager;
use Core\Request;
use Core\Response;
use Core\Router;
use Core\Settings;
use Modules\Forum\ForumModel;
use Modules\Post\PostModel;
use Modules\Thread\ThreadModel;
use Modules\User\AttachmentModel;
use Modules\User\NotificationModel;
use Modules\User\UsergroupModel;
use Modules\User\UserModel;

final class AdminController extends AdminBaseController
{
    /*
     * 站点设置的分组与「键 → 处理方式」全部登记在 SettingsPages 里（单一事实来源）：
     * 侧栏下拉、每个分组页渲染哪些字段、保存时处理哪些键，三处共用同一份定义。
     * 分组之后表单是「部分表单」，保存时**必须**按当前分组的键收窄 ——
     * 否则把「附件」页一存，其它页未提交的布尔开关会被写成 0。
     */

    /**
     * 后台概览
     *
     * @param array<string, string> $params
     */
    public function dashboard(array $params): string
    {
        $todayStart  = strtotime('today') ?: (time() - 86400);
        $attachments = AttachmentModel::stats();

        /*
         * 「运行环境」与「最近操作」属于系统运维信息，按权限决定可见性：
         *  - 运行环境（PHP / Web 服务器 / 数据库 / 调试模式 / SQL 次数）→ 需要「站点设置」；
         *  - 最近操作（全站审计日志）→ 需要「查看日志」。
         * 管理员组默认两项都有；版主等内容角色默认都看不到，概览只剩内容相关的卡片。
         * 无权限时**连查询都不发起**（而不是查完不渲染），免得白跑一次日志表查询。
         */
        $canSystem = Auth::can('admin.settings');
        $canLogs   = Auth::can('admin.logs');

        return $this->adminView('admin/dashboard', [
            'pageTitle'  => '后台概览 - ' . (string)setting('site_name'),
            'adminTitle' => '概览',

            'stats' => [
                'users'           => UserModel::totalCount(),
                'threads'         => ThreadModel::totalCount(),
                'posts'           => PostModel::totalCount(),
                'attachments'     => (int)$attachments['total'],
                'today_users'     => UserModel::countSince($todayStart),
                'today_threads'   => ThreadModel::countSince($todayStart),
                'today_posts'     => PostModel::countSince($todayStart),
                'pending_threads' => ThreadModel::pendingCount(),
                'pending_posts'   => PostModel::pendingCount(),
                'bans'            => BanModel::activeCount(),
                'plugins'         => count(PluginManager::enabled()),
                'cron_due'        => CronModel::dueCount(),
            ],

            'forumStats'    => ForumModel::stats(),
            'recentUsers'   => UserModel::decorateMany(UserModel::recent(8)),
            'recentThreads' => ThreadModel::decorate(ThreadModel::latest(8)),

            'canSystem'     => $canSystem,
            'canLogs'       => $canLogs,
            'recentLogs'    => $canLogs ? LogModel::recent(10) : [],
            'system'        => $canSystem ? $this->systemInfo($attachments) : [],
        ]);
    }

    /**
     * 清理 OPcache（PHP 字节码缓存）
     *
     * 为什么需要它：服务器一旦开启 opcache 且没有配置按时间校验源文件，
     * 改完 PHP 源码后旧代码仍会继续被使用，表现就是「明明改了文件，页面却没变」。
     * 这个接口用来手动清一次，省得去重启 PHP-FPM。
     */
    public function clearOpcache(array $params): never
    {
        if (!function_exists('opcache_reset')) {
            $this->json([
                'ok'      => false,
                'message' => '当前 PHP 未启用 OPcache 扩展，无需清理。',
            ], 400);
        }

        $reset = @opcache_reset();

        // 顺手清掉站点自己的文件缓存（设置 / 版块 / 用户组）。
        // 它和 OPcache 一样会造成「改了配置但页面没变」的错觉，一起清更省事。
        Cache::flush();

        $this->json([
            'ok'      => true,
            'message' => $reset
                ? 'OPcache 已清理，站点缓存也已清空。'
                : '站点缓存已清空；OPcache 未能在本次请求中重置（部分环境不允许，可重启 PHP-FPM 后再试）。',
        ]);
    }

    /**
     * 站点设置（一个分组一页）
     *
     * 路由：`/admin/settings/{group}`；裸地址 `/admin/settings` 重定向到默认分组，
     * 这样地址栏里的分组、侧栏高亮与面包屑永远是同一个事实。
     *
     * @param array<string, string> $params
     */
    public function settings(array $params): string
    {
        $slug = trim((string)($params['group'] ?? ''));

        if ($slug === '') {
            Response::redirect(Router::url(SettingsPages::path(SettingsPages::DEFAULT_SLUG)));
        }

        /* 旧的分组地址（站点开关 / 系统维护）合并后重定向到新页面，别直接 404 */
        $canonical = SettingsPages::canonical($slug);

        if ($canonical !== $slug) {
            Response::redirect(Router::url(SettingsPages::path($canonical)));
        }

        if (!SettingsPages::has($slug)) {
            App::abort(404, '设置分组不存在。');
        }

        $page = SettingsPages::meta($slug);

        return $this->adminView('admin/settings', [
            'pageTitle'    => $page['label'] . ' - 站点设置 - ' . (string)setting('site_name'),
            'adminTitle'   => $page['label'],
            'adminSubtitle' => '站点设置',
            'settingSlug'  => $slug,
            'settingPage'  => $page,
            'settings'     => Settings::all(),
            'groups'       => UsergroupModel::options(),
            'uploadMax'    => (string)ini_get('upload_max_filesize'),
            'postMax'      => (string)ini_get('post_max_size'),
            'uploadDirSize' => format_size(AttachmentModel::stats()['bytes']),
            'opcacheAvailable' => function_exists('opcache_reset'),
            'debugEnabled'     => \Core\App::debug(),
        ]);
    }

    /**
     * 保存某个分组的站点设置
     *
     * 只处理当前分组登记的键：分组之后提交上来的是「部分表单」，
     * 全量遍历会让「这个页面里没有的开关」被当成未勾选而写 0。
     *
     * @param array<string, string> $params
     */
    public function saveSettings(array $params): never
    {
        // 兼容旧表单地址 POST /admin/settings（无分组）：按默认分组处理
        $slug = trim((string)($params['group'] ?? ''));
        $slug = $slug === '' ? SettingsPages::DEFAULT_SLUG : $slug;

        if (!SettingsPages::has($slug)) {
            App::abort(404, '设置分组不存在。');
        }

        /*
         * 门槛是「这一组有没有登记键」而不是 form 标志：
         * 「系统与维护」页为了放两个独立表单（保存开关 / 清 OPcache）不套外壳表单，
         * 但它确实有可保存的设置项。
         */
        if (SettingsPages::keys($slug) === []) {
            App::abort(404, '该页面没有可提交的设置表单。');
        }

        $input   = Request::allPost();
        $current = Settings::all();
        $back    = Router::url(SettingsPages::path($slug));

        $values = [];

        /* ---------- 文本项（本分组登记的才处理，未提交时保留库里的值） ---------- */
        foreach (SettingsPages::textKeys($slug) as $key => $limit) {
            $values[$key] = mb_substr(trim((string)($input[$key] ?? ($current[$key] ?? ''))), 0, $limit);
        }

        /* ---------- 允许上传的扩展名（白名单化） ---------- */
        foreach (SettingsPages::extKeys($slug) as $key) {
            $values[$key] = $this->normalizeExtensions((string)($input[$key] ?? ($current[$key] ?? '')));
        }

        /*
         * 「管理员邮箱」与「全站公告」已下线：
         * 前者的校验与保存一并移除，后者改造成「全站通知」并迁到通知中心管理
         * （见 Modules\Notice\NoticeController），这里不再接收这两个字段。
         *
         * 「外观」三个颜色键（theme_primary / theme_primary_dark / theme_highlight）
         * 也已随后台设置页的「外观」区块一并下线：站点配色由 theme.css 固定令牌控制，
         * 表单不再提交、这里不再校验与保存。
         */

        /* ---------- 校验：只在本页确实有该字段时执行 ---------- */
        if (array_key_exists('site_name', $values) && $values['site_name'] === '') {
            $this->backWithErrors(['site_name' => '站点名称不能为空。'], $back);
        }

        // 站点地址若填写则必须是合法 URL（留空表示自动使用当前域名）
        if (array_key_exists('site_url', $values)
            && $values['site_url'] !== ''
            && !filter_var($values['site_url'], FILTER_VALIDATE_URL)) {
            $this->backWithErrors(['site_url' => '站点地址格式不正确，请填写完整 URL（含 http:// 或 https://）。'], $back);
        }

        /* ---------- 布尔项 ---------- */
        foreach (SettingsPages::boolKeys($slug) as $key) {
            $values[$key] = Request::bool($key) ? '1' : '0';
        }

        /* ---------- 数值项 ---------- */
        foreach (SettingsPages::intKeys($slug) as $key => [$min, $max]) {
            $value = (int)($input[$key] ?? ($current[$key] ?? 0));
            $values[$key] = (string)max($min, min($max, $value));
        }

        // 兜底：注册用户组必须真实存在，否则回退到「注册用户」
        if (isset($values['register_group'])
            && !isset(UsergroupModel::options()[(int)$values['register_group']])) {
            $values['register_group'] = '3';
        }

        // 发帖长度上下限必须合理，避免出现 min > max 导致所有帖子都发不出去
        if (isset($values['post_min_length'], $values['post_max_length'])
            && (int)$values['post_min_length'] > (int)$values['post_max_length']) {
            $values['post_min_length'] = '2';
            $values['post_max_length'] = '20000';
        }

        Settings::save($values);
        Cache::flush();

        $label = SettingsPages::label($slug);

        $this->audit('settings.save', 'settings', '更新站点设置 · ' . $label);

        $message = '「' . $label . '」设置已保存。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 系统信息（概览页右栏）
     *
     * @param array{total:int, bytes:int} $attachments
     * @return array<string, string|bool>
     */
    private function systemInfo(array $attachments): array
    {
        return [
            'php_version'   => PHP_VERSION,
            'server'        => (string)($_SERVER['SERVER_SOFTWARE'] ?? 'CLI'),
            'driver'        => Database::driver(),
            'db_size'       => $this->databaseSize(),
            'app_version'   => (string)config('app.version', '1.0.0'),
            'installed_at'  => (string)Settings::get('installed_at', '未记录'),
            'upload_size'   => format_size((int)$attachments['bytes']),
            'upload_files'  => (string)(int)$attachments['total'],
            'debug'         => \Core\App::debug(),
            'sql_queries'   => (string)Database::queryCount(),
            'url_style'     => Router::queryStyle() ? 'index.php?r=（兼容模式）' : '伪静态',
        ];
    }

    /** 数据库占用（SQLite 可统计文件大小，其余引擎交由服务器管理） */
    private function databaseSize(): string
    {
        if (Database::driver() !== 'sqlite') {
            return '由数据库服务器管理';
        }

        $file = Database::sqlitePath((string)config('database.database', 'owlsgo.sqlite'));

        if (!is_file($file)) {
            return '—';
        }

        $size = (int)filesize($file);

        // WAL 模式下未合并的数据在 -wal 文件中，一并计入更接近真实占用
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($file . $suffix)) {
                $size += (int)filesize($file . $suffix);
            }
        }

        return format_size($size);
    }

    /**
     * 规范化「允许上传的扩展名」
     *
     * 只保留字母数字，统一小写去重，并剔除 PHP 相关的高危扩展名。
     */
    /**
     * 清洗「允许上传的扩展名」输入
     *
     * 只拉黑 svg：它是 XML、可以内嵌 <script>，浏览器按图片渲染时脚本会真的执行，
     * 改名保存也拦不住，所以整类不允许出现在白名单里。
     *
     * php / html / phtml 这类可执行扩展名**不再剔除** —— 站长可能确实需要交换源码，
     * 交给 Upload 在保存时把扩展名改写成 xxx1（shell.php → shell.php1），
     * 文件在服务器上永远不会被当作脚本执行。想彻底禁止，把它们从本字段里删掉即可。
     */
    private function normalizeExtensions(string $raw): string
    {
        $blocked = ['svg', 'svgz'];

        $result = [];
        foreach (preg_split('/[\s,，;；]+/u', $raw) ?: [] as $piece) {
            $ext = strtolower(ltrim(trim($piece), '.'));

            if ($ext === '' || !preg_match('/^[a-z0-9]{1,10}$/', $ext) || in_array($ext, $blocked, true)) {
                continue;
            }

            $result[$ext] = true;
        }

        $list = array_keys($result);

        return $list === [] ? 'jpg,jpeg,png,gif,webp,zip,pdf,txt' : implode(',', $list);
    }
}

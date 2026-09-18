<?php
/**
 * 安装向导
 *
 * 流程：环境自检 → 填写数据库 → （可选）测试连接 → 填写管理员 → 完成安装。
 *
 * 安全说明：
 *  - 入口仅在 storage/install.lock 不存在时可用，安装完成后自动关闭
 *  - 表单中的密码类字段不会被回填（Session::flashInput 已屏蔽）
 *  - 所有校验都在服务端重做一遍，不信任前端任何状态
 */

declare(strict_types=1);

namespace Modules\Install;

use Core\App;
use Core\Auth;
use Core\Controller;
use Core\Installer;
use Core\Request;
use Core\Response;
use Core\Router;
use Core\Session;

final class InstallController extends Controller
{
    /** 支持的数据库驱动 */
    private const DRIVERS = ['sqlite', 'mysql', 'pgsql'];

    /**
     * 安装向导首页
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        if (App::isInstalled()) {
            Response::redirect(Router::url('/'));
        }

        $driver = Request::string('driver', 'sqlite', 10);
        if (!in_array($driver, self::DRIVERS, true)) {
            $driver = 'sqlite';
        }

        return $this->view('install/index', [
            'pageTitle'    => '安装',
            'requirements' => Installer::requirements(),
            'installable'  => Installer::requirementsPassed(),
            'driver'       => $driver,
            'drivers'      => $this->driverLabels(),
            'phpVersion'   => PHP_VERSION,
            'storageWritable' => is_writable(APP_STORAGE_DIR),
            'termsText'    => $this->termsText(),
            'formErrors'   => Session::errors(),
        ], 'layouts/install');
    }

    /**
     * 处理安装提交
     *
     * @param array<string, string> $params
     */
    public function run(array $params): never
    {
        if (App::isInstalled()) {
            $this->fail('站点已完成安装。如需重装，请先删除 storage/install.lock。', 403);
        }

        $this->throttle('install', 'install:' . Request::ip());

        // 「测试数据库连接」按钮走同一个入口，用 action 区分
        if (Request::string('action', '', 20) === 'test') {
            $this->testConnection();
        }

        $result = Installer::install(Request::allPost());

        if (!$result['ok']) {
            Session::setErrors(['install' => (string)$result['message']]);
            Session::setFlash('message', (string)$result['message']);
            Session::setFlash('type', 'error');
            Session::flashInput(Request::allPost());

            if (Request::wantsJson()) {
                $this->fail((string)$result['message']);
            }

            Response::redirect(Router::url('/install'));
        }

        // 安装成功：换一个全新的会话，避免沿用安装前的会话 ID
        Session::clearFlashes();
        Session::regenerate();

        /*
         * 直接以刚创建的管理员身份登录，再跳前台首页。
         * 这样用户装完就能看到自己刚才建的账号在场，不必再手工登录一次。
         *
         * 注意：必须在 Session::regenerate() 之后再调 Auth::login()，
         * 因为 login() 内部会再次 regenerate，顺序颠倒会把这里刚换的会话 ID 覆盖掉。
         * 记住我保持关闭：管理员刚在本机装完，没必要立刻种一个 180 天的持久 Cookie。
         */
        $adminId = (int)($result['admin_id'] ?? 0);
        if ($adminId > 0) {
            Auth::login($adminId, false);
        }

        $message = (string)$result['message'];

        if (Request::wantsJson()) {
            $this->json([
                'ok'       => true,
                'message'  => $message,
                'redirect' => Router::url('/'),
            ]);
        }

        $this->redirectWith(Router::url('/'), $message);
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 测试数据库连接（返回 JSON，供向导实时反馈）
     */
    private function testConnection(): never
    {
        try {
            $config = Installer::buildConfig(Request::allPost());
            $result = Installer::testConnection($config);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage(), 'version' => ''];
        }

        $message = $result['ok']
            ? '连接成功，服务器版本：' . (string)$result['version']
            : '连接失败：' . (string)$result['message'];

        $this->json([
            'ok'      => (bool)$result['ok'],
            'message' => $message,
            'version' => (string)$result['version'],
        ], $result['ok'] ? 200 : 400);
    }

    /**
     * 读取《前置同意声明》全文
     *
     * 正文放在项目根的 TERMS.md，避免把上千字的条款抄进模板（改版时容易漏改一处）。
     * 依次回退：TERMS.md → LICENSE → 一句内置说明，保证任何情况下都有内容可展示，
     * 不会因为文件缺失让安装流程卡住。
     */
    private function termsText(): string
    {
        foreach ([APP_ROOT . '/TERMS.md', APP_ROOT . '/LICENSE'] as $file) {
            if (is_file($file)) {
                $text = (string)file_get_contents($file);
                if (trim($text) !== '') {
                    return $text;
                }
            }
        }

        return '前置同意声明' . PHP_EOL . PHP_EOL
            . '本软件按随附开源许可证（如 MIT License）授权，按「现状」提供，'
            . '不作任何明示或默示担保。部署与运营风险由运营者自行承担，'
            . '操作前请完成可恢复备份。继续安装即表示您已阅读、理解并接受本条款。';
    }

    /**
     * 数据库类型下拉选项
     *
     * @return array<string, string>
     */
    private function driverLabels(): array
    {
        $available = class_exists('PDO') ? \PDO::getAvailableDrivers() : [];

        $labels = [
            'sqlite' => 'SQLite（零配置，推荐单机使用）',
            'mysql'  => 'MySQL / MariaDB',
            'pgsql'  => 'PostgreSQL',
        ];

        $result = [];
        foreach ($labels as $driver => $label) {
            $enabled = in_array($driver, $available, true);
            $result[$driver] = $label . ($enabled ? '' : '（当前 PHP 未启用该驱动）');
        }

        return $result;
    }
}

<?php
/**
 * 视图渲染
 *
 * 使用纯 PHP 模板（无需编译步骤，也不引入模板引擎）：
 *   - 模板文件放在 templates/ 下，按目录组织
 *   - View::render('forum/index', [...]) 会自动套用 templates/layouts/main.php
 *   - 模板内通过 e() 输出动态内容，禁止直接 echo 用户数据
 *
 * 模板路径经过严格校验，杜绝通过参数控制模板路径读取任意文件。
 */

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class View
{
    /** @var array<string, mixed> 全站共享变量 */
    private static array $shared = [];

    /** 默认布局 */
    private const DEFAULT_LAYOUT = 'layouts/main';

    /**
     * 注册全站共享变量
     *
     * @param array<string, mixed> $data
     */
    public static function share(array $data): void
    {
        self::$shared = array_merge(self::$shared, $data);
    }

    /**
     * 渲染模板（自动套布局）
     *
     * @param string               $template 形如 forum/index
     * @param array<string, mixed> $data
     * @param string|null          $layout   传空串表示不套布局
     */
    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $data    = array_merge(self::defaultData(), self::$shared, $data);
        $content = self::partial($template, $data);

        $layout = $layout ?? (string)($data['layout'] ?? self::DEFAULT_LAYOUT);

        if ($layout === '') {
            return $content;
        }

        // 布局与模板共享数据，并把渲染结果作为 $content 传入
        $data['content'] = $content;

        return self::partial($layout, $data);
    }

    /**
     * 渲染局部模板（不套布局），返回 HTML 字符串
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        $file = self::resolve($template);

        // 局部模板同样能拿到共享变量
        $data = array_merge(self::$shared, $data);

        return self::evaluate($file, $data);
    }

    /** 模板是否存在 */
    public static function exists(string $template): bool
    {
        try {
            self::resolve($template);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * 解析模板路径（防目录穿越）
     *
     * 支持两种写法：
     *   forum/index            → templates/forum/index.php（核心模板）
     *   plugin/owlsgo-demo/demo → plugins/owlsgo-demo/templates/demo.php（插件自带模板）
     */
    private static function resolve(string $template): string
    {
        $template = str_replace('\\', '/', trim($template));

        // 只允许字母、数字、下划线、短横线、斜杠与点
        if ($template === '' || !preg_match('#^[A-Za-z0-9_\-/]+$#', $template) || str_contains($template, '..')) {
            throw new RuntimeException('非法的模板名：' . $template);
        }

        // 插件模板：plugin/{插件ID}/{模板名}
        if (str_starts_with($template, 'plugin/')) {
            return self::resolvePlugin($template);
        }

        $base     = realpath(APP_ROOT . '/templates') ?: APP_ROOT . '/templates';
        $absolute = realpath($base . '/' . ltrim($template, '/') . '.php');

        if ($absolute === false || !str_starts_with($absolute, $base)) {
            throw new RuntimeException('模板不存在：' . $template);
        }

        return $absolute;
    }

    /**
     * 解析插件自带模板
     *
     * 安全边界：插件 ID 与模板名都做白名单校验，且 realpath 必须落在该插件目录内，
     * 因此插件模板无法越界读取其它插件或核心目录中的文件。
     */
    private static function resolvePlugin(string $template): string
    {
        $parts  = explode('/', $template, 3);
        $plugin = $parts[1] ?? '';
        $name   = $parts[2] ?? '';

        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,31}$/', $plugin) !== 1 || $name === '') {
            throw new RuntimeException('非法的插件模板名：' . $template);
        }

        $base = realpath(APP_ROOT . '/plugins/' . $plugin);

        if ($base === false) {
            throw new RuntimeException('插件目录不存在：' . $plugin);
        }

        $absolute = realpath($base . '/templates/' . ltrim($name, '/') . '.php');

        if ($absolute === false || !str_starts_with($absolute, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('插件模板不存在：' . $template);
        }

        return $absolute;
    }

    /**
     * 在隔离作用域中执行模板
     *
     * @param array<string, mixed> $data
     */
    private static function evaluate(string $file, array $data): string
    {
        // 模板中可直接使用 $view 处理局部渲染
        $data['view'] = static fn (string $t, array $d = []): string => self::partial($t, $d);

        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $output = ob_get_clean();

        return $output === false ? '' : $output;
    }

    /**
     * 每次渲染都会注入的默认变量
     *
     * @return array<string, mixed>
     */
    private static function defaultData(): array
    {
        return [
            'currentUser'  => Auth::user(),
            'currentPath'  => Request::path(),
            'siteName'     => (string)Settings::get('site_name', 'owlsgo'),
            'siteNotice'   => (string)Settings::get('site_notice', ''),
            'flashMessage' => (string)Session::flash('message', ''),
            'flashType'    => (string)Session::flash('type', 'info'),
            'formErrors'   => Session::errors(),
            'isAjax'       => Request::isAjax(),
            'pageTitle'    => '',
            'layout'       => self::DEFAULT_LAYOUT,
        ];
    }

    /** 清空共享变量（测试用） */
    public static function reset(): void
    {
        self::$shared = [];
    }
}

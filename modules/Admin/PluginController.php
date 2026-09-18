<?php
/**
 * 后台：插件管理
 *
 * 覆盖插件清单、启用/停用/卸载、配置表单、插件后台页面，以及插件静态资源的分发。
 *
 * 资源分发的安全边界（重要）：
 *  - 只允许白名单内的静态扩展名（图片 / 样式 / 脚本 / 字体），绝不回吐 .php
 *  - 路径必须经 PluginManager::safePath 校验，硬阻断目录穿越
 *  - 一律带 nosniff，并按类型给出固定 Content-Type
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\App;
use Core\Auth;
use Core\Plugin;
use Core\PluginManager;
use Core\Request;
use Core\Response;
use Core\Router;

final class PluginController extends AdminBaseController
{
    /** 允许对外分发的静态资源扩展名 => Content-Type */
    private const ASSET_TYPES = [
        'css'   => 'text/css; charset=UTF-8',
        'js'    => 'application/javascript; charset=UTF-8',
        'mjs'   => 'application/javascript; charset=UTF-8',
        'map'   => 'application/json; charset=UTF-8',
        'json'  => 'application/json; charset=UTF-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'svg'   => 'image/svg+xml',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'txt'   => 'text/plain; charset=UTF-8',
        'md'    => 'text/plain; charset=UTF-8',
    ];

    /**
     * 插件列表
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        $plugins = PluginManager::syncDatabase();
        $loaded  = PluginManager::loadedIds();

        $rows = [];
        foreach ($plugins as $id => $meta) {
            $entry = PluginManager::safePath($id, (string)$meta['entry']);

            $rows[] = [
                'id'          => (string)$id,
                'name'        => (string)$meta['name'],
                'version'     => (string)$meta['version'],
                'description' => (string)$meta['description'],
                'author'      => (string)$meta['author'],
                'url'         => (string)$meta['url'],
                'enabled'     => (bool)($meta['enabled'] ?? false),
                'loaded'      => in_array((string)$id, $loaded, true),
                'hook_count'  => count((array)$meta['hooks']),
                'settings'    => count((array)$meta['settings']),
                'entry_ok'    => $entry !== null,
                'has_assets'  => (array)($meta['assets']['css'] ?? []) !== [] || (array)($meta['assets']['js'] ?? []) !== [],
                'path'        => (string)$meta['path'],
            ];
        }

        return $this->adminView('admin/plugins', [
            'pageTitle'  => '插件管理 - ' . (string)setting('site_name'),
            'adminTitle' => '插件管理',
            'rows'       => $rows,
            'total'      => count($rows),
            'enabledCount' => count(array_filter($rows, static fn (array $r): bool => $r['enabled'])),
            'bundleReady' => PluginManager::hasAssets(),
        ]);
    }

    /**
     * 启用插件
     *
     * @param array<string, string> $params
     */
    public function enable(array $params): never
    {
        $id  = (string)($params['id'] ?? '');
        $back = Router::url('/admin/plugins');

        try {
            PluginManager::enable($id);
        } catch (\Throwable $e) {
            $this->redirectWith($back, '启用失败：' . $e->getMessage(), 'error');
        }

        $this->audit('plugin.enable', 'plugin:' . $id, '启用插件');

        $this->redirectWith($back, '插件已启用。');
    }

    /**
     * 停用插件
     *
     * @param array<string, string> $params
     */
    public function disable(array $params): never
    {
        $id   = (string)($params['id'] ?? '');
        $back = Router::url('/admin/plugins');

        try {
            PluginManager::disable($id);
        } catch (\Throwable $e) {
            $this->redirectWith($back, '停用失败：' . $e->getMessage(), 'error');
        }

        $this->audit('plugin.disable', 'plugin:' . $id, '停用插件');

        $this->redirectWith($back, '插件已停用。');
    }

    /**
     * 卸载插件
     *
     * @param array<string, string> $params
     */
    public function uninstall(array $params): never
    {
        $id   = (string)($params['id'] ?? '');
        $back = Router::url('/admin/plugins');

        // 先停用，避免卸载后仍有钩子在运行
        try {
            PluginManager::disable($id);
            PluginManager::uninstall($id);
        } catch (\Throwable $e) {
            $this->redirectWith($back, '卸载失败：' . $e->getMessage(), 'error');
        }

        $this->audit('plugin.uninstall', 'plugin:' . $id, '卸载插件（插件目录与自建表需手动清理）');

        $this->redirectWith($back, '插件已卸载。插件目录与其自建数据表需要你手动删除。');
    }

    /**
     * 插件配置表单
     *
     * @param array<string, string> $params
     */
    public function config(array $params): string
    {
        $id   = (string)($params['id'] ?? '');
        $meta = $this->requirePlugin($id);

        $fields = $this->normalizeSettings((array)$meta['settings']);
        $values = Plugin::config($this->defaults($fields), $id);

        return $this->adminView('admin/plugin-config', [
            'pageTitle'  => '插件配置 - ' . (string)$meta['name'],
            'adminTitle' => '插件配置',
            'plugin'     => $meta,
            'fields'     => $fields,
            'values'     => $values,
            'action'     => Router::url('/admin/plugins/' . $id . '/config'),
        ]);
    }

    /**
     * 保存插件配置
     *
     * 只保存清单中声明过的键，插件无法借此写入任意数据。
     *
     * @param array<string, string> $params
     */
    public function saveConfig(array $params): never
    {
        $id   = (string)($params['id'] ?? '');
        $meta = $this->requirePlugin($id);

        $fields = $this->normalizeSettings((array)$meta['settings']);
        $back   = Router::url('/admin/plugins/' . $id . '/config');

        $values = [];
        foreach ($fields as $key => $field) {
            $type = (string)$field['type'];

            if ($type === 'checkbox') {
                $values[$key] = Request::bool($key) ? '1' : '0';
                continue;
            }

            $value = Request::string($key, (string)$field['default'], 2000);

            // 下拉框只接受声明过的选项值
            if ($type === 'select') {
                $options = array_map('strval', array_keys((array)$field['options']));
                if (!in_array($value, $options, true)) {
                    $value = (string)$field['default'];
                }
            }

            $values[$key] = $value;
        }

        Plugin::saveConfig($values, $id);

        $this->audit('plugin.config', 'plugin:' . $id, '更新插件配置');

        $message = '插件配置已保存。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /**
     * 插件注册的后台页面（/admin/plugin/{slug}）
     *
     * @param array<string, string> $params
     */
    public function page(array $params): string
    {
        $slug = (string)($params['slug'] ?? '');
        $page = Plugin::findAdminPage($slug);

        if ($page === null) {
            App::abort(404, '插件页面不存在。');
        }

        $pluginId = (string)$page['plugin'];

        if (!isset(PluginManager::enabled()[$pluginId])) {
            App::abort(404, '该插件当前未启用。');
        }

        if (!Auth::can((string)$page['permission'])) {
            App::abort(403, '你没有访问该页面的权限。');
        }

        $result = App::invoke($page['handler'], ['plugin' => $pluginId, 'slug' => $slug]);

        if (is_string($result) && $result !== '') {
            return $result;
        }

        // 处理器没有输出内容时给一个占位页，避免白屏
        return $this->adminView('admin/plugin-page', [
            'pageTitle'   => (string)$page['title'] . ' - ' . (string)setting('site_name'),
            'adminTitle'  => (string)$page['title'],
            'pluginPage'  => $page,
            'pluginId'    => $pluginId,
        ]);
    }

    /**
     * 插件静态资源（/plugin-file/{id}/{path}）
     *
     * @param array<string, string> $params
     */
    public function asset(array $params): never
    {
        $id   = (string)($params['id'] ?? '');
        $path = (string)($params['path'] ?? '');

        $absolute = PluginManager::safePath($id, $path);

        if ($absolute === null || !is_file($absolute)) {
            App::abort(404, '资源不存在。');
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        // 白名单之外一律拒绝，尤其是 .php —— 绝不能被当作脚本执行
        if (!isset(self::ASSET_TYPES[$extension])) {
            App::abort(403, '该资源类型不允许直接访问。');
        }

        if ($extension === 'svg') {
            Response::header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        $this->stream($absolute, self::ASSET_TYPES[$extension]);
    }

    /**
     * 合并后的插件资源（/plugin-assets/{type}）
     *
     * @param array<string, string> $params
     */
    public function bundle(array $params): never
    {
        $type = strtolower((string)($params['type'] ?? ''));

        if (!in_array($type, ['css', 'js'], true)) {
            App::abort(404, '资源不存在。');
        }

        $file = PluginManager::bundlePath($type);

        if ($file === null) {
            App::abort(404, '插件资源尚未生成。');
        }

        $this->stream(
            $file,
            $type === 'js' ? 'application/javascript; charset=UTF-8' : 'text/css; charset=UTF-8',
            // 合并文件名固定，因此不能让浏览器长期强缓存，否则插件更新后取不到新内容
            3600
        );
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 取插件元数据，不存在直接 404
     *
     * @return array<string, mixed>
     */
    private function requirePlugin(string $id): array
    {
        $plugins = PluginManager::syncDatabase();

        if (!isset($plugins[$id])) {
            App::abort(404, '插件不存在：' . e($id));
        }

        return $plugins[$id];
    }

    /**
     * 规范化插件配置声明
     *
     * 支持两种写法：
     *   "settings": { "key": { "label": "...", "type": "text", "default": "" } }
     *   "settings": [ { "key": "key", "label": "...", "type": "text" } ]
     *
     * @param array<mixed> $settings
     * @return array<string, array{label:string, type:string, default:string, hint:string, options:array<string,string>}>
     */
    private function normalizeSettings(array $settings): array
    {
        $allowedTypes = ['text', 'textarea', 'checkbox', 'select', 'number'];
        $result       = [];

        foreach ($settings as $key => $definition) {
            // 列表写法：从 definition.key 取配置键
            if (is_int($key) && is_array($definition)) {
                $key = (string)($definition['key'] ?? '');
                if ($key === '') {
                    continue;
                }
            }

            if (!is_string($key) || preg_match('/^[a-z0-9_]{1,40}$/', $key) !== 1) {
                continue;
            }

            $definition = is_array($definition) ? $definition : [];
            $type       = (string)($definition['type'] ?? 'text');

            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }

            $options = [];
            foreach ((array)($definition['options'] ?? []) as $optionValue => $optionLabel) {
                $options[(string)$optionValue] = (string)$optionLabel;
            }

            $result[$key] = [
                'label'   => (string)($definition['label'] ?? $key),
                'type'    => $type,
                'default' => is_scalar($definition['default'] ?? '') ? (string)($definition['default'] ?? '') : '',
                'hint'    => (string)($definition['hint'] ?? ''),
                'options' => $options,
            ];
        }

        return $result;
    }

    /**
     * 配置字段的默认值
     *
     * @param array<string, array{label:string, type:string, default:string, hint:string, options:array<string,string>}> $fields
     * @return array<string, string>
     */
    private function defaults(array $fields): array
    {
        $defaults = [];

        foreach ($fields as $key => $field) {
            $defaults[$key] = (string)$field['default'];
        }

        return $defaults;
    }

    /**
     * 输出静态资源
     */
    private function stream(string $absolute, string $mime, int $maxAge = 86400): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $size = @filesize($absolute);
        $size = $size === false ? 0 : $size;

        Response::header('Content-Type', $mime);
        Response::header('Content-Length', (string)$size);
        Response::header('X-Content-Type-Options', 'nosniff');
        Response::header('Cache-Control', 'public, max-age=' . $maxAge);

        Response::sendHeaders();

        if ($size > 0) {
            readfile($absolute);
        }

        exit;
    }
}

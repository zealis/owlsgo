<?php
/**
 * 配置容器
 *
 * 负责加载 config/ 下的 PHP 配置文件，并以「点号路径」读取：
 *   Config::get('app.debug')  =>  config/app.php 中的 debug 键
 */

declare(strict_types=1);

namespace Core;

final class Config
{
    /** @var array<string, array<string, mixed>> 已加载的配置文件 */
    private static array $items = [];

    /** @var array<string, string> 缓存键 => 值，避免重复读取 memo */
    private static array $memo = [];

    /**
     * 加载配置文件（同一文件只加载一次）
     *
     * @param string $name 不带扩展名的配置名，例如 app、database
     * @param string $path 配置文件绝对路径
     */
    public static function load(string $name, string $path): void
    {
        if (array_key_exists($name, self::$items)) {
            return;
        }

        if (!is_file($path)) {
            self::$items[$name] = [];
            return;
        }

        /** @var mixed $data */
        $data = require $path;
        self::$items[$name] = is_array($data) ? $data : [];
        self::$memo        = [];
    }

    /** 直接注入一份配置（安装向导、插件覆写时使用） */
    public static function set(string $name, array $data): void
    {
        self::$items[$name] = $data;
        self::$memo         = [];
    }

    /**
     * 以点号路径读取配置
     *
     * @param string $key     形如 app.debug 或 database
     * @param mixed  $default 缺失时返回的默认值
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $segments = explode('.', $key);
        $file     = array_shift($segments);

        $value = self::$items[$file] ?? null;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        if ($value === null) {
            return $default;
        }

        // 仅缓存标量与数组，避免缓存大对象
        if (is_scalar($value)) {
            self::$memo[$key] = $value;
        }

        return $value;
    }

    /** 判断配置项是否存在 */
    public static function has(string $key): bool
    {
        return self::get($key, '__missing__') !== '__missing__';
    }
}

<?php
/**
 * PSR-4 自动加载器（零依赖实现）
 *
 * 不使用 Composer，直接按命名空间前缀 → 目录映射加载类文件。
 * 要求：每个类文件只包含一个类，文件名与类名完全一致。
 */

declare(strict_types=1);

namespace Core;

final class Autoloader
{
    /** @var array<string, string> 命名空间前缀 => 绝对目录（末尾带分隔符） */
    private array $prefixes = [];

    /**
     * 注册一个命名空间前缀与目录的映射
     *
     * @param string $prefix  命名空间前缀，例如 "Core\\"
     * @param string $baseDir 对应的绝对目录
     */
    public function addNamespace(string $prefix, string $baseDir): self
    {
        $prefix  = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;

        $this->prefixes[$prefix] = $baseDir;

        return $this;
    }

    /** 将本加载器注册到 SPL 自动加载队列 */
    public function register(): void
    {
        spl_autoload_register([$this, 'loadClass']);
    }

    /**
     * 依据类名加载对应文件
     *
     * @param string $class 完全限定类名
     */
    public function loadClass(string $class): void
    {
        $class = ltrim($class, '\\');

        foreach ($this->prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            // 路径穿越防护：解析后必须仍位于基目录内
            $real = realpath($file);
            if ($real === false || !str_starts_with($real, realpath($baseDir) ?: $baseDir)) {
                continue;
            }

            if (is_file($real)) {
                require_once $real;
                return;
            }
        }
    }
}

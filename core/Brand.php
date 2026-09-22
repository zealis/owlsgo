<?php
/**
 * 站点品牌 Logo（顶栏 / 侧栏 / 登录注册 / 错误页 / 安装页共用的那只猫头鹰位）
 *
 * 来源优先级：
 *   1. 后台上传的自定义 SVG（storage/config/site-logo.svg，「基本信息」页可上传/恢复）；
 *   2. 内置默认：public/assets/img/site-logo.svg（设计稿「彩虹圆环」，随代码发布）；
 *   3. 两者都不可用时回退最初的线性猫头鹰（保证页面上永不出现空白）。
 * 输出为内联 <svg>（不是 <img>），配合 CSS 自适应 brand__mark 容器尺寸。
 */

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class Brand
{
    /** 每个请求内的缓存（页面 head 与 body 会各调一次） */
    private static ?string $inlineCache = null;

    private static ?int $versionCache = null;

    /** 自定义 Logo 支持的扩展名（后台上传时按上传类型择一保存） */
    public const CUSTOM_EXTS = ['svg', 'png', 'jpg', 'jpeg', 'webp'];

    /**
     * 后台自定义 Logo 的落盘路径
     *
     * 位图（png/jpg/webp）与矢量（svg）各占一个文件名：上传新格式时会删掉旧格式，
     * 因此同一时刻只会存在一个。
     */
    public static function customFile(string $ext = 'svg'): string
    {
        return APP_ROOT . '/storage/config/site-logo.' . strtolower($ext);
    }

    /** 找到当前实际存在的自定义 Logo 文件（无则 null） */
    public static function customPath(): ?string
    {
        foreach (self::CUSTOM_EXTS as $ext) {
            $file = self::customFile($ext);
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /** 是否存在自定义 Logo */
    public static function hasCustom(): bool
    {
        return self::customPath() !== null;
    }

    /** 自定义 Logo 是否位图（用于选择输出方式） */
    public static function customIsBitmap(): bool
    {
        $path = self::customPath();

        return $path !== null && strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) !== 'svg';
    }

    /** 输出到页面的 Logo 地址（动态路由，带版本号，换 Logo 后立即刷新） */
    public static function url(): string
    {
        return Router::url('/site-logo') . '?v=' . self::version();
    }

    /**
     * 缓存版本号：随「自定义 Logo / 内置 Logo」文件变化
     * （favicon 的 URL 会带 ?v= 参数，改 Logo 后浏览器立即取新的图标）
     */
    public static function version(): int
    {
        if (self::$versionCache !== null) {
            return self::$versionCache;
        }

        $path = self::customPath();
        if ($path !== null) {
            return self::$versionCache = (int)@filemtime($path);
        }

        if (is_file(self::builtinFile())) {
            return self::$versionCache = (int)@filemtime(self::builtinFile());
        }

        return self::$versionCache = 0;
    }

    /**
     * 输出内联 <svg>（已清洗）。
     * 自定义 Logo 优先；没有则返回内置猫头鹰（与历史内联完全一致）。
     */
    public static function inlineSvg(): string
    {
        if (self::$inlineCache !== null) {
            return self::$inlineCache;   // 每个请求内只读盘/清洗一次
        }

        $path = self::customPath();

        // 位图 Logo：交给 <img>（尺寸由 .brand__mark img 控制），不再内联
        if ($path !== null && strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) !== 'svg') {
            return self::$inlineCache = '<img src="' . e(self::url()) . '" alt="" class="brand__logo">';
        }

        // SVG Logo：内联输出（保留 currentColor 与零额外请求的优势）
        if ($path !== null) {
            $svg = Avatar::sanitizeSvg((string)@file_get_contents($path));
            if ($svg !== null) {
                return self::$inlineCache = self::forceScalable($svg);
            }
        }

        return self::$inlineCache = self::defaultSvg();
    }

    /**
     * 输出当前 Logo 的浏览器响应（/site-logo 路由）
     * SVG 走清洗后的文本，位图直接透传字节；都不存在时回落到内置设计稿。
     */
    public static function sendToBrowser(): never
    {
        $path = self::customPath();

        if ($path === null) {
            $svg = self::defaultSvg();
            Response::header('Content-Type', 'image/svg+xml; charset=UTF-8');
            self::noSniff();
            Response::raw($svg);
        }

        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'svg') {
            $svg = Avatar::sanitizeSvg((string)@file_get_contents($path)) ?? self::defaultSvg();
            Response::header('Content-Type', 'image/svg+xml; charset=UTF-8');
            self::noSniff();
            Response::raw($svg);
        }

        $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
        Response::header('Content-Type', $mimes[$ext] ?? 'application/octet-stream');
        self::noSniff();
        Response::raw((string)@file_get_contents($path));
    }

    private static function noSniff(): void
    {
        Response::header('X-Content-Type-Options', 'nosniff');
        Response::header('Cache-Control', 'public, max-age=604800, immutable');
        header_remove('Expires');
        header_remove('Pragma');
    }

    /** 内置默认 Logo 文件（随代码发布的「彩虹圆环」设计稿） */
    public static function builtinFile(): string
    {
        return APP_ROOT . '/public/assets/img/site-logo.svg';
    }

    /**
     * 内置默认 Logo：优先读内置设计文件，缺失/损坏时回退最初的线性猫头鹰。
     */
    public static function defaultSvg(): string
    {
        $file = self::builtinFile();
        if (is_file($file)) {
            $svg = Avatar::sanitizeSvg((string)@file_get_contents($file));
            if ($svg !== null) {
                return self::forceScalable($svg);
            }
        }

        return self::legacyOwlSvg();
    }

    /** 最初的线性猫头鹰（24 viewBox，stroke 继承 currentColor）——兜底用 */
    public static function legacyOwlSvg(): string
    {
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <ellipse cx="12" cy="13" rx="7.5" ry="8"/>
    <circle cx="9.4" cy="11.4" r="2.4" fill="currentColor" stroke="none"/>
    <circle cx="14.6" cy="11.4" r="2.4" fill="currentColor" stroke="none"/>
    <path d="M12 15.4l1.7 2.2h-3.4z" fill="currentColor" stroke="none"/>
</svg>
SVG;
    }

    /**
     * 上传校验 + 落盘（「基本信息」页调用）
     *
     * @param  array{tmp_name:string, size:int, name:string} $file $_FILES 条目
     * @throws RuntimeException 校验失败时给出用户可读原因
     */
    public static function storeCustom(array $file): void
    {
        $name = strtolower((string)($file['name'] ?? ''));
        $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $size = (int)($file['size'] ?? 0);

        if (!in_array($ext, self::CUSTOM_EXTS, true)) {
            throw new RuntimeException('只接受 SVG / PNG / JPG / WebP 格式的图片。');
        }

        // SVG 是文本矢量（很小），位图允许更大
        $limit = $ext === 'svg' ? 2 * 1024 * 1024 : 10 * 1024 * 1024;
        if ($size > $limit) {
            throw new RuntimeException('文件超过 ' . ($limit / 1024 / 1024) . 'MB 上限。');
        }

        $tmp = (string)($file['tmp_name'] ?? '');

        if ($ext === 'svg') {
            $svg = Avatar::sanitizeSvg((string)@file_get_contents($tmp));
            if ($svg === null) {
                throw new RuntimeException('文件内容不是有效的 SVG。');
            }
            $payload = self::forceScalable($svg);
        } else {
            // 位图：用 getimagesize 验证真实类型，避免改扩展名绕过
            $info = @getimagesize($tmp);
            if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
                throw new RuntimeException('文件内容不是有效的 PNG / JPG / WebP 图片。');
            }
            $payload = (string)@file_get_contents($tmp);
            if ($payload === '') {
                throw new RuntimeException('读取上传文件失败。');
            }
        }

        $dir = dirname(self::customFile('svg'));
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $target = self::customFile($ext);
        if (@file_put_contents($target, $payload, LOCK_EX) === false) {
            throw new RuntimeException('写入失败（storage/config/ 目录权限不足？）');
        }

        // 清掉其它格式的旧文件，保证同一时刻只有一个 Logo 文件
        foreach (self::CUSTOM_EXTS as $other) {
            if ($other !== $ext) {
                @unlink(self::customFile($other));
            }
        }
    }

    /** 删除自定义 Logo，恢复内置默认 */
    public static function removeCustom(): void
    {
        foreach (self::CUSTOM_EXTS as $ext) {
            @unlink(self::customFile($ext));
        }
    }

    /**
     * 确保根 <svg> 可随容器缩放：剥掉固定的 width/height 属性
     * （没有 viewBox 的极简 SVG 也补一个 64x64 的缺省，避免自定义 logo 撑破容器）。
     */
    private static function forceScalable(string $svg): string
    {
        if (stripos($svg, 'viewBox') === false) {
            $svg = preg_replace('/<svg/i', '<svg viewBox="0 0 64 64"', $svg) ?? $svg;
        }
        $svg = preg_replace('/\s(width|height)="[^"]*"/i', '', $svg) ?? $svg;
        // 首个 <svg> 去掉属性后可能残留多余空白，顺手收紧
        return preg_replace('/<svg\s+/', '<svg ', $svg) ?? $svg;
    }
}

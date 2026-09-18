<?php
/**
 * 头像生成器
 *
 * 零依赖方案：不调用任何外部头像服务（避免泄露用户信息给第三方、也避免被墙），
 * 而是按用户名哈希确定性地生成 SVG 头像。
 *
 * 安全：SVG 中的文字内容全部经过白名单过滤与 XML 转义，
 * 并通过「Content-Security-Policy: default-src 'none'」+ nosniff 提供，
 * 从机制上消除 SVG 作为 XSS 载体的可能。
 */

declare(strict_types=1);

namespace Core;

final class Avatar
{
    /** 可选风格 */
    public const STYLES = ['owl', 'geometric', 'initial', 'robot', 'pixel'];

    /**
     * 预置头像库：seed + 风格的固定组合。
     *
     * seed 用固定前缀（而非用户名哈希），保证「预置头像」列表对所有人一致；
     * 用户选中后把 `preset:{seed}|{style}` 写入 users.avatar（见 Avatar::url）。
     *
     * @return array<int, array{seed:string, style:string}>
     */
    public static function presets(): array
    {
        $styles = self::STYLES;
        $presets = [];

        for ($i = 0; $i < 10; $i++) {
            $presets[] = [
                'seed'  => 'preset-' . $i . '-' . substr(hash('sha256', 'owlsgo-preset' . $i), 0, 10),
                'style' => $styles[$i % count($styles)],
            ];
        }

        return $presets;
    }

    /** 配色板（QQ 经典蓝白 + 少量点缀色） */
    private const PALETTE = [
        ['#00A0E9', '#E6F7FF'],
        ['#0078D4', '#E6F7FF'],
        ['#0F6FD1', '#EAF3FF'],
        ['#2BA8E0', '#EAF8FF'],
        ['#5B8DEF', '#EEF3FF'],
        ['#00B3A6', '#E6FAF8'],
        ['#FF7D00', '#FFF3E6'],
        ['#7C6BF2', '#F1EFFF'],
        ['#F5222D', '#FFECEC'],
        ['#52C41A', '#EFFBE8'],
    ];

    /**
     * 生成头像 URL
     *
     * @param array<string, mixed>|null $user 用户记录
     * @param int                       $size 期望尺寸（仅用于前端渲染提示）
     */
    public static function url(?array $user, int $size = 48): string
    {
        $size = max(16, min(256, $size));

        $uploaded = trim((string)($user['avatar'] ?? ''));
        if ($uploaded !== '') {
            /*
             * 「预置头像」：preset:{seed}|{style}。
             * 用户从预置头像库里选的内置 SVG —— 不占磁盘文件，
             * 渲染时按种子 + 风格实时生成，随时可换回其它风格或上传图。
             */
            if (str_starts_with($uploaded, 'preset:')) {
                $spec  = substr($uploaded, 7);
                [$seed, $style] = array_pad(explode('|', $spec, 2), 2, '');
                $query = ['s' => $size];
                if ($style !== '') {
                    $query['style'] = $style;
                }

                return Router::url('/avatar/' . rawurlencode($seed) . '.svg', $query);
            }

            return Router::url('/media/' . ltrim($uploaded, '/'));
        }

        return Router::url('/avatar/' . self::seed($user) . '.svg', ['s' => $size]);
    }

    /**
     * 种子字符串：同一用户始终得到同一张头像
     */
    public static function seed(?array $user): string
    {
        if ($user === null) {
            return 'guest';
        }

        $id   = (int)($user['id'] ?? 0);
        $name = (string)($user['username'] ?? '');

        return substr(hash('sha256', $id . '|' . $name), 0, 16);
    }

    /**
     * 生成头像 SVG 源码
     *
     * @param string $seed  种子
     * @param int    $size  输出尺寸（像素）
     * @param string $style 风格，空则按种子自动挑选
     * @param string $label 可选文字（initial 风格使用）
     */
    public static function svg(string $seed, int $size = 96, string $style = '', string $label = ''): string
    {
        $size  = max(16, min(512, $size));
        $style = in_array($style, self::STYLES, true) ? $style : self::pickStyle($seed);

        $palette = self::pickPalette($seed);
        $primary = $palette[0];
        $soft    = $palette[1];

        $body = match ($style) {
            'geometric' => self::geometric($seed, $primary, $soft),
            'initial'   => self::initial($seed, $primary, $soft, $label),
            'robot'     => self::robot($seed, $primary, $soft),
            'pixel'     => self::pixel($seed, $primary, $soft),
            default     => self::owl($seed, $primary, $soft),
        };

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="' . $size . '" height="' . $size . '"'
            . ' role="img" aria-label="用户头像">'
            . '<rect width="100" height="100" rx="50" fill="' . $soft . '"/>'
            . $body
            . '</svg>';
    }

    /** 依据种子选择风格 */
    private static function pickStyle(string $seed): string
    {
        $index = (int)(hexdec(substr(md5($seed . 'style'), 0, 4)) % count(self::STYLES));

        return self::STYLES[$index];
    }

    /**
     * 依据种子选择配色
     *
     * @return array{0:string, 1:string}
     */
    private static function pickPalette(string $seed): array
    {
        $index = (int)(hexdec(substr(md5($seed . 'color'), 0, 4)) % count(self::PALETTE));

        return self::PALETTE[$index];
    }

    /** 由种子产生 0..n-1 的确定性整数 */
    private static function pick(string $seed, string $salt, int $mod): int
    {
        return $mod <= 1 ? 0 : (int)(hexdec(substr(md5($seed . $salt), 0, 4)) % $mod);
    }

    /** 风格一：猫头鹰（站点吉祥物） */
    private static function owl(string $seed, string $primary, string $soft): string
    {
        $eyeOffset = 20 + self::pick($seed, 'eye', 3);
        $beakY     = 62 + self::pick($seed, 'beak', 4);
        $hasTuft   = self::pick($seed, 'tuft', 2) === 1;

        $tuft = $hasTuft
            ? '<path d="M28 24 L34 12 L42 22 Z" fill="' . $primary . '"/>'
              . '<path d="M72 24 L66 12 L58 22 Z" fill="' . $primary . '"/>'
            : '';

        return $tuft
            . '<ellipse cx="50" cy="55" rx="30" ry="32" fill="' . $primary . '"/>'
            . '<circle cx="' . (50 - $eyeOffset) . '" cy="48" r="11" fill="#FFFFFF"/>'
            . '<circle cx="' . (50 + $eyeOffset) . '" cy="48" r="11" fill="#FFFFFF"/>'
            . '<circle cx="' . (50 - $eyeOffset + 1) . '" cy="49" r="5" fill="#1A1A1A"/>'
            . '<circle cx="' . (50 + $eyeOffset + 1) . '" cy="49" r="5" fill="#1A1A1A"/>'
            . '<path d="M50 ' . $beakY . ' l7 9 h-14 z" fill="#FFB020"/>'
            . '<path d="M34 82 q16 12 32 0" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round"/>';
    }

    /** 风格二：几何图形 */
    private static function geometric(string $seed, string $primary, string $soft): string
    {
        $shapes = ['circle', 'triangle', 'square', 'diamond'];
        $outer  = $shapes[self::pick($seed, 'outer', 4)];
        $inner  = $shapes[self::pick($seed, 'inner', 4)];
        $rotate = self::pick($seed, 'rot', 90);

        return '<g transform="rotate(' . $rotate . ' 50 50)">'
            . self::shape($outer, 50, 50, 38, $primary)
            . self::shape($inner, 50, 50, 20, '#FFFFFF', 0.85)
            . '</g>';
    }

    /** 风格三：首字母 */
    private static function initial(string $seed, string $primary, string $soft, string $label): string
    {
        // 只保留字母与中日文字符，避免把任意字符注入 SVG
        $label = trim($label);
        if ($label !== '') {
            if (preg_match('/^[A-Za-z0-9]/', $label)) {
                $char = strtoupper(substr($label, 0, 1));
            } elseif (preg_match('/^[\x{4e00}-\x{9fa5}]/u', $label, $m)) {
                $char = $m[0];
            } else {
                $char = '?';
            }
        } else {
            $char = strtoupper(substr($seed, 0, 1));
        }

        if (!preg_match('/^[A-Za-z0-9\x{4e00}-\x{9fa5}]$/u', $char)) {
            $char = '?';
        }

        $char = htmlspecialchars($char, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return '<circle cx="50" cy="50" r="42" fill="' . $primary . '"/>'
            . '<text x="50" y="50" text-anchor="middle" dominant-baseline="central"'
            . ' font-family="-apple-system, Segoe UI, Microsoft YaHei, sans-serif"'
            . ' font-size="44" font-weight="600" fill="#FFFFFF">' . $char . '</text>';
    }

    /** 风格四：机器人 */
    private static function robot(string $seed, string $primary, string $soft): string
    {
        $mouth = self::pick($seed, 'mouth', 3);
        $mouthSvg = match ($mouth) {
            0 => '<rect x="40" y="66" width="20" height="4" rx="2" fill="#FFFFFF"/>',
            1 => '<path d="M38 66 q12 12 24 0" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round"/>',
            default => '<circle cx="50" cy="68" r="5" fill="#FFFFFF"/>',
        };

        return '<line x1="50" y1="14" x2="50" y2="26" stroke="' . $primary . '" stroke-width="4"/>'
            . '<circle cx="50" cy="12" r="5" fill="#FF7D00"/>'
            . '<rect x="24" y="26" width="52" height="54" rx="14" fill="' . $primary . '"/>'
            . '<circle cx="38" cy="50" r="8" fill="#FFFFFF"/>'
            . '<circle cx="62" cy="50" r="8" fill="#FFFFFF"/>'
            . '<circle cx="39" cy="51" r="3.5" fill="#1A1A1A"/>'
            . '<circle cx="63" cy="51" r="3.5" fill="#1A1A1A"/>'
            . $mouthSvg;
    }

    /** 风格五：像素块 */
    private static function pixel(string $seed, string $primary, string $soft): string
    {
        $hash = md5($seed . 'pixel');
        $cells = '';

        for ($row = 0; $row < 6; $row++) {
            for ($col = 0; $col < 3; $col++) {
                $value = hexdec($hash[($row * 3 + $col) % 32]);
                if ($value % 3 === 0) {
                    continue;
                }

                $x    = 13 + $col * 12;
                $y    = 13 + $row * 12;
                $fill = $value % 4 === 0 ? '#FFFFFF' : $primary;

                $cells .= '<rect x="' . $x . '" y="' . $y . '" width="12" height="12" fill="' . $fill . '"/>'
                    . '<rect x="' . (87 - $col * 12 - 12) . '" y="' . $y . '" width="12" height="12" fill="' . $fill . '"/>';
            }
        }

        return '<circle cx="50" cy="50" r="42" fill="' . $soft . '"/>'
            . '<clipPath id="pixel-clip"><circle cx="50" cy="50" r="42"/></clipPath>'
            . '<g clip-path="url(#pixel-clip)">' . $cells . '</g>';
    }

    /** 生成基础几何形状 */
    private static function shape(string $type, int $cx, int $cy, int $size, string $fill, float $opacity = 1.0): string
    {
        $attrs = ' fill="' . $fill . '"' . ($opacity < 1 ? ' fill-opacity="' . $opacity . '"' : '');

        return match ($type) {
            'circle'   => '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $size . '"' . $attrs . '/>',
            'triangle' => '<path d="M' . $cx . ' ' . ($cy - $size) . ' L' . ($cx + $size) . ' ' . ($cy + $size)
                . ' L' . ($cx - $size) . ' ' . ($cy + $size) . ' Z"' . $attrs . '/>',
            'square'   => '<rect x="' . ($cx - $size) . '" y="' . ($cy - $size) . '" width="' . ($size * 2)
                . '" height="' . ($size * 2) . '" rx="6"' . $attrs . '/>',
            default    => '<path d="M' . $cx . ' ' . ($cy - $size) . ' L' . ($cx + $size) . ' ' . $cy
                . ' L' . $cx . ' ' . ($cy + $size) . ' L' . ($cx - $size) . ' ' . $cy . ' Z"' . $attrs . '/>',
        };
    }
}

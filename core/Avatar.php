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
    /** 可选风格（本地生成器的风格；DiceBear 预置头像不使用这些） */
    public const STYLES = ['owl', 'geometric', 'initial', 'robot', 'pixel'];

    /**
     * 预置头像（DiceBear）
     *
     * 预置库不再是本地那几套几何图形，而是调用 DiceBear 的 big-ears-neutral 风格：
     * 每次打开弹层给一批新的随机 seed（所以叫「随机生成 10 个」），
     * 用户选中哪个就把哪个抓下来**落盘**存成自己的头像（见 UserController::applyDicebearAvatar）。
     *
     * 预览走本站代理 `/avatar/dice/{seed}.svg`，不经用户浏览器直连第三方：
     * 一来国内访问 DiceBear 不一定通，二来不把用户的 IP 暴露给外部服务。
     */
    public const DICEBEAR_STYLE = 'big-ears-neutral';

    /** DiceBear 背景色（接口要求的逗号分隔十六进制，不带 #） */
    public const DICEBEAR_BACKGROUNDS = 'fdba74,fcd34d,fca5a5,fb923c,f9a8d4';

    /** DiceBear 抓取超时（秒）：连接 / 总耗时 */
    private const DICEBEAR_TIMEOUT_CONNECT = 4;
    private const DICEBEAR_TIMEOUT_TOTAL   = 10;

    /** 单张 SVG 的大小上限（字节）：DiceBear 一张约 4~8 KB，给足余量 */
    private const DICEBEAR_MAX_BYTES = 131072;

    /**
     * 预置头像候选：10 个随机 seed
     *
     * 每次调用都换一批（弹层打开即随机），所以返回值里直接带上渲染用的 URL。
     *
     * @return list<array{seed:string, url:string}>
     */
    public static function presets(int $count = 10): array
    {
        $count   = max(1, min(30, $count));
        $presets = [];

        for ($i = 0; $i < $count; $i++) {
            $seed       = self::randomSeed();
            $presets[] = [
                'seed' => $seed,
                'url'  => Router::url('/avatar/dice/' . rawurlencode($seed) . '.svg'),
            ];
        }

        return $presets;
    }

    /** 随机 seed（同一 seed 永远得到同一张脸，所以"随机"只是换一批种子） */
    public static function randomSeed(): string
    {
        return 'db' . bin2hex(random_bytes(8));
    }

    /** DiceBear 头像 URL（服务端抓取用，不直接给浏览器） */
    public static function dicebearUrl(string $seed): string
    {
        return 'https://api.dicebear.com/10.x/' . self::DICEBEAR_STYLE . '/svg'
            . '?seed=' . rawurlencode($seed)
            . '&backgroundColor=' . self::DICEBEAR_BACKGROUNDS;
    }

    /**
     * 取一张 DiceBear 头像的 SVG（带文件缓存）
     *
     * 缓存目录 storage/cache/dicebear/{seed}.svg：同一个 seed 只要抓一次，
     * 之后预览与应用都直接读本地文件 —— 断网也能用已经缓存过的那批。
     *
     * 失败（网络不通 / 超时 / 返回不是 SVG / 清洗后为空）返回 null，
     * 由调用方回退到本地生成的头像，保证页面上不会出现裂图。
     */
    public static function dicebearSvg(string $seed): ?string
    {
        if (preg_match('/^[a-z0-9_-]{1,64}$/', $seed) !== 1) {
            return null;
        }

        $file = self::dicebearCacheFile($seed);

        if ($file !== null && is_file($file)) {
            $cached = (string)@file_get_contents($file);
            if ($cached !== '') {
                return $cached;
            }
        }

        $svg = self::fetchDicebear($seed);

        if ($svg === null) {
            return null;
        }

        if ($file !== null) {
            @file_put_contents($file, $svg, LOCK_EX);
        }

        return $svg;
    }

    /**
     * 向 DiceBear 发一次请求，返回清洗后的 SVG；失败返回 null
     *
     * SSL 校验的默认姿势是「严格」——但不少 Windows 环境（本机 phpStudy 就是）
     * 既没有配置 curl.cainfo，出网又经过自签名证书的企业代理，
     * 严格校验会恒定失败（errno 60）。所以：
     *   1. 先按「严格 + 已知 CA 文件」请求；
     *   2. 只有确认失败在证书链上（errno 60/77）才放宽校验重试一次，并记 warning。
     * 内容本身还有 sanitizeSvg + CSP sandbox 兜底，放宽的只是传输层。
     */
    private static function fetchDicebear(string $seed): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $svg = self::curlDicebear($seed, true);

        if ($svg === null && in_array(self::$lastCurlErrno, [60, 77], true)) {
            Logger::warning(
                'DiceBear 头像抓取：证书校验失败（errno ' . self::$lastCurlErrno . '），已放宽校验重试',
                ['seed' => $seed]
            );

            $svg = self::curlDicebear($seed, false);
        }

        return $svg;
    }

    /** 上一次 cURL 的错误号（用于判断是否值得放宽校验重试） */
    private static int $lastCurlErrno = 0;

    private static function curlDicebear(string $seed, bool $verify): ?string
    {
        $ch = curl_init(self::dicebearUrl($seed));

        if ($ch === false) {
            return null;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::DICEBEAR_TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT        => self::DICEBEAR_TIMEOUT_TOTAL,
            CURLOPT_USERAGENT      => 'owlsgo/' . (string)config('app.version', '1.0'),
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ];

        $ca = self::caFile();
        if ($ca !== null) {
            $options[CURLOPT_CAINFO] = $ca;
        }

        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        self::$lastCurlErrno = (int)curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $status !== 200 || strlen((string)$raw) > self::DICEBEAR_MAX_BYTES) {
            return null;
        }

        // 只接受 SVG：接口偶尔会返回 JSON 错误体，别把它当图片存下来
        if (!str_contains($type, 'svg') && !str_contains($type, 'xml')) {
            return null;
        }

        return self::sanitizeSvg((string)$raw);
    }

    /**
     * 找一个可用的 CA 证书文件
     *
     * PHP 的 curl.cainfo / openssl.cafile 为空时，Windows 上常导致 HTTPS 全部失败；
     * 这里按常见位置兜底找一次，找不到就返回 null（由调用方决定是否放宽校验）。
     */
    private static function caFile(): ?string
    {
        foreach (['curl.cainfo', 'openssl.cafile'] as $ini) {
            $value = (string)ini_get($ini);

            if ($value !== '' && is_file($value)) {
                return $value;
            }
        }

        $candidates = [
            APP_ROOT . '/storage/config/cacert.pem',
            dirname((string)PHP_BINARY) . '/extras/ssl/cacert.pem',
            dirname((string)PHP_BINARY) . '/cacert.pem',
            (string)getenv('SYSTEMROOT') . '/System32/curl-ca-bundle.crt',
        ];

        foreach ($candidates as $file) {
            if ($file !== '' && is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * SVG 清洗：只留静态矢量图形，去掉一切可执行/可外联的东西
     *
     * SVG 是 XML，可以内嵌 <script>、事件属性与外链引用 —— 哪怕最终只在 <img> 里显示，
     * 也要防住「用户直接打开这个 URL」的场景（我们另外再用 CSP sandbox 兜底）。
     */
    public static function sanitizeSvg(string $svg): ?string
    {
        $svg = trim($svg);

        if ($svg === '' || stripos($svg, '<svg') === false) {
            return null;
        }

        // 去掉 XML 声明与注释（注释里可能藏着被截断的标签）
        $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!--.*?-->/is', '', $svg) ?? $svg;

        // 危险节点整个删掉（注意：<use> 不在名单里 —— DiceBear 的人脸部件全靠它组装）
        $svg = preg_replace('#<\s*(script|foreignObject|iframe|audio|video|handler)\b.*?</\s*\1\s*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<\s*(script|foreignObject|iframe|audio|video|handler)\b[^>]*/?\s*>#is', '', $svg) ?? $svg;

        /*
         * <use> / <image> / 动画元素：只删「指向外部资源」的 ——
         * 引用同文档 #id 的 <use> 是 DiceBear 的正常结构，删了头像就只剩背景色。
         * 外链 <use href="https://…"> 才是 XSS 载体（把别处的 SVG 载荷拖进来）。
         */
        $svg = preg_replace(
            '#<\s*(use|image|animate|set)\b[^>]*\b(?:xlink:)?href\s*=\s*(["\'])\s*((?:https?:)?//|data:)[^"\']*\2[^>]*/?\s*>(?:.*?</\s*\1\s*>)?#is',
            '',
            $svg
        ) ?? $svg;

        // 事件属性（onload / onclick / …）
        $svg = preg_replace('#\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)#is', '', $svg) ?? $svg;

        // 外链：href / xlink:href 指向 http(s) 的一律剔除属性
        $svg = preg_replace('#\s(?:xlink:)?href\s*=\s*(["\'])\s*https?://[^"\']*\1#i', '', $svg) ?? $svg;

        if (strlen($svg) > self::DICEBEAR_MAX_BYTES || stripos($svg, '<svg') === false) {
            return null;
        }

        return $svg;
    }

    /** 缓存文件路径（目录不可写时返回 null，退化为不缓存） */
    private static function dicebearCacheFile(string $seed): ?string
    {
        $dir = APP_ROOT . '/storage/cache/dicebear';

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        return $dir . '/' . $seed . '.svg';
    }

    /** 配色板（经典蓝白 + 少量点缀色） */
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

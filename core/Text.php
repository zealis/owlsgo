<?php
/**
 * 内容安全解析
 *
 * 硬性约束：不引入 HTMLPurifier，也**绝不**解析用户提交的原始 HTML。
 * 做法是「先整体转义，再用白名单正则重建结构」，因此用户即便提交
 * <script>alert(1)</script> 也只会被当成普通文字显示（转义后为 &lt;script&gt;）。
 *
 * 支持的两套标记：
 *  - Markdown 子集：标题 / 粗体 / 斜体 / 删除线 / 行内代码 / 代码块 / 引用 / 列表 / 分割线 / 链接 / 图片
 *  - UBB 子集：[b] [i] [u] [s] [code] [quote] [url] [img] [color] [size] [attach]
 */

declare(strict_types=1);

namespace Core;

final class Text
{
    /** 允许出现的 UBB 标签名（白名单） */
    private const UBB_TAGS = ['b', 'i', 'u', 's', 'code', 'quote', 'url', 'img', 'color', 'size', 'attach'];

    /** @var list<string> 代码块占位符内容 */
    private static array $codeBlocks = [];

    /**
     * 把用户正文渲染为安全的 HTML
     */
    public static function toHtml(string $raw): string
    {
        $raw = self::normalize($raw);

        if ($raw === '') {
            return '';
        }

        self::$codeBlocks = [];

        // 1) 先摘出代码块，避免其中的符号被其它规则污染
        $text = self::extractCodeBlocks($raw);

        // 2) 整体转义，这一步之后所有 "<" 都变成 "&lt;"，XSS 面被彻底关闭
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3) 分块解析
        $text = self::parseBlocks($text);

        // 4) 行内解析
        $text = self::parseInline($text);

        // 5) UBB 标签
        $text = self::parseUbb($text);

        // 6) 段落内的换行
        $text = self::applyLineBreaks($text);

        // 7) 还原代码块
        $text = self::restoreCodeBlocks($text);

        return trim($text);
    }

    /**
     * 提取正文纯文本摘要（用于列表页、SEO 描述）
     */
    public static function excerpt(string $raw, int $max = 120): string
    {
        $text = self::normalize($raw);

        // 去掉代码块与常见标记
        $text = preg_replace('/```[\s\S]*?```/', ' ', $text) ?? $text;
        $text = preg_replace('/\[code\][\s\S]*?\[\/code\]/i', ' ', $text) ?? $text;
        $text = preg_replace('/\[quote\][\s\S]*?\[\/quote\]/i', ' ', $text) ?? $text;
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text) ?? $text;
        $text = preg_replace('/\[img\][\s\S]*?\[\/img\]/i', ' ', $text) ?? $text;
        $text = preg_replace('/\[attach[^\]]*\][\s\S]*?\[\/attach\]/i', ' ', $text) ?? $text;
        $text = preg_replace('/\[[^\]]*\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = preg_replace('/\[[a-z]+(=[^\]]*)?\]/i', ' ', $text) ?? $text;
        $text = preg_replace('/\[\/[a-z]+\]/i', ' ', $text) ?? $text;
        $text = preg_replace('/^[#>\-\*\s]+/m', '', $text) ?? $text;
        $text = preg_replace('/[`*_~]/', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        $text = trim($text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    /**
     * 从正文中提取 @提及 的用户名
     *
     * @return list<string>
     */
    public static function mentions(string $raw): array
    {
        if (!preg_match_all('/(?<![\w@])@([A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,20})/u', $raw, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * 清洗搜索关键词（搜索控制器与顶栏搜索框共用，保证两处回显一致）
     *
     * 规则：
     *   1. 掐掉控制字符与零宽字符 —— 它们肉眼不可见，却会让搜索词失去意义、
     *      污染统计，甚至被用来绕过频率限制；
     *   2. trim 首尾空白。
     *
     * 长度限制由调用方（Request::string 的 $max 参数）负责，这里不重复。
     * 无效 UTF-8 输入时 preg_replace 会返回 null，此时按空串处理（安全降级）。
     */
    public static function cleanSearchKeyword(string $raw): string
    {
        $clean = preg_replace(
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200D}\x{FEFF}]/u',
            '',
            $raw
        );

        return trim((string)($clean ?? ''));
    }

    /** 统一换行符与不可见字符 */
    private static function normalize(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? $raw;

        return trim($raw);
    }

    /**
     * 摘出 ``` 与 [code] 代码块，替换为占位符
     */
    private static function extractCodeBlocks(string $text): string
    {
        // Markdown 围栏代码块
        $text = preg_replace_callback(
            '/```([a-zA-Z0-9_+\-]*)\n([\s\S]*?)```/',
            static function (array $m): string {
                $lang = strtolower($m[1]);
                self::$codeBlocks[] = ['code' => $m[2], 'lang' => $lang];

                return "\n\x01CODE" . (count(self::$codeBlocks) - 1) . "\x01\n";
            },
            $text
        ) ?? $text;

        // UBB 代码块
        $text = preg_replace_callback(
            '/\[code\]([\s\S]*?)\[\/code\]/i',
            static function (array $m): string {
                self::$codeBlocks[] = ['code' => $m[1], 'lang' => ''];

                return "\n\x01CODE" . (count(self::$codeBlocks) - 1) . "\x01\n";
            },
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * 还原代码块（内容仍会被转义，但不再做其它解析）
     */
    private static function restoreCodeBlocks(string $text): string
    {
        return preg_replace_callback(
            '/\x01CODE(\d+)\x01/',
            static function (array $m): string {
                $index = (int)$m[1];
                $block = self::$codeBlocks[$index] ?? ['code' => '', 'lang' => ''];

                $code = htmlspecialchars($block['code'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $lang = $block['lang'] !== ''
                    ? ' class="language-' . preg_replace('/[^a-z0-9_+\-]/', '', $block['lang']) . '"'
                    : '';

                return '<pre class="code-block"><code' . $lang . '>' . rtrim($code, "\n") . '</code></pre>';
            },
            $text
        ) ?? $text;
    }

    /**
     * 块级元素解析：标题、引用、列表、分割线、表格
     */
    private static function parseBlocks(string $text): string
    {
        $lines  = explode("\n", $text);
        $output = [];
        $inList = null;   // 'ul' | 'ol' | null
        $inQuote = false;

        $closeList = static function () use (&$inList, &$output): void {
            if ($inList !== null) {
                $output[] = '</' . $inList . '>';
                $inList   = null;
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $plain   = trim(preg_replace('/^&gt;\s?/', '', $trimmed) ?? $trimmed);

            // 分割线
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $closeList();
                $output[] = '<hr>';
                continue;
            }

            // 标题
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
                $closeList();
                $level    = min(6, strlen($m[1]));
                $output[] = '<h' . $level . ' class="content-heading">' . $m[2] . '</h' . $level . '>';
                continue;
            }

            // 引用（已转义，因此 > 变为 &gt;）
            if (str_starts_with($trimmed, '&gt;')) {
                $closeList();
                if (!$inQuote) {
                    $output[] = '<blockquote>';
                    $inQuote  = true;
                }
                $output[] = '<p>' . $plain . '</p>';
                continue;
            }

            if ($inQuote) {
                $output[] = '</blockquote>';
                $inQuote  = false;
            }

            // 无序列表
            if (preg_match('/^[\-\*\+]\s+(.*)$/', $trimmed, $m)) {
                if ($inList !== 'ul') {
                    $closeList();
                    $output[] = '<ul>';
                    $inList   = 'ul';
                }
                $output[] = '<li>' . $m[1] . '</li>';
                continue;
            }

            // 有序列表
            if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $m)) {
                if ($inList !== 'ol') {
                    $closeList();
                    $output[] = '<ol>';
                    $inList   = 'ol';
                }
                $output[] = '<li>' . $m[1] . '</li>';
                continue;
            }

            $closeList();

            if ($trimmed === '') {
                $output[] = '';
                continue;
            }

            $output[] = '<p class="content-paragraph">' . $trimmed . '</p>';
        }

        if ($inQuote) {
            $output[] = '</blockquote>';
        }
        $closeList();

        return implode("\n", $output);
    }

    /**
     * 行内元素解析：粗体、斜体、删除线、行内代码、链接、图片、提及
     */
    private static function parseInline(string $text): string
    {
        // 行内代码先处理，避免其中的 * 被当作强调（内容已转义，需还原为转义前形态再转义一次）
        $text = preg_replace_callback(
            '/`([^`\n]+)`/',
            static fn (array $m): string => '<code class="inline-code">'
                . htmlspecialchars(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '</code>',
            $text
        ) ?? $text;

        // 图片 ![alt](url)
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)\)/',
            static function (array $m): string {
                $url = self::safeUrl($m[2], true);
                if ($url === '') {
                    return $m[1];
                }

                return '<img class="content-image" src="' . $url . '" alt="' . $m[1] . '" loading="lazy">';
            },
            $text
        ) ?? $text;

        // 链接 [text](url)
        $text = preg_replace_callback(
            '/\[([^\]\n]+)\]\(([^)\s]+)\)/',
            static function (array $m): string {
                $url = self::safeUrl($m[2]);
                if ($url === '') {
                    return $m[1];
                }

                return '<a href="' . $url . '" rel="noopener nofollow ugc" target="_blank">' . $m[1] . '</a>';
            },
            $text
        ) ?? $text;

        // 裸链接自动识别
        $text = preg_replace_callback(
            '/(?<!["\'=])\bhttps?:\/\/[^\s<>"\')]+/',
            static function (array $m): string {
                $url = self::safeUrl($m[0], false, true);
                if ($url === '') {
                    return $m[0];
                }

                return '<a href="' . $url . '" rel="noopener nofollow ugc" target="_blank">' . $m[0] . '</a>';
            },
            $text
        ) ?? $text;

        // 粗体 **x** / __x__
        $text = preg_replace('/(\*\*|__)(?=\S)([\s\S]*?\S)\1/', '<strong>$2</strong>', $text) ?? $text;

        // 删除线 ~~x~~
        $text = preg_replace('/~~(?=\S)([\s\S]*?\S)~~/', '<del>$1</del>', $text) ?? $text;

        // 斜体 *x* / _x_
        $text = preg_replace('/(?<![\*\w])\*(?=\S)([^\*\n]*?\S)\*(?![\*\w])/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![\w_])_(?=\S)([^_\n]*?\S)_(?![\w_])/', '<em>$1</em>', $text) ?? $text;

        // @提及（仅做视觉标记，通知在业务层处理）
        $text = preg_replace(
            '/(?<![\w@&])@([A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,20})/u',
            '<span class="mention">@$1</span>',
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * UBB 标签解析
     */
    private static function parseUbb(string $text): string
    {
        $simple = [
            'b' => 'strong',
            'i' => 'em',
            'u' => 'u',
            's' => 'del',
        ];

        foreach ($simple as $ubb => $html) {
            $text = preg_replace(
                '/\[' . $ubb . '\]([\s\S]*?)\[\/' . $ubb . '\]/i',
                '<' . $html . '>$1</' . $html . '>',
                $text
            ) ?? $text;
        }

        // 引用
        $text = preg_replace('/\[quote(?:=([^\]]*))?\]([\s\S]*?)\[\/quote\]/i', '<blockquote class="ubb-quote">$2</blockquote>', $text) ?? $text;

        // 超链接 [url=地址]文字[/url] 与 [url]地址[/url]
        $text = preg_replace_callback(
            '/\[url=([^\]]+)\]([\s\S]*?)\[\/url\]/i',
            static function (array $m): string {
                $url = self::safeUrl($m[1]);
                if ($url === '') {
                    return $m[2];
                }

                return '<a href="' . $url . '" rel="noopener nofollow ugc" target="_blank">' . $m[2] . '</a>';
            },
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\[url\]([\s\S]*?)\[\/url\]/i',
            static function (array $m): string {
                $url = self::safeUrl(trim($m[1]));
                if ($url === '') {
                    return $m[1];
                }

                return '<a href="' . $url . '" rel="noopener nofollow ugc" target="_blank">' . $m[1] . '</a>';
            },
            $text
        ) ?? $text;

        // 图片
        $text = preg_replace_callback(
            '/\[img\]([\s\S]*?)\[\/img\]/i',
            static function (array $m): string {
                $url = self::safeUrl(trim($m[1]), true);
                if ($url === '') {
                    return '';
                }

                return '<img class="content-image" src="' . $url . '" alt="图片" loading="lazy">';
            },
            $text
        ) ?? $text;

        // 颜色（白名单色值）
        $text = preg_replace_callback(
            '/\[color=([^\]]+)\]([\s\S]*?)\[\/color\]/i',
            static function (array $m): string {
                $color = trim($m[1]);
                if (!preg_match('/^(#[0-9a-fA-F]{3,6}|[a-zA-Z]{3,20})$/', $color)) {
                    return $m[2];
                }

                return '<span style="color:' . $color . '">' . $m[2] . '</span>';
            },
            $text
        ) ?? $text;

        // 字号（限制在 1-7 的 UBB 语义）
        $text = preg_replace_callback(
            '/\[size=([^\]]+)\]([\s\S]*?)\[\/size\]/i',
            static function (array $m): string {
                $size = (int)trim($m[1]);
                if ($size < 1 || $size > 7) {
                    return $m[2];
                }

                $px = 10 + $size * 2;

                return '<span style="font-size:' . $px . 'px">' . $m[2] . '</span>';
            },
            $text
        ) ?? $text;

        // 附件引用 [attach]12[/attach]
        $text = preg_replace_callback(
            '/\[attach\](\d+)\[\/attach\]/i',
            static function (array $m): string {
                $id = (int)$m[1];

                return '<a class="attachment-link" href="' . Router::url('/attachment/' . $id) . '" rel="noopener">📎 附件 #' . $id . '</a>';
            },
            $text
        ) ?? $text;

        // 兜底：剥离所有未在白名单内的方括号标签，防止它们以原文形式泄露
        $text = preg_replace_callback(
            '/\[\/?([a-zA-Z][a-zA-Z0-9]*)(=[^\]]*)?\]/',
            static function (array $m): string {
                return in_array(strtolower($m[1]), self::UBB_TAGS, true) ? $m[0] : '';
            },
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * 段落内换行转 <br>
     *
     * 块级标签附近不补 <br>，避免出现多余空行。
     */
    private static function applyLineBreaks(string $text): string
    {
        $lines  = explode("\n", $text);
        $output = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $isBlock = preg_match('#^<(h[1-6]|ul|ol|li|blockquote|pre|hr|p|div|table)#i', $trimmed) === 1;
            $isClose = preg_match('#^</(ul|ol|blockquote|pre|p|div|table)#i', $trimmed) === 1;

            $output[] = ($isBlock || $isClose) ? $trimmed : $trimmed . '<br>';
        }

        return implode("\n", $output);
    }

    /**
     * URL 白名单校验
     *
     * @param string $url         原始 URL（已 HTML 转义）
     * @param bool   $imageOnly   是否仅允许图片场景（禁止站内锚点）
     * @param bool   $allowPlain  是否允许不带协议的纯文本 URL
     * @return string 合法时返回可安全放入属性的 URL，否则空串
     */
    private static function safeUrl(string $url, bool $imageOnly = false, bool $allowPlain = false): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // 去掉换行与空白，阻断 "java\nscript:" 之类的绕过
        $url = preg_replace('/[\s\x00-\x1F\x7F]/', '', $url) ?? '';

        if ($url === '') {
            return '';
        }

        // 明确拒绝危险协议
        if (preg_match('/^(javascript|vbscript|data|file|blob|about|chrome):/i', $url)) {
            return '';
        }

        // 允许站内相对地址与锚点
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            if ($imageOnly && str_starts_with($url, '#')) {
                return '';
            }

            return htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // 允许 http / https
        if (preg_match('#^https?://#i', $url)) {
            // 仅允许 ASCII 且长度合理的地址
            if (strlen($url) > 2000 || !preg_match('#^https?://[A-Za-z0-9\-._~:/?#\[\]@!$&\'()*+,;=%]+$#', $url)) {
                return '';
            }

            return htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // 其它协议（mailto / ftp 等）一律拒绝
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            return '';
        }

        if ($allowPlain && substr_count($url, '.') >= 1) {
            return htmlspecialchars('https://' . $url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }
}

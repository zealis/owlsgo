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

    /**
     * 站内图片允许出现的前缀（静态资源目录）
     *
     * 只有这些目录下的站内地址才允许出现在 <img src>，其余站内地址一律降级为文字 ——
     * 原因见 imageAllowed()。
     */
    private const IMAGE_PATH_PREFIXES = ['/attachment/', '/avatar/', '/media/', '/assets/'];

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
        // 表格：整行都是 | 分隔的单元格，摘要里没有保留的价值
        $text = preg_replace('/^\s*\|.*$/m', ' ', $text) ?? $text;
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
        $total  = count($lines);
        $output = [];
        $inList = null;   // 'ul' | 'ol' | null
        $inQuote = false;

        $closeList = static function () use (&$inList, &$output): void {
            if ($inList !== null) {
                $output[] = '</' . $inList . '>';
                $inList   = null;
            }
        };

        // 表格要往下看一行（分隔行）并整块吃掉，所以这里用带下标的 for 而不是 foreach
        for ($i = 0; $i < $total; $i++) {
            $trimmed = trim($lines[$i]);
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

            /*
             * 表格：本行含 | 且下一行是 |---|---| 形态的分隔行。
             *
             * 分隔行是表格的强制签名 —— 少了这一条，正文里随手写的「a | b」
             * 就会被误判成表格，所以宁可要求用户写出完整表头。
             */
            if (str_contains($trimmed, '|') && $i + 1 < $total) {
                [$tableHtml, $next] = self::parseTable($lines, $i);

                if ($tableHtml !== '') {
                    $closeList();
                    $output[] = $tableHtml;
                    $i        = $next - 1;   // for 的 $i++ 会把游标推到 $next
                    continue;
                }
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

            // 有序列表（保留起始序号：渲染成 <ol start="3"> 才不会与作者写下的编号对不上）
            if (preg_match('/^(\d+)\.\s+(.*)$/', $trimmed, $m)) {
                if ($inList !== 'ol') {
                    $closeList();
                    $start    = max(1, (int)$m[1]);
                    $output[] = $start > 1 ? '<ol start="' . $start . '">' : '<ol>';
                    $inList   = 'ol';
                }
                $output[] = '<li>' . $m[2] . '</li>';
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
     * 拆一行表格单元格
     *
     * 支持 `\|` 转义，且行内代码里的 | 不算分隔符（`a | b` 这种写法在代码里很常见）。
     *
     * @return list<string>
     */
    private static function tableCells(string $line): array
    {
        $line = trim($line);

        // 首尾的竖线是可选的装饰，不属于任何单元格
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }

        if (str_ends_with($line, '|')) {
            $line = substr($line, 0, -1);
        }

        $cells   = [];
        $cell    = '';
        $code    = false;
        $escaped = false;

        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            $char = $line[$i];

            if ($escaped) {
                $cell   .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $cell   .= $char;
                $escaped = true;
                continue;
            }

            if ($char === '`') {
                $code = !$code;
            }

            if ($char === '|' && !$code) {
                $cells[] = trim(str_replace('\\|', '|', $cell));
                $cell    = '';
                continue;
            }

            $cell .= $char;
        }

        $cells[] = trim(str_replace('\\|', '|', $cell));

        return $cells;
    }

    /**
     * 表格分隔行判定
     *
     * @param  list<string> $cells 已拆分的一行
     * @return list<string>|null   对齐方式列表（'' | 'left' | 'center' | 'right'）；不是分隔行时返回 null
     */
    private static function tableAligns(array $cells): ?array
    {
        if ($cells === [] || $cells === ['']) {
            return null;
        }

        $aligns = [];

        foreach ($cells as $cell) {
            $cell = trim($cell);

            // 至少要三个减号，避免把 `-` 这种普通文本当成表格
            if (preg_match('/^:?-{3,}:?$/', $cell) !== 1) {
                return null;
            }

            $left  = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');

            $aligns[] = $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
        }

        return $aligns;
    }

    /**
     * 渲染表格
     *
     * 对齐不写内联 style，改用 class 交给 theme.css —— 内联样式在深色模式下
     * 无法被主题变量覆盖，而且颜色与间距也不该散落在渲染器里。
     *
     * @param  list<string> $lines 已转义并按行拆好的正文
     * @param  int          $start 表头所在行下标
     * @return array{0:string,1:int} [表格 HTML（非法时为空串）, 首个未被消费的行下标]
     */
    private static function parseTable(array $lines, int $start): array
    {
        $headers = self::tableCells($lines[$start]);
        $aligns  = self::tableAligns(self::tableCells($lines[$start + 1] ?? ''));

        // 列数必须与分隔行一致，否则不认作表格（宁可当普通段落，也不要渲染出半截表）
        if ($aligns === null || $headers === [] || count($headers) !== count($aligns)) {
            return ['', $start];
        }

        $attr = static fn (string $align): string => $align === '' ? '' : ' class="md-align-' . $align . '"';

        $html = '<div class="content-table-wrap"><table class="content-table"><thead><tr>';

        foreach ($headers as $index => $header) {
            $html .= '<th' . $attr((string)($aligns[$index] ?? '')) . '>' . $header . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        $cursor = $start + 2;

        for ($count = count($lines); $cursor < $count; $cursor++) {
            $line = trim($lines[$cursor]);

            // 空行或不再含竖线的行 → 表格到此结束
            if ($line === '' || !str_contains($line, '|')) {
                break;
            }

            $cells = self::tableCells($lines[$cursor]);

            if ($cells === ['']) {
                break;
            }

            $html .= '<tr>';

            // 以表头列数为准：多出来的单元格丢弃，缺的补空，行与行之间列数始终对齐
            foreach (array_keys($headers) as $index) {
                $html .= '<td' . $attr((string)($aligns[$index] ?? '')) . '>'
                    . (string)($cells[$index] ?? '') . '</td>';
            }

            $html .= '</tr>';
        }

        return [$html . '</tbody></table></div>', $cursor];
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

                // 站内地址必须落在静态资源目录，见 imageAllowed() 的说明
                if ($url === '' || !self::imageAllowed($m[2])) {
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
                $raw = trim($m[1]);
                $url = self::safeUrl($raw, true);

                if ($url === '' || !self::imageAllowed($raw)) {
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

            /*
             * 表格各行的标签也要算块级：解析器生成的表格是多行 HTML，
             * 漏掉 tr/td 就会被补上 <br>，表格里凭空多出一堆空行。
             */
            $isBlock = preg_match('#^<(h[1-6]|ul|ol|li|blockquote|pre|hr|p|div|table|thead|tbody|tr|td|th)#i', $trimmed) === 1;
            $isClose = preg_match('#^</(ul|ol|blockquote|pre|p|div|table|thead|tbody|tr|td|th)#i', $trimmed) === 1;

            $output[] = ($isBlock || $isClose) ? $trimmed : $trimmed . '<br>';
        }

        return implode("\n", $output);
    }

    /**
     * 站内图片地址白名单
     *
     * 图片会在**别人打开帖子时自动发出请求**，所以站内地址必须限制在静态资源目录，
     * 否则 `![](/logout)` 这类写法会让每个看帖的人被动触发一次站内请求
     * —— `/logout` 是 GET，看帖即被踢下线；将来若出现任何按 GET 触发副作用的接口，
     * 后果只会更大。这是渲染器该守的边界，不能指望用户不这么写。
     *
     * 判定分三种情况：
     *  - 协议相对地址（//host/…）与外站地址：指向别的站点，不会带本站 Cookie，放行；
     *  - 本站地址（/… 或 http(s)://本站/…）：路径必须落在 IMAGE_PATH_PREFIXES 内；
     *  - 其它（相对路径、data: 等）：不放行 —— data: 在 safeUrl 里已被拒绝。
     *
     * @param string $url 原始 URL（可能已 HTML 转义，这里会先解码再判定）
     */
    private static function imageAllowed(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // 控制字符与空白一律非法：既拦 `java\nscript:`，也拦路径里的换行注入
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '//')) {
            return true;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            $self = strtolower((string)parse_url('//' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));

            // 拿不到 Host（CLI 场景）或指向别的站点 → 不是本站请求，放行
            if ($host === '' || $self === '' || $host !== $self) {
                return true;
            }

            return self::staticPathAllowed((string)parse_url($url, PHP_URL_PATH));
        }

        if (!str_starts_with($url, '/')) {
            return false;
        }

        return self::staticPathAllowed((string)parse_url($url, PHP_URL_PATH));
    }

    /**
     * 路径是否落在站内静态资源目录
     *
     * 先解码再判 `..`：`/%2e%2e/logout` 这种写法解码后必须同样被拦下。
     */
    private static function staticPathAllowed(string $path): bool
    {
        $path = rawurldecode($path);

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        foreach (self::IMAGE_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
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
            /*
             * 仅允许 ASCII 且长度合理的地址。
             * ⚠️ 字符类里的 # 必须写成 \# —— 分隔符就是 #，不转义的话 PCRE 会在类里那个 #
             * 处提前收尾，整条正则编译失败（Unknown modifier '\'），于是**所有绝对地址
             * 一律被拒**：外链与图片会悄悄退化成纯文本，且不报任何错。
             */
            if (strlen($url) > 2000 || !preg_match('#^https?://[A-Za-z0-9\-._~:/?\#\[\]@!$&\'()*+,;=%]+$#', $url)) {
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

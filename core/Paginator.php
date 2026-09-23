<?php
/**
 * 分页器
 *
 * 输出与 ui.css 一致的语义化标记（<nav> + <menu class="buttons">）。
 * 出于安全与性能考虑：
 *  - 页码上限由 app.max_page 控制，避免深分页把数据库拖垮
 *  - 所有页面链接都通过 url() 生成，参数经过编码，杜绝参数注入
 */

declare(strict_types=1);

namespace Core;

final class Paginator
{
    /**
     * 渲染分页控件
     *
     * @param array{total:int,page:int,pages:int,per_page:int} $pagination
     * @param string                                           $path     基础路径，如 /f/3
     * @param array<string, mixed>                             $query    附加查询参数
     * @param string                                           $pageKey  页码参数名，默认 page。
     *                                                                  同一页上有两个独立列表时（如通知中心
     *                                                                  的「公告」与「我的通知」）必须给其中一个
     *                                                                  换名字，否则两者共用 ?page 会互相覆盖 ——
     *                                                                  翻其中一个会把另一个也翻页。
     */
    public static function render(
        array $pagination,
        string $path,
        array $query = [],
        string $pageKey = 'page'
    ): string {
        $page  = max(1, (int)($pagination['page'] ?? 1));
        $pages = max(1, (int)($pagination['pages'] ?? 1));
        $total = max(0, (int)($pagination['total'] ?? 0));

        if ($pages <= 1) {
            return '';
        }

        $link = static fn (int $target): string
            => e(Router::url($path, array_merge($query, [$pageKey => $target])));

        $items = [];

        /*
         * 注意所有按钮只能用 <a> 或 <button>：
         * ui.css 的按钮基础样式选择器是 :is(button, ..., a.button)，
         * <span class="button"> 完全匹配不上 —— 不会拿到 padding / flex 布局，
         * 直接塌成一个 7px 宽的裸文字条（这个坑实际踩过：当前页原本用 span）。
         * 禁用态用 <button disabled>，浏览器原生不可点，样式也由 ui.css 接管。
         */

        // 上一页
        $items[] = $page > 1
            ? '<li><a class="button outline small" href="' . $link($page - 1) . '" rel="prev">上一页</a></li>'
            : '<li><button type="button" class="button outline small" disabled>上一页</button></li>';

        // 页码窗口：首页、尾页、当前页 ±2
        $window = [];
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
                $window[] = $i;
            }
        }

        $previous = 0;
        foreach ($window as $number) {
            if ($previous !== 0 && $number - $previous > 1) {
                $items[] = '<li><span class="pagination-ellipsis" aria-hidden="true">…</span></li>';
            }

            if ($number === $page) {
                // 当前页同样是 <a>（指向自己），aria-current 负责标注「这就是当前页」，
                // 实心高亮交给 theme.css 的 .pagination [aria-current] 规则
                $items[] = '<li><a class="button small" aria-current="page" href="' . $link($number) . '">' . $number . '</a></li>';
            } else {
                $items[] = '<li><a class="button outline small" href="' . $link($number) . '">' . $number . '</a></li>';
            }

            $previous = $number;
        }

        // 下一页
        $items[] = $page < $pages
            ? '<li><a class="button outline small" href="' . $link($page + 1) . '" rel="next">下一页</a></li>'
            : '<li><button type="button" class="button outline small" disabled>下一页</button></li>';

        return '<nav class="pagination" aria-label="分页导航">'
            . '<menu class="buttons">' . implode('', $items) . '</menu>'
            . '</nav>';
    }

    /**
     * 计算安全的页码（限制在 app.max_page 之内）
     */
    public static function page(int $requested): int
    {
        $max = max(1, (int)config('app.max_page', 500));

        return min(max(1, $requested), $max);
    }

    /**
     * 把分页信息转换为模板可直接使用的结构
     *
     * @param array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function normalize(array $result): array
    {
        return [
            'items'    => $result['items'] ?? [],
            'total'    => (int)($result['total'] ?? 0),
            'page'     => (int)($result['page'] ?? 1),
            'pages'    => (int)($result['pages'] ?? 1),
            'per_page' => (int)($result['per_page'] ?? 20),
        ];
    }
}

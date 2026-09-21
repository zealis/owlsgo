<?php
/**
 * 编辑器接口
 *
 * 目前只有一个能力：把编辑器里的正文交给服务端渲染成 HTML，供「实时预览」那一栏使用。
 *
 * 为什么预览必须走服务端：
 *   正文在写入时就被 Core\Text::toHtml() 渲染成 HTML 缓存在 posts.content_html 里，
 *   发布后读到的是那份缓存。前端若自己再实现一套 Markdown 渲染器，两边必然漂移
 *   （表格、UBB、@提及、附件权限降级都很难对齐）——「边写边看」就会变成「边写边被骗」。
 *   所以这里复用与发布完全相同的渲染函数，预览与成稿逐字一致。
 */

declare(strict_types=1);

namespace Modules\Editor;

use Core\Controller;
use Core\Request;
use Core\Text;

final class EditorController extends Controller
{
    /**
     * 预览正文长度上限
     *
     * 与编辑器里的字数上限同量级。超出只截断、不报错：预览是辅助手段，
     * 不该因为用户多敲了几个字就变成一片错误提示。
     */
    private const PREVIEW_MAX = 200000;

    /**
     * 实时预览
     *
     * 请求：POST /editor/preview，字段 content（编辑器正文）
     * 响应：{"ok":true,"html":"…"}
     */
    public function preview(array $params): never
    {
        $this->requireLogin();

        $content = (string)Request::post('content', '');

        if (mb_strlen($content) > self::PREVIEW_MAX) {
            $content = mb_substr($content, 0, self::PREVIEW_MAX);
        }

        $this->json([
            'ok'   => true,
            'html' => Text::toHtml($content),
        ]);
    }
}

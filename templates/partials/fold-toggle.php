<?php
/**
 * 长内容折叠按钮（帖子楼层与全站通知共用）
 *
 * 传入：
 *   $foldTarget  被折叠容器的 id（由 content_fold() 给出），为空则不输出
 *
 * 设计要点：
 *  - **默认 hidden**：内容到底有没有超过阈值，要等前端量出真实渲染高度才知道
 *    （图片、代码块、宽表格的高度服务端算不出来）。所以按钮统一先渲染成隐藏，
 *    由 app.js 判断后决定是否显示 —— 无 JS 时页面就是完整正文，与老站点一致。
 *  - 文案与 aria-expanded 都由前端切换，这里只放初始态（收起 = 展开全文）。
 *  - 不写内联脚本、不用内联事件，符合站点 CSP（script-src 'self'）。
 */

declare(strict_types=1);

$foldTarget = isset($foldTarget) ? (string)$foldTarget : '';
?>

<?php if ($foldTarget !== ''): ?>
    <div class="fold-actions">
        <button type="button" class="fold-toggle" data-fold-toggle
                aria-expanded="false" aria-controls="<?= e($foldTarget) ?>" hidden>
            <span data-fold-label>展开全文</span>
            <?= $view('partials/icon', ['name' => 'chevron-down', 'size' => 14]) ?>
        </button>
    </div>
<?php endif; ?>

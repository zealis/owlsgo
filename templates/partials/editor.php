<?php
/**
 * 富文本编辑器（Markdown 工具栏 + 表情 + 实时预览 + 全屏 + 附件上传）
 *
 * 传入（全部可选，均有默认值，避免调用方遗漏）：
 *   $editorName        textarea 的 name，默认 content
 *   $editorId          元素 id 前缀，默认取 $editorName
 *   $editorValue       初始内容（会被转义）
 *   $editorMax         最大字数，0 表示不限制
 *   $editorUpload      是否显示附件上传，默认 false
 *   $editorMaxMb       单文件大小上限（MB），默认 2
 *   $editorPlaceholder 占位文案
 *   $editorAttachments 已有附件（编辑页回显）
 *
 * 设计要点：
 *  - 工具栏与正文框共享一条边框（工具栏下边框去掉、正文框上边框去掉），
 *    与站点其他组件一样维持「紧贴 + 共享边框」的视觉整合；
 *  - 预览一律由服务端渲染（POST /editor/preview → Core\Text::toHtml），
 *    与发布后的成稿逐字一致，不在前端再实现一份 Markdown 渲染器；
 *  - 全部行为放在 app.js 的「编辑器」一节，用事件委托 + data-* 标记驱动，
 *    页面里没有任何内联脚本，符合站点 CSP（script-src 'self'）。
 */

declare(strict_types=1);

$name        = isset($editorName) && (string)$editorName !== '' ? (string)$editorName : 'content';
$id          = isset($editorId) && (string)$editorId !== '' ? (string)$editorId : $name;
$value       = isset($editorValue) ? (string)$editorValue : '';
$max         = isset($editorMax) ? (int)$editorMax : 0;
$upload      = (bool)($editorUpload ?? false);
$maxMb       = (int)($editorMaxMb ?? 2);
$placeholder = isset($editorPlaceholder)
    ? (string)$editorPlaceholder
    : '支持 Markdown：**加粗**、`代码`、> 引用、[链接](https://)、| 表格 |';

/* 已有附件回显：编辑页传入，让「刷新后附件不丢」成为可能 */
$attachments = isset($editorAttachments) && is_array($editorAttachments) ? $editorAttachments : [];

/* 附件容器 id 必须唯一，避免同页多个编辑器互相覆盖 */
$targetId = 'attachments-' . preg_replace('/[^A-Za-z0-9_-]/', '', $id);

/* 上传用的 file input id：工具栏第一个按钮要代理它，所以先算好 */
$filesId = $id . '-files';

$maxMb = max(1, (int)$maxMb);

/**
 * 工具栏按钮
 *
 * 提示一律写成 title —— ui.css 的 tooltip.js 会把它转成样式化气泡；
 * 额外带 data-ow-tooltip-placement="bottom"，避免气泡向上弹出时被卡片上沿裁掉
 * （卡片 .panel 是 overflow: hidden，见 theme.css 的说明）。
 */
$mdButton = static function (string $attribute, string $value, string $icon, string $label, string $extra = '', string $aria = '') use ($view): string {
    /*
     * aria-label 默认与 title 一致；「上传附件」这类提示很长（带大小上限），
     * 读屏念一整句不合适，所以单独给一个短标签。
     */
    return '<button type="button" class="editor-btn' . ($extra !== '' ? ' ' . $extra : '') . '"'
        . ' ' . $attribute . '="' . e($value) . '"'
        . ' title="' . e($label) . '" aria-label="' . e($aria !== '' ? $aria : $label) . '"'
        . ' data-ow-tooltip-placement="bottom">'
        . $view('partials/icon', ['name' => $icon, 'size' => 16])
        . '</button>';
};

/*
 * 工具栏顺序（按用户指定，右侧三个已并入左边同排）：
 *   上传附件 → 标题 → 粗体 → 斜体 → 删除线
 *   ｜ 引用 → 代码 → 代码块 → 无序列表 → 有序列表
 *   ｜ 添加链接 → 添加图片 → 表情 → 预览 → 全屏写作
 *
 * 「上传附件」把大小上限写进悬浮提示，正文下方不再重复展示
 * 「上传附件」按钮与「单个文件 ≤ N MB」那一行。
 */
$mdGroups = [
    array_merge(
        $upload ? [['upload', 'upload', '上传附件，单个文件 ≤ ' . $maxMb . ' MB']] : [],
        [
            ['heading', 'heading', '标题'],
            ['bold', 'bold', '粗体'],
            ['italic', 'italic', '斜体'],
            ['strike', 'strike', '删除线'],
        ]
    ),
    [
        ['quote', 'quote', '引用'],
        ['code', 'code', '行内代码'],
        ['code_block', 'code-block', '代码块'],
        ['ul', 'list', '无序列表'],
        ['ol', 'list-ol', '有序列表'],
    ],
    [
        ['link', 'link', '添加链接'],
        ['image', 'image', '添加图片'],
        ['emoji', 'emoji', '插入表情'],
        ['preview', 'eye', '实时预览'],
        ['fullscreen', 'fullscreen', '全屏写作'],
    ],
];
?>
<div class="editor" data-editor
     data-editor-preview="<?= e(url('/editor/preview')) ?>"
     data-editor-hotkey="1"<?= $upload ? ' data-editor-paste="1"' : '' ?>>

    <div class="editor-bar" role="toolbar" aria-label="编辑工具栏">
        <?php $groupCount = count($mdGroups); ?>
        <?php foreach ($mdGroups as $index => $group): ?>
            <?php $lastAction = $group[count($group) - 1][0]; ?>
            <?php if ($index > 0): ?><span class="editor-split" aria-hidden="true"></span><?php endif; ?>
            <?php foreach ($group as [$action, $icon, $label]): ?>
                <?php if ($action === 'emoji'): ?>
                    <?= $mdButton('data-editor-emoji', '1', $icon, $label) ?>
                <?php elseif ($action === 'upload'): ?>
                    <?php /* 代理下方隐藏的原生 file input，工具样式与其它按钮完全一致 */ ?>
                    <?= $mdButton('data-editor-upload', '#' . $filesId, $icon, $label, '', '上传附件') ?>
                <?php else: ?>
                    <?php
                    /* 最后一个按钮贴着卡片右缘，气泡要改为贴右展开（见 theme.css） */
                    $isLast = $index === $groupCount - 1 && $action === $lastAction;
                    ?>
                    <?= $mdButton('data-editor-action', $action, $icon, $label, $isLast ? 'editor-btn--end' : '') ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <span class="editor-spacer"></span>

        <span class="char-counter" data-max="<?= $max ?>">0<?= $max > 0 ? ' / ' . $max : '' ?></span>
    </div>

    <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="12"
              placeholder="<?= e($placeholder) ?>"><?= e($value) ?></textarea>

    <?php /* 实时预览：内容由服务端渲染后填进来（app.js 里 400ms 防抖） */ ?>
    <div class="editor-preview post-content" hidden aria-live="polite"></div>

    <?php /* 排版速查：默认收起，需要时才展开，不占版面 */ ?>
    <details class="editor-help">
        <summary>排版速查</summary>
        <div class="editor-help__body">
            <code>**粗体**</code> <code>*斜体*</code> <code>~~删除线~~</code>
            <code># 标题</code> <code>&gt; 引用</code>
            <code>- 无序列表</code> <code>1. 有序列表</code>
            <code>`行内代码`</code> <code>``` 代码块</code>
            <code>[链接](https://example.com)</code>
            <code>![图片](/attachment/1)</code>
            <code>| 表头 | 表头 |<br>| --- | --- |<br>| 内容 | 内容 |</code>
        </div>
    </details>
</div>

<?php if ($upload): ?>
    <div class="editor-upload">
        <?php
        /*
         * 附件上传区（编辑器下方）：
         *  - 「上传附件」入口已移到工具栏第一个图标，悬浮提示里带大小上限，
         *    这里不再重复按钮与「单个文件 ≤ N MB」那一行；
         *  - 只保留「批量插入」与附件列表（一个附件一行：左名称、右状态与操作）；
         *  - 编辑器开了「粘贴上传」（data-editor-paste）时，正文里直接粘贴截图
         *    也会走这条通道上传并插入 Markdown，不需要先点上传按钮。
         */
        ?>
        <div class="editor-upload-bar">
            <button type="button" class="ow-button ow-ghost ow-small" data-insert-all="#<?= e($targetId) ?>"
                    title="把已上传的全部附件以 Markdown 形式插入正文">
                <?= $view('partials/icon', ['name' => 'paperclip', 'size' => 14]) ?>
                <span>批量插入</span>
            </button>
        </div>
        <input type="file" id="<?= e($filesId) ?>" name="files[]" multiple hidden
               data-upload="<?= e(url('/upload')) ?>" data-upload-target="#<?= e($targetId) ?>">
        <div id="<?= e($targetId) ?>" class="attachment-list"<?= $attachments === [] ? ' hidden' : '' ?>>
            <?php
            /* 已有附件回显：结构与 app.js 动态创建的行完全一致，
               复制/移除走事件委托，所以回显行同样可复制、可从列表移除。 */
            foreach ($attachments as $att):
                $attId      = (int)($att['id'] ?? 0);
                $attName    = (string)($att['name'] ?? '');
                $attIsImage = !empty($att['is_image']);
                $attUrl     = url('/attachment/' . $attId);
                $md         = ($attIsImage ? '!' : '') . '[' . $attName . '](' . $attUrl . ')';
            ?>
                <?php /* data-att-* 与 JS 动态创建的行保持一致，草稿才能完整保存/还原附件 */ ?>
                <div class="attachment-row" data-markdown="<?= e($md) ?>"
                     data-attachment="<?= $attId ?>"
                     data-att-name="<?= e($attName) ?>"
                     data-att-size="<?= e((string)($att['size_text'] ?? '')) ?>"
                     data-att-key="<?= e($attName) ?>:<?= (int)($att['size'] ?? 0) ?>"
                     data-att-image="<?= $attIsImage ? '1' : '0' ?>"
                     data-att-url="<?= e($attUrl) ?>">
                    <div class="attachment-row__name" title="<?= e($attName) ?>">
                        <span><?= e($attName) ?><?= !empty($att['size_text']) ? '（' . e($att['size_text']) . '）' : '' ?></span>
                    </div>
                    <div class="attachment-row__right">
                        <button type="button" class="attachment-icon-btn" data-attach-copy
                                title="点击复制 Markdown 引用">
                            <?= $view('partials/icon', ['name' => 'copy', 'size' => 15]) ?>
                        </button>
                        <button type="button" class="attachment-icon-btn attachment-icon-btn--danger"
                                data-attach-remove title="从列表中移除">
                            <?= $view('partials/icon', ['name' => 'trash', 'size' => 15]) ?>
                        </button>
                    </div>
                    <input type="hidden" name="attachments[]" value="<?= $attId ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php
/**
 * 富文本编辑器（Markdown 提示 + 插入工具 + 字数统计 + 可选附件上传）
 *
 * 传入（全部可选，均有默认值，避免调用方遗漏）：
 *   $editorName    textarea 的 name，默认 content
 *   $editorId      元素 id 前缀，默认取 $editorName
 *   $editorValue   初始内容（会被转义）
 *   $editorMax     最大字数，0 表示不限制
 *   $editorUpload  是否显示附件上传，默认 false
 *   $editorMaxMb   单文件大小上限（MB），默认 2
 *   $editorPlaceholder 占位文案
 *
 * 说明：工具栏按钮统一使用 [data-insert] + 事件委托（见 app.js），
 * 不产生任何内联脚本，符合站点 CSP 只允许 'self' 的要求。
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
    : '支持 Markdown：**加粗**、`代码`、> 引用、[链接](https://)';

/* 已有附件回显：编辑页传入，让「刷新后附件不丢」成为可能 */
$attachments = isset($editorAttachments) && is_array($editorAttachments) ? $editorAttachments : [];

/* 附件容器 id 必须唯一，避免同页多个编辑器互相覆盖 */
$targetId = 'attachments-' . preg_replace('/[^A-Za-z0-9_-]/', '', $id);
?>
<div class="editor">
    <div class="editor-bar">
        <button type="button" class="button ghost small" data-insert="bold" title="加粗">B</button>
        <button type="button" class="button ghost small" data-insert="italic" title="斜体"><em>I</em></button>
        <button type="button" class="button ghost small" data-insert="code" title="行内代码">Code</button>
        <button type="button" class="button ghost small" data-insert="codeblock" title="代码块">代码块</button>
        <button type="button" class="button ghost small" data-insert="quote" title="引用">引用</button>
        <button type="button" class="button ghost small" data-insert="link" title="链接">链接</button>
        <span class="spacer"></span>
        <span class="char-counter" data-max="<?= $max ?>">0<?= $max > 0 ? ' / ' . $max : '' ?></span>
    </div>

    <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="12"
              placeholder="<?= e($placeholder) ?>"><?= e($value) ?></textarea>
</div>

<?php if ($upload): ?>
    <div style="margin-top:12px">
        <?php
        /*
         * 附件上传区：
         *  - 左下角的「上传附件」小图标按钮代理隐藏的原生 file input（不再显示
         *    「选择文件 未选择任何文件」的默认控件）；
         *  - 右侧是「批量插入」；
         *  - 上传记录以「一个附件一行」的列表呈现：左名称、右状态与操作。
         */
        $filesId = $id . '-files';
        ?>
        <div class="editor-upload-bar">
            <button type="button" class="button ghost small" data-editor-upload="#<?= e($filesId) ?>"
                    title="上传图片与附件">
                <?= $view('partials/icon', ['name' => 'upload', 'size' => 15]) ?>
                <span>上传附件</span>
            </button>
            <span class="spacer"></span>
            <button type="button" class="button ghost small" data-insert-all="#<?= e($targetId) ?>"
                    title="把已上传的全部附件以 Markdown 形式插入正文">
                <?= $view('partials/icon', ['name' => 'paperclip', 'size' => 14]) ?>
                <span>批量插入</span>
            </button>
        </div>
        <input type="file" id="<?= e($filesId) ?>" name="files[]" multiple hidden
               data-upload="<?= e(url('/upload')) ?>" data-upload-target="#<?= e($targetId) ?>">
        <div id="<?= e($targetId) ?>" class="attachment-list">
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

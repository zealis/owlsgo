<?php
/**
 * 布尔开关行（后台设置用）
 *
 * 变量：$name（设置键，同时作为 checkbox 的 name）、$label、$hint（可为空）、$checked（bool）
 *
 * 说明为空时用居中对齐：只有一行标题还按 flex-start 会让勾选框吊在文字上方。
 *
 * ⚠️ 复选框未勾选时浏览器不会提交该字段 —— 服务端按「本页未提交即 0」处理，
 *    所以每个开关都必须真实渲染出来，不能靠 hidden input 兜底。
 */

declare(strict_types=1);

$name    = (string)($name ?? '');
$label   = (string)($label ?? '');
$hint    = (string)($hint ?? '');
$checked = (bool)($checked ?? false);
?>
<label class="hstack" style="gap:8px;align-items:<?= $hint === '' ? 'center' : 'flex-start' ?>;margin-bottom:12px">
    <input type="checkbox" class="switch" name="<?= e($name) ?>" value="1" <?= $checked ? 'checked' : '' ?>>
    <?php if ($hint === ''): ?>
        <strong style="font-size:14px"><?= e($label) ?></strong>
    <?php else: ?>
        <span>
            <strong style="font-size:14px"><?= e($label) ?></strong>
            <span class="text-light" style="display:block;font-size:12.5px"><?= e($hint) ?></span>
        </span>
    <?php endif; ?>
</label>

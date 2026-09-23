<?php
/**
 * 设置页的保存条
 *
 * 放在分组页面最后一个 .panel 的末尾 —— 与其它后台表单（版块 / 用户组）一致：
 * 按钮跟着卡片走，卡片边缘共享同一条边线，而不是漂在页面底部的独立一条。
 *
 * 变量：$hint（可选，覆盖默认说明文案）
 */

declare(strict_types=1);

$hint = (string)($hint ?? '保存后会立即清空缓存，前台即刻生效。');
?>
<div class="panel__foot ow-hstack">
    <button type="submit" class="ow-button">
        <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
        <span>保存设置</span>
    </button>
    <span class="ow-text-light" style="font-size:12.5px"><?= e($hint) ?></span>
</div>

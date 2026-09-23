<?php
/**
 * 后台列表的批量操作条
 *
 * 放在 `.panel` 内、表格上方。**不套 `<form>`**：表格每行已经有自己的操作表单
 * （通过/删除），HTML 不允许表单嵌套 —— 所以这里是一个 `[data-bulk]` 容器，
 * 由 app.js 的 initAdminBulk() 收集勾选项后 POST 到 $bulkEndpoint。
 *
 * 传入：
 *   $bulkEndpoint  提交地址（POST），必填
 *   $bulkOptions   选项 list<array{value:string, label:string, confirm:string,
 *                                 danger?:bool, extra?:string}>；`extra` 指定该动作
 *                 需要哪个额外控件（如 `forum` → 页面上 [data-bulk-extra="forum"] 的版块下拉），
 *                 没选额外控件时会拒绝提交而不是盲发请求
 *   $bulkExtras    额外控件的 HTML（通常是一个隐藏的 `<label data-bulk-extra="forum">`）
 *   $bulkNoun      计数单位，默认「项」
 *
 * 表格侧需要配套：表头 `<input type="checkbox" data-bulk-all>`、
 * 每行 `<input type="checkbox" data-bulk-item value="...">`。
 */

declare(strict_types=1);

$bulkEndpoint = (string)($bulkEndpoint ?? '');
$bulkOptions  = is_array($bulkOptions ?? null) ? $bulkOptions : [];
$bulkExtras   = (string)($bulkExtras ?? '');
$bulkNoun     = (string)($bulkNoun ?? '项');

if ($bulkEndpoint === '' || $bulkOptions === []) {
    return;   // 没配好就不渲染，避免出现一个点了没反应的条
}
?>
<div class="bulk-bar" data-bulk data-bulk-endpoint="<?= e($bulkEndpoint) ?>" data-bulk-noun="<?= e($bulkNoun) ?>">
    <?= csrf_field() ?>

    <label class="bulk-bar__pick ow-hstack" style="gap:6px">
        <input type="checkbox" data-bulk-all>
        <span>全选本页</span>
    </label>

    <span class="bulk-bar__count" data-bulk-count>已选 0 <?= e($bulkNoun) ?></span>

    <span class="spacer"></span>

    <?= $bulkExtras ?>

    <select data-bulk-action style="width:220px">
        <option value="">批量操作…</option>
        <?php foreach ($bulkOptions as $option): ?>
            <option value="<?= e((string)$option['value']) ?>"
                    data-confirm="<?= e((string)$option['confirm']) ?>"
                    <?= !empty($option['danger']) ? 'data-danger="1"' : '' ?>
                    <?= !empty($option['extra']) ? 'data-extra="' . e((string)$option['extra']) . '"' : '' ?>>
                <?= e((string)$option['label']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="button" class="ow-button ow-small" data-bulk-run disabled>
        <?= $view('partials/icon', ['name' => 'check', 'size' => 15]) ?>
        <span>执行</span>
    </button>
</div>

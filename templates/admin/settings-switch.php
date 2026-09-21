<?php
/**
 * 后台设置：站点开关
 *
 * 分组 slug：switch
 * 字段：site_closed / site_closed_reason / debug_mode
 *
 * 变量：$val、$isOn（取值助手）
 */

declare(strict_types=1);

$toggles = [
    'site_closed' => ['关闭站点', '开启后前台会展示维护提示，管理员仍可正常访问后台。'],
    'debug_mode'  => ['调试模式', '开启后记录 debug 级日志、出错页显示详细报错。仅用于排错，用完请及时关闭 —— 报错细节可能暴露路径、SQL 与配置信息。'],
];
?>
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'shield', 'size' => 16]) ?>站点开关</h3>
    </div>
    <div class="panel__body">
        <?= $view('partials/admin-switch', [
            'name'    => 'site_closed',
            'label'   => $toggles['site_closed'][0],
            'hint'    => $toggles['site_closed'][1],
            'checked' => $isOn('site_closed'),
        ]) ?>

        <div data-field>
            <label for="site_closed_reason">维护提示语</label>
            <textarea id="site_closed_reason" name="site_closed_reason" rows="2" maxlength="200"><?= e($val('site_closed_reason', '站点正在维护，请稍后再访问。')) ?></textarea>
        </div>

        <div style="margin-top:4px">
            <?= $view('partials/admin-switch', [
                'name'    => 'debug_mode',
                'label'   => $toggles['debug_mode'][0],
                'hint'    => $toggles['debug_mode'][1],
                'checked' => $isOn('debug_mode'),
            ]) ?>
        </div>
    </div>

    <?= $view('partials/admin-save-foot') ?>
</section>

<?php
/**
 * 后台设置：系统与维护（站点开关 + 系统维护合成一页）
 *
 * 分组 slug：system（form = false —— 页内自带两个独立表单，外壳不套表单）
 * 字段：site_closed / site_closed_reason / debug_mode
 *
 * 两张卡片各自独立成 <form>：
 *  - 站点开关：POST /admin/settings/system（保存设置）
 *  - 清理 OPcache：POST /admin/maintenance/opcache
 * 套在一起会变成 form 嵌套，也会让「清缓存」顺带提交一遍设置。
 *
 * 变量：$val、$isOn（取值助手）、$opcacheAvailable、$debugEnabled
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

    <form method="post" action="<?= e(url('/admin/settings/system')) ?>" data-ajax data-ajax-redirect>
        <?= csrf_field() ?>

        <div class="panel__body">
            <?= $view('partials/admin-switch', [
                'name'    => 'site_closed',
                'label'   => $toggles['site_closed'][0],
                'hint'    => $toggles['site_closed'][1],
                'checked' => $isOn('site_closed'),
            ]) ?>

            <div data-ow-field>
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
    </form>
</section>

<section class="panel" style="margin-top:var(--space-4)">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'server', 'size' => 16]) ?>系统维护</h3>
    </div>
    <div class="panel__body">
        <dl class="kv" style="margin:0 0 14px">
            <dt>站点版本</dt>
            <dd class="mono">v<?= e(config('app.version', '1.0.0')) ?></dd>

            <dt>OPcache</dt>
            <dd><?= $opcacheAvailable ? '已启用' : '未启用（无需清理）' ?></dd>

            <dt>调试模式</dt>
            <dd><?= $debugEnabled ? '已开启' : '已关闭' ?></dd>

            <dt>日志目录</dt>
            <dd><code>storage/logs/</code></dd>
        </dl>

        <div class="ow-hstack" style="flex-wrap:wrap;gap:8px">
            <form method="post" action="<?= e(url('/admin/maintenance/opcache')) ?>"
                  data-ajax class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="ow-button ow-outline ow-small"<?= $opcacheAvailable ? '' : ' disabled' ?>>
                    <?= $view('partials/icon', ['name' => 'refresh', 'size' => 15]) ?>
                    <span>清理 OPcache</span>
                </button>
            </form>

            <a class="ow-button ow-outline ow-small" href="<?= e(url('/admin/upgrade')) ?>">
                <?= $view('partials/icon', ['name' => 'download', 'size' => 15]) ?>
                <span>在线升级</span>
            </a>

            <a class="ow-button ow-outline ow-small" href="<?= e(url('/admin/logs/system')) ?>">
                <?= $view('partials/icon', ['name' => 'file', 'size' => 15]) ?>
                <span>查看系统日志</span>
            </a>
        </div>

        <p class="ow-text-light" style="margin:12px 0 0;font-size:12.5px">
            改完 PHP 源码若页面没变化，多半是 OPcache 缓存了旧代码，点一下清理即可（会同时清空站点缓存）。<br>
            系统日志里是 PHP 报错与异常堆栈；调试模式关闭时不会产生 <code>debug</code> 日志，但错误始终会记录。
        </p>
    </div>
</section>

<?php
/**
 * 后台设置：系统维护
 *
 * 分组 slug：maintenance（form = false，页内自带独立表单）
 *
 * 这一页刻意放在设置分组里而不是侧栏一级菜单：改完设置发现页面没变，就来这里清 OPcache。
 *
 * 两个动作（清缓存、看日志）与「保存设置」是两回事，不该被同一个表单一起提交，
 * 所以它们各自独立成 <form>；模板外壳也不会给本页套表单（避免 form 嵌套）。
 *
 * 变量：$opcacheAvailable、$debugEnabled
 */

declare(strict_types=1);
?>
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'server', 'size' => 16]) ?>系统维护</h3>
    </div>
    <div class="panel__body">
        <dl class="kv" style="margin:0 0 14px">
            <dt>OPcache</dt>
            <dd><?= $opcacheAvailable ? '已启用' : '未启用（无需清理）' ?></dd>

            <dt>调试模式</dt>
            <dd><?= $debugEnabled ? '已开启' : '已关闭' ?></dd>

            <dt>日志目录</dt>
            <dd><code>storage/logs/</code></dd>
        </dl>

        <div class="hstack" style="flex-wrap:wrap;gap:8px">
            <form method="post" action="<?= e(url('/admin/maintenance/opcache')) ?>"
                  data-ajax class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="button outline small"<?= $opcacheAvailable ? '' : ' disabled' ?>>
                    <?= $view('partials/icon', ['name' => 'refresh', 'size' => 15]) ?>
                    <span>清理 OPcache</span>
                </button>
            </form>

            <a class="button outline small" href="<?= e(url('/admin/logs/system')) ?>">
                <?= $view('partials/icon', ['name' => 'file', 'size' => 15]) ?>
                <span>查看系统日志</span>
            </a>
        </div>

        <p class="text-light" style="margin:12px 0 0;font-size:12.5px">
            改完 PHP 源码若页面没变化，多半是 OPcache 缓存了旧代码，点一下清理即可（会同时清空站点缓存）。<br>
            系统日志里是 PHP 报错与异常堆栈；调试模式关闭时不会产生 <code>debug</code> 日志，但错误始终会记录。
        </p>
    </div>
</section>

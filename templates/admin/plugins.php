<?php
/**
 * 后台：插件管理
 *
 * 变量：$rows（id/name/version/description/author/url/enabled/loaded/hook_count/settings/entry_ok/has_assets/path）、
 *       $total、$enabledCount、$bundleReady
 *
 * 说明：
 *  - loaded 表示该插件在当前请求中确实被加载（已启用且入口文件存在）
 *  - entry_ok 为 false 说明 manifest 声明的入口文件找不到，插件无法运行
 *  - 卸载只清理数据库登记，插件目录与自建表需要管理员手动清理
 */

declare(strict_types=1);

$rows         = is_array($rows ?? null) ? $rows : [];
$total        = (int)($total ?? count($rows));
$enabledCount = (int)($enabledCount ?? 0);
$bundleReady  = (bool)($bundleReady ?? false);
$hasAssets    = false;

foreach ($rows as $row) {
    /*
     * 只有「已启用 + 声明了静态资源」的插件才需要检查合并产物。
     *
     * 这里曾经漏判 enabled：只要目录里有插件声明了 assets 就报警，
     * 于是全新安装（示例插件在但未启用）会稳定误报「合并资源尚未生成」——
     * 而未启用的插件本来就不会加载任何资源，前台根本不缺样式。
     */
    if (!empty($row['enabled']) && !empty($row['has_assets'])) {
        $hasAssets = true;
        break;
    }
}
?>

<?php if ($hasAssets && !$bundleReady): ?>
    <div role="alert" data-variant="warning" style="margin-bottom:var(--space-4)">
        <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
        <div>
            有插件声明了静态资源，但合并资源尚未生成，前台可能缺少样式或脚本。
            <a href="<?= e(url('/plugin-assets/css')) ?>" target="_blank" rel="noopener">检查 CSS</a> ·
            <a href="<?= e(url('/plugin-assets/js')) ?>" target="_blank" rel="noopener">检查 JS</a>
        </div>
    </div>
<?php endif; ?>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'plug', 'size' => 16]) ?>插件列表</h3>
        <span class="spacer"></span>
        <span class="text-light" style="font-size:13px">
            共 <?= $total ?> 个 · 已启用 <?= $enabledCount ?> 个
        </span>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'plug', 'size' => 46]) ?>
            <p>还没有发现任何插件。</p>
            <p class="text-light" style="font-size:13px">
                把插件目录放进 <code>plugins/</code> 并确保其中包含 <code>plugin.json</code>，
                刷新本页即可自动识别。
            </p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>插件</th>
                    <th style="width:80px">钩子</th>
                    <th style="width:80px">配置项</th>
                    <th style="width:110px">状态</th>
                    <th style="width:250px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $plugin): ?>
                    <?php
                    $pluginId   = (string)($plugin['id'] ?? '');
                    $pluginUrl  = rawurlencode($pluginId);
                    $isEnabled  = (bool)($plugin['enabled'] ?? false);
                    $isLoaded   = (bool)($plugin['loaded'] ?? false);
                    $entryOk    = (bool)($plugin['entry_ok'] ?? false);
                    $settingNum = (int)($plugin['settings'] ?? 0);
                    $authorUrl  = (string)($plugin['url'] ?? '');
                    ?>
                    <tr>
                        <td>
                            <div class="hstack" style="gap:8px;align-items:flex-start">
                                <strong><?= e((string)($plugin['name'] ?? $pluginId)) ?></strong>
                                <span class="badge outline mono" style="font-size:11.5px">
                                    v<?= e((string)($plugin['version'] ?? '0')) ?>
                                </span>
                                <?php if (!$entryOk): ?>
                                    <span class="badge" data-variant="danger">入口文件缺失</span>
                                <?php endif; ?>
                                <?php if ($isEnabled && !$isLoaded): ?>
                                    <span class="badge" data-variant="warning">已启用但未加载</span>
                                <?php endif; ?>
                            </div>

                            <?php if ((string)($plugin['description'] ?? '') !== ''): ?>
                                <span class="text-light" style="display:block;font-size:12.5px;margin-top:3px">
                                    <?= e((string)$plugin['description']) ?>
                                </span>
                            <?php endif; ?>

                            <span class="text-light mono" style="display:block;font-size:11.5px;margin-top:3px">
                                <?= e($pluginId) ?>
                                <?php if ((string)($plugin['author'] ?? '') !== ''): ?>
                                    · 作者：
                                    <?php if ($authorUrl !== ''): ?>
                                        <a href="<?= e($authorUrl) ?>" target="_blank" rel="noopener nofollow">
                                            <?= e((string)$plugin['author']) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= e((string)$plugin['author']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($plugin['has_assets'])): ?>
                                    · 含静态资源
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="text-light"><?= (int)($plugin['hook_count'] ?? 0) ?></td>
                        <td class="text-light"><?= $settingNum ?></td>
                        <td>
                            <?php if ($isEnabled): ?>
                                <span class="badge outline"><span class="status-dot"></span>已启用</span>
                            <?php else: ?>
                                <span class="badge outline"><span class="status-dot status-dot--off"></span>已停用</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-table-actions">
                                <?php if ($settingNum > 0): ?>
                                    <a class="button small ghost"
                                       href="<?= e(url('/admin/plugins/' . $pluginUrl . '/config')) ?>">
                                        <?= $view('partials/icon', ['name' => 'settings', 'size' => 14]) ?>
                                        <span>配置</span>
                                    </a>
                                <?php endif; ?>

                                <?php if ($isEnabled): ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/plugins/' . $pluginUrl . '/disable')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="button small ghost">
                                            <?= $view('partials/icon', ['name' => 'pause', 'size' => 14]) ?>
                                            <span>停用</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/plugins/' . $pluginUrl . '/enable')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="button small"
                                                <?= $entryOk ? '' : 'disabled' ?>>
                                            <?= $view('partials/icon', ['name' => 'play', 'size' => 14]) ?>
                                            <span>启用</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form class="inline-form" method="post"
                                      action="<?= e(url('/admin/plugins/' . $pluginUrl . '/uninstall')) ?>"
                                      data-confirm="确定要卸载「<?= e((string)($plugin['name'] ?? $pluginId)) ?>」吗？插件目录与其自建数据表需要你手动清理。">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="button small ghost" data-variant="danger">
                                        <?= $view('partials/icon', ['name' => 'trash', 'size' => 14]) ?>
                                        <span>卸载</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="panel__foot text-light" style="font-size:12.5px">
        卸载只会移除数据库中的登记记录，不会删除磁盘上的插件目录，也不会回滚插件自建的数据表。
    </div>
</section>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'bookmark', 'size' => 16]) ?>插件开发速览</h3>
    </div>
    <div class="panel__body">
        <p style="margin-top:0;font-size:13.5px;color:var(--qq-ink-2)">
            一个插件就是一个目录，最小结构如下：
        </p>
        <pre class="snippet"><code>plugins/example/
├── plugin.json     必需，声明名称、版本、入口与钩子
├── Plugin.php      入口文件，注册钩子、路由与后台页面
├── assets/         可选，样式与脚本（经 /plugin-file/{id}/{path} 分发）
├── sql/            可选，{driver}.sql 建表脚本，启用插件时自动执行
└── templates/      可选，插件自带模板（view('plugin/example/xxx')）</code></pre>

        <div class="doc-note" style="margin-top:12px">
            入口文件中的任何 <code>.php</code> 文件都不会被 Web 直接访问：
            插件静态资源只允许白名单内的类型（css/js/图片/字体）经
            <code>/plugin-file/{id}/{path}</code> 分发。
        </div>
    </div>
</section>

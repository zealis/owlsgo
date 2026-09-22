<?php
/**
 * 后台：操作日志
 *
 * 变量：$result（items 已补 username / avatar / time_text）、$action、$keyword、
 *       $actions（出现过的动作种类）、$total、$pagination、$exportUrl
 *
 * 只读页面：导出按钮直接指向 /admin/logs/export，会把当前筛选条件一并带上，
 * 因此「先筛选再导出」得到的就是屏幕上这批记录。
 */

declare(strict_types=1);

$result   = is_array($result ?? null) ? $result : ['items' => []];
$items    = is_array($result['items'] ?? null) ? $result['items'] : [];
$action   = (string)($action ?? '');
$keyword  = (string)($keyword ?? '');
$actions  = is_array($actions ?? null) ? $actions : [];
$total    = (int)($total ?? 0);
$exportUrl = (string)($exportUrl ?? url('/admin/logs/export'));

$hasFilter = $action !== '' || $keyword !== '';

/** 动作类型 → 中文（未收录的动作原样展示，筛选下拉同样使用） */
$actionLabels = [
    'user.login'         => '登录成功',
    'user.login.fail'    => '登录失败',
    'user.logout'        => '退出登录',
    'user.register'      => '注册账号',
    'user.update'        => '更新用户资料',
    'account.update'     => '更新账号设置',
    'user.moderates'     => '调整版主版块',
    'user.unban'         => '解除封禁',
    'user.ban'           => '封禁用户',
    'forum.create'       => '创建版块',
    'forum.update'       => '更新版块',
    'forum.delete'       => '删除版块',
    'group.create'       => '创建用户组',
    'group.update'       => '更新用户组',
    'group.delete'       => '删除用户组',
    'thread.approve'     => '审核通过帖子',
    'thread.delete'      => '删除帖子',
    'post.approve'       => '审核通过评论',
    'post.delete'        => '删除评论',
    'attachment.delete'  => '删除附件',
    'plugin.enable'      => '启用插件',
    'plugin.disable'     => '停用插件',
    'plugin.uninstall'   => '卸载插件',
    'plugin.upload'      => '上传安装插件',
    'plugin.config'      => '保存插件配置',
    'cron.run'           => '执行计划任务',
    'cron.toggle'        => '切换计划任务',
    'cron.token'         => '重置触发令牌',
    'settings.save'      => '保存站点设置',
    'log.export'         => '导出日志',
    'system.log.clear'   => '清空系统日志',
    'notice.create'      => '发布公告',
    'notice.update'      => '更新公告',
    'notice.delete'      => '删除公告',
    'notice.settings'    => '更新通知中心设置',
];
$actionText = static fn (string $name): string => $actionLabels[$name] ?? $name;
?>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'list', 'size' => 16]) ?>操作日志</h3>
        <span class="spacer"></span>
        <a class="button outline small" href="<?= e(url('/admin/logs/system')) ?>">
            <?= $view('partials/icon', ['name' => 'file', 'size' => 15]) ?>
            <span>系统日志</span>
        </a>
        <span class="spacer"></span>
        <span class="text-light" style="font-size:13px">
            <?= $hasFilter
                ? '筛选结果 ' . number_format((int)($result['total'] ?? 0)) . ' 条 / 全部 ' . number_format($total) . ' 条'
                : '共 ' . number_format($total) . ' 条' ?>
        </span>
        <a class="button small ghost" href="<?= e($exportUrl) ?>">
            <?= $view('partials/icon', ['name' => 'download', 'size' => 15]) ?>
            <span>导出 CSV</span>
        </a>
    </div>

    <form class="admin-filter" method="get" action="<?= e(url('/admin/logs')) ?>">
        <label>
            动作类型
            <select name="action" style="width:190px">
                <option value="">全部动作</option>
                <?php foreach ($actions as $name): ?>
                    <option value="<?= e((string)$name) ?>" <?= selected($action, (string)$name) ?>>
                        <?= e($actionText((string)$name)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php /* 与前台同一套胶囊搜索框（.search-box），放大镜按钮即提交 */ ?>
        <div class="search-box search-box--admin">
            <input type="search" name="q" value="<?= e($keyword) ?>" maxlength="60"
                   placeholder="详情 / 对象 / IP" aria-label="日志关键词">
            <button type="submit" aria-label="筛选">
                <?= $view('partials/icon', ['name' => 'search', 'size' => 16]) ?>
            </button>
        </div>

        <?php if ($hasFilter): ?>
            <a class="button small ghost" href="<?= e(url('/admin/logs')) ?>">重置</a>
        <?php endif; ?>
    </form>

    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'list', 'size' => 46]) ?>
            <p><?= $hasFilter ? '没有符合条件的日志。' : '还没有任何操作记录。' ?></p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <?php /* admin-list：固定列宽 + 长文本截断（详情最多两行），见 theme.css「后台列表」一节 */ ?>
            <table class="admin-list admin-list--logs">
                <thead>
                <tr>
                    <th style="width:150px">时间</th>
                    <th style="width:150px">操作者</th>
                    <th style="width:150px">动作</th>
                    <th style="width:170px">对象</th>
                    <th style="width:300px">详情</th>
                    <th style="width:130px">IP</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $log): ?>
                    <?php
                    $actionName = (string)($log['action'] ?? '');
                    $avatar     = is_array($log['avatar'] ?? null) ? $log['avatar'] : null;
                    ?>
                    <tr>
                        <td class="text-light mono" style="font-size:12.5px">
                            <?= e((string)($log['time_text'] ?? '—')) ?>
                        </td>
                        <td>
                            <?php if ($avatar !== null): ?>
                                <div class="hstack" style="gap:7px;align-items:center">
                                    <?= avatar_img($avatar, 22) ?>
                                    <a href="<?= e(url('/admin/users/' . (int)($log['user_id'] ?? 0))) ?>">
                                        <?= e((string)($log['username'] ?? '')) ?>
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="text-light">
                                    <?= e((string)($log['username'] ?? '系统')) ?>
                                    <span class="mono" style="font-size:11.5px">
                                        #<?= (int)($log['user_id'] ?? 0) ?>
                                    </span>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge outline"><?= e($actionText((string)($actionName ?? '')) !== '' ? $actionText((string)($actionName ?? '')) : '—') ?></span>
                        </td>
                        <td class="mono text-light" style="font-size:12.5px">
                            <?= e((string)($log['target'] ?? '') !== '' ? (string)$log['target'] : '—') ?>
                        </td>
                        <td><span class="cell-title"><?= e((string)($log['detail'] ?? '')) ?></span></td>
                        <td class="mono text-light" style="font-size:12.5px">
                            <?= e((string)($log['ip'] ?? '') !== '' ? (string)$log['ip'] : '—') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="panel__foot text-light" style="font-size:12.5px">
        导出为 CSV（UTF-8 带 BOM，Excel 可直接打开中文），单次最多导出 5000 条。
        写日志失败不会影响主流程，因此极端情况下可能缺失个别记录。
    </div>

    <?php if (($pagination ?? '') !== ''): ?>
        <div class="panel__foot"><?= (string)$pagination ?></div>
    <?php endif; ?>
</section>

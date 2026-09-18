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
                        <?= e((string)$name) ?>
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
            <table>
                <thead>
                <tr>
                    <th style="width:150px">时间</th>
                    <th style="width:150px">操作者</th>
                    <th style="width:150px">动作</th>
                    <th style="width:170px">对象</th>
                    <th>详情</th>
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
                            <span class="badge outline"><?= e($actionName !== '' ? $actionName : '—') ?></span>
                        </td>
                        <td class="mono text-light" style="font-size:12.5px">
                            <?= e((string)($log['target'] ?? '') !== '' ? (string)$log['target'] : '—') ?>
                        </td>
                        <td><?= e((string)($log['detail'] ?? '')) ?></td>
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

<?php
/**
 * 后台：用户管理（列表 + 筛选）
 *
 * 变量：$result（items 已 decorateMany）、$keyword、$groupId、$groups、$pagination
 */

declare(strict_types=1);

$result   = is_array($result ?? null) ? $result : ['items' => []];
$items    = is_array($result['items'] ?? null) ? $result['items'] : [];
$keyword  = (string)($keyword ?? '');
$groupId  = (int)($groupId ?? 0);
$groups   = is_array($groups ?? null) ? $groups : [];
?>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'users', 'size' => 16]) ?>用户列表</h3>
        <span class="spacer"></span>
        <span class="text-light" style="font-size:13px">
            共 <?= number_format((int)($result['total'] ?? 0)) ?> 位用户
        </span>
    </div>

    <form class="admin-filter" method="get" action="<?= e(url('/admin/users')) ?>">
        <label>
            用户组
            <select name="group" style="width:170px">
                <option value="0">全部用户组</option>
                <?php foreach ($groups as $id => $name): ?>
                    <option value="<?= (int)$id ?>" <?= selected($groupId, $id) ?>>
                        <?= e((string)$name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php /* 与前台同一套胶囊搜索框（.search-box），放大镜按钮即提交 */ ?>
        <div class="search-box search-box--admin">
            <input type="search" name="q" value="<?= e($keyword) ?>" maxlength="60"
                   placeholder="用户名或邮箱" aria-label="用户名或邮箱">
            <button type="submit" aria-label="筛选">
                <?= $view('partials/icon', ['name' => 'search', 'size' => 16]) ?>
            </button>
        </div>

        <?php if ($keyword !== '' || $groupId > 0): ?>
            <a class="button small ghost" href="<?= e(url('/admin/users')) ?>">重置</a>
        <?php endif; ?>
    </form>

    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'users', 'size' => 46]) ?>
            <p><?= $keyword !== '' || $groupId > 0 ? '没有符合条件的用户。' : '还没有注册用户。' ?></p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>用户</th>
                    <th style="width:200px">邮箱</th>
                    <th style="width:70px">主题</th>
                    <th style="width:70px">回复</th>
                    <th style="width:70px">积分</th>
                    <th style="width:90px">状态</th>
                    <th style="width:120px">注册时间</th>
                    <th style="width:110px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $user): ?>
                    <?php
                    $userId   = (int)($user['id'] ?? 0);
                    $status   = (int)($user['status'] ?? 1);
                    $groupCol = (string)($user['group_color'] ?? '#999999');
                    ?>
                    <tr>
                        <td>
                            <div class="hstack" style="gap:9px;align-items:flex-start">
                                <?= avatar_img($user, 32) ?>
                                <span style="min-width:0">
                                    <a href="<?= e(url('/admin/users/' . $userId)) ?>">
                                        <?= e((string)($user['username'] ?? '')) ?>
                                    </a>
                                    <span class="badge outline" style="margin-left:6px;color:<?= e($groupCol) ?>">
                                        <?= e((string)($user['group_name'] ?? '游客')) ?>
                                    </span>
                                    <span class="text-light mono" style="display:block;font-size:11.5px">
                                        #<?= $userId ?>
                                        <?php if ((string)($user['signature'] ?? '') !== ''): ?>
                                            · <?= e(mb_substr((string)$user['signature'], 0, 24)) ?>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </div>
                        </td>
                        <td class="text-light mono"><?= e((string)($user['email'] ?? '')) ?></td>
                        <td><?= number_format((int)($user['thread_count'] ?? 0)) ?></td>
                        <td><?= number_format((int)($user['post_count'] ?? 0)) ?></td>
                        <td><?= number_format((int)($user['points'] ?? 0)) ?></td>
                        <td>
                            <?php if ($status === 1): ?>
                                <span class="badge outline"><span class="status-dot"></span>正常</span>
                            <?php else: ?>
                                <span class="badge outline"><span class="status-dot status-dot--off"></span>已禁用</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-light"><?= e(human_time((int)($user['created_at'] ?? 0))) ?></td>
                        <td>
                            <div class="admin-table-actions">
                                <a class="button small ghost" href="<?= e(url('/admin/users/' . $userId)) ?>">
                                    <?= $view('partials/icon', ['name' => 'edit', 'size' => 14]) ?>
                                    <span>管理</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (($pagination ?? '') !== ''): ?>
        <div class="panel__foot"><?= (string)$pagination ?></div>
    <?php endif; ?>
</section>

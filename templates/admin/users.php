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
        <span class="ow-text-light" style="font-size:13px">
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
            <a class="ow-button ow-small ow-ghost" href="<?= e(url('/admin/users')) ?>">重置</a>
        <?php endif; ?>
    </form>

    <?php
    /*
     * 批量操作：删除账号。
     * 四种范围直接对应服务端 bulk() 的 action 取值（见 UserController::bulk）：
     *   account    只删账号 → 内容留着，作者名显示「用户已删除」
     *   其余三种   账号 + 帖子 / 评论 / 两者，内容走软删，可在回收站恢复
     * 自己与超级管理员组的行在下方被禁用勾选，服务端也会再拦一次。
     */
    echo $view('partials/admin-bulk-bar', [
        'bulkEndpoint' => url('/admin/users/bulk'),
        'bulkNoun'     => '个账号',
        'bulkOptions'  => [
            [
                'value'   => 'account',
                'label'   => '只删账号',
                'confirm' => '确认删除选中的 {n} 个账号吗？他们的帖子与评论会保留，作者名显示为「用户已删除」，个人主页变为 404。',
            ],
            [
                'value'   => 'account_threads',
                'label'   => '账号 + 帖子',
                'confirm' => '确认删除选中的 {n} 个账号，并连带删除他们的帖子吗？帖子下**他人的评论**也会一起删除（可在回收站恢复）。',
                'danger'  => true,
            ],
            [
                'value'   => 'account_posts',
                'label'   => '账号 + 评论',
                'confirm' => '确认删除选中的 {n} 个账号，并连带删除他们的评论吗？（首帖属于帖子，不在其中；可在回收站恢复）',
                'danger'  => true,
            ],
            [
                'value'   => 'account_all',
                'label'   => '账号 + 帖子 + 评论',
                'confirm' => '确认删除选中的 {n} 个账号，并连带删除他们的全部帖子与评论吗？内容可在回收站恢复。',
                'danger'  => true,
            ],
        ],
    ]);
    ?>

    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'users', 'size' => 46]) ?>
            <p><?= $keyword !== '' || $groupId > 0 ? '没有符合条件的用户。' : '还没有注册用户。' ?></p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="admin-list admin-list--users">
                <thead>
                <tr>
                    <th class="bulk-check"></th>
                    <th style="width:240px">用户</th>
                    <th style="width:200px">邮箱</th>
                    <th style="width:70px">帖子</th>
                    <th style="width:70px">评论</th>
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
                        <td class="bulk-check">
                            <?php
                            /*
                             * 自己与超级管理员组不能删（服务端也会拦），这里直接禁用勾选，
                             * 避免「勾了却发现被跳过」的困惑。
                             */
                            $cannotDelete = $userId === (int)($currentUserId ?? 0)
                                || (int)($user['group_id'] ?? 0) === \Core\Permission::SUPER_GROUP;
                            ?>
                            <input type="checkbox" data-bulk-item value="<?= $userId ?>"
                                   <?= $cannotDelete ? 'disabled title="不能删除自己或超级管理员"' : '' ?>
                                   aria-label="选择用户">
                        </td>
                        <td>
                            <div class="ow-hstack" style="gap:9px;align-items:flex-start">
                                <?= avatar_img($user, 32) ?>
                                <span style="min-width:0">
                                    <div class="cell-row">
                                        <a class="cell-title" href="<?= e(url('/admin/users/' . $userId)) ?>"
                                           title="<?= e((string)($user['username'] ?? '')) ?>">
                                            <?= e((string)($user['username'] ?? '')) ?>
                                        </a>
                                        <span class="ow-badge ow-outline" style="color:<?= e($groupCol) ?>">
                                            <?= e((string)($user['group_name'] ?? '游客')) ?>
                                        </span>
                                    </div>
                                <?php /* UID 不在这里显示：用户自己用不到，管理员要定位有搜索与邮箱 */ ?>
                                <?php if (trim((string)($user['bio'] ?? '')) !== ''): ?>
                                    <span class="cell-title ow-text-light" style="display:block;font-size:11.5px">
                                        <?= e(mb_substr(trim((string)$user['bio']), 0, 24)) ?>
                                    </span>
                                <?php endif; ?>
                                </span>
                            </div>
                        </td>
                        <td class="ow-text-light mono">
                            <span class="cell-title" title="<?= e((string)($user['email'] ?? '')) ?>"><?= e((string)($user['email'] ?? '')) ?></span>
                        </td>
                        <td><?= number_format((int)($user['thread_count'] ?? 0)) ?></td>
                        <?php /* 评论数要减掉本人帖子数（post_count 含首帖），见 user_comment_count() */ ?>
                        <td><?= number_format(user_comment_count($user)) ?></td>
                        <td><?= number_format((int)($user['points'] ?? 0)) ?></td>
                        <td>
                            <?php if ($status === 1): ?>
                                <span class="ow-badge ow-outline"><span class="status-dot"></span>正常</span>
                            <?php else: ?>
                                <span class="ow-badge ow-outline"><span class="status-dot status-dot--off"></span>已禁用</span>
                            <?php endif; ?>
                        </td>
                        <td class="ow-text-light"><?= e(human_time((int)($user['created_at'] ?? 0))) ?></td>
                        <td>
                            <div class="admin-table-actions">
                                <a class="ow-button ow-small ow-ghost" href="<?= e(url('/admin/users/' . $userId)) ?>">
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

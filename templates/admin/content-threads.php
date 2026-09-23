<?php
/**
 * 后台：帖子管理
 *
 * 变量：$result（items 已 decorate，不含摘要）、$keyword、$status（-1 全部 / 0 待审核 / 1 已通过）、
 *       $forums（版块下拉，用作 forum_name 缺失时的兜底）、$canApprove、$pending、$pagination
 */

declare(strict_types=1);

$result     = is_array($result ?? null) ? $result : ['items' => []];
$items      = is_array($result['items'] ?? null) ? $result['items'] : [];
$keyword    = (string)($keyword ?? '');
$status     = (int)($status ?? -1);
$forums     = is_array($forums ?? null) ? $forums : [];
$canApprove = (bool)($canApprove ?? false);
$pending    = (int)($pending ?? 0);

$statusOptions = [-1 => '全部状态', 0 => '待审核', 1 => '已通过'];
?>

<section class="panel">
    <?php /* 内容管理的三个页签（帖子 / 回帖 / 回收站），与前台共用 .tabbar */ ?>
    <?= $view('partials/admin-content-tabs', ['tabsActive' => 'threads']) ?>

    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'file', 'size' => 16]) ?>帖子管理</h3>
        <span class="spacer"></span>
        <?php if ($pending > 0): ?>
            <span class="ow-badge" data-ow-variant="warning"><?= $pending ?> 条待审核</span>
        <?php endif; ?>
        <span class="ow-text-light" style="font-size:13px">
            共 <?= number_format((int)($result['total'] ?? 0)) ?> 个帖子
        </span>
    </div>

    <form class="admin-filter" method="get" action="<?= e(url('/admin/threads')) ?>">
        <label>
            审核状态
            <select name="status" style="width:140px">
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= (int)$value ?>" <?= selected($status, $value) ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php /* 搜索范围：决定关键词去匹配哪个字段（见 ThreadModel::applyAdminSearch） */ ?>
        <label>
            搜索范围
            <select name="scope" style="width:120px">
                <?php foreach ($scopes as $value => $label): ?>
                    <option value="<?= e((string)$value) ?>" <?= selected($scope, $value) ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php /* 与前台同一套胶囊搜索框（.search-box），放大镜按钮即提交 */ ?>
        <div class="search-box search-box--admin">
            <input type="search" name="q" value="<?= e($keyword) ?>" maxlength="60"
                   placeholder="<?= e($scope === 'id' ? '输入帖子 ID…' : '输入关键词…') ?>" aria-label="搜索关键词">
            <button type="submit" aria-label="筛选">
                <?= $view('partials/icon', ['name' => 'search', 'size' => 16]) ?>
            </button>
        </div>

        <?php if ($keyword !== '' || $status !== -1 || $scope !== 'all'): ?>
            <a class="ow-button ow-small ow-ghost" href="<?= e(url('/admin/threads')) ?>">重置</a>
        <?php endif; ?>

        <?php if ($pending > 0 && $status !== 0): ?>
            <span class="spacer"></span>
            <a class="ow-button ow-small" href="<?= e(url('/admin/threads', ['status' => 0])) ?>">
                <?= $view('partials/icon', ['name' => 'filter', 'size' => 15]) ?>
                <span>只看待审核</span>
            </a>
        <?php endif; ?>
    </form>

    <?php
    /*
     * 批量操作条。动作与服务端 bulkThreads() 的 action 取值一一对应：
     *   move   → 需要额外参数 forum_id，界面上由「转移到」版块下拉提供
     *   delete → 软删除，可到回收站恢复
     */
    echo $view('partials/admin-bulk-bar', [
        'bulkEndpoint' => url('/admin/threads/bulk'),
        'bulkNoun'     => '个帖子',
        'bulkExtras'   => '<label class="bulk-target" data-bulk-extra="forum_id" hidden>转移到'
            . '<select name="forum_id" style="width:150px"><option value="">选择版块…</option>'
            . implode('', array_map(
                static fn (int $fid, string $fname): string => '<option value="' . $fid . '">' . e($fname) . '</option>',
                array_keys($forums),
                array_values($forums)
            ))
            . '</select></label>',
        'bulkOptions'  => [
            [
                'value'   => 'move',
                'label'   => '批量转移版块',
                'confirm' => '确认把选中的 {n} 个帖子转移到指定版块吗？帖子的评论会一起转移。',
                'extra'   => 'forum_id',
            ],
            [
                'value'   => 'delete',
                'label'   => '批量删除',
                'confirm' => '确认删除选中的 {n} 个帖子吗？其下所有评论会一并删除（可在回收站恢复）。',
                'danger'  => true,
            ],
        ],
    ]);
    ?>

    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'file', 'size' => 46]) ?>
            <p><?= $keyword !== '' || $status !== -1 ? '没有符合条件的帖子。' : '还没有任何帖子。' ?></p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <?php /*
             * admin-list：固定列宽布局，长文本列由 .cell-title 单行截断（.cell-row 用于「主值 + 徽章」）。
             * 不这么做的话，一条长标题就会把「标题」列撑宽、把其它列挤扁，
             * 移动端更是整张表横向拉长，滚不到头也看不清。
             */ ?>
            <table class="admin-list admin-list--threads">
                <thead>
                <tr>
                    <th class="bulk-check"></th>
                    <th style="width:280px">标题</th>
                    <th style="width:130px">作者</th>
                    <th style="width:120px">版块</th>
                    <th style="width:65px">评论</th>
                    <th style="width:65px">浏览</th>
                    <th style="width:90px">状态</th>
                    <th style="width:120px">发表时间</th>
                    <th style="width:200px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $thread): ?>
                    <?php
                    $threadId  = (int)($thread['id'] ?? 0);
                    $forumId   = (int)($thread['forum_id'] ?? 0);
                    $isPending = (int)($thread['status'] ?? 1) !== 1;
                    $forumName = (string)($thread['forum_name'] ?? '') !== ''
                        ? (string)$thread['forum_name']
                        : (string)($forums[$forumId] ?? '未知版块');
                    ?>
                    <tr>
                        <td class="bulk-check">
                            <input type="checkbox" data-bulk-item value="<?= $threadId ?>"
                                   aria-label="选择帖子：<?= e((string)($thread['title'] ?? '')) ?>">
                        </td>
                        <td>
                            <?php /* 标题单行截断（悬停有原生 tooltip 看全文），徽章紧跟其后不换行 */ ?>
                            <div class="cell-row">
                                <a class="cell-title" href="<?= e(url('/t/' . $threadId)) ?>"
                                   target="_blank" rel="noopener"
                                   title="<?= e((string)($thread['title'] ?? '')) ?>">
                                    <?= e((string)($thread['title'] ?? '')) ?>
                                </a>
                                <?php if ($isPending): ?>
                                    <span class="ow-badge" data-ow-variant="warning">待审核</span>
                                <?php endif; ?>
                                <?php if ((int)($thread['is_pinned'] ?? 0) === 1): ?>
                                    <span class="ow-badge ow-outline">置顶</span>
                                <?php endif; ?>
                                <?php if ((int)($thread['is_essence'] ?? 0) === 1): ?>
                                    <span class="ow-badge ow-outline">精华</span>
                                <?php endif; ?>
                                <?php if ((int)($thread['is_locked'] ?? 0) === 1): ?>
                                    <span class="ow-badge ow-outline">已锁定</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <a href="<?= e(url('/u/' . (int)($thread['user_id'] ?? 0))) ?>"
                               target="_blank" rel="noopener"
                               style="color:<?= e((string)($thread['author_group_color'] ?? '#999999')) ?>">
                                <?= e((string)($thread['author']['username'] ?? '用户已删除')) ?>
                            </a>
                        </td>
                        <td class="ow-text-light">
                            <a href="<?= e(url('/f/' . $forumId)) ?>" target="_blank" rel="noopener">
                                <?= e($forumName) ?>
                            </a>
                        </td>
                        <td><?= number_format((int)($thread['reply_count'] ?? 0)) ?></td>
                        <td><?= number_format((int)($thread['views'] ?? 0)) ?></td>
                        <td>
                            <?php if ($isPending): ?>
                                <span class="ow-badge ow-outline"><span class="status-dot status-dot--off"></span>待审核</span>
                            <?php else: ?>
                                <span class="ow-badge ow-outline"><span class="status-dot"></span>已通过</span>
                            <?php endif; ?>
                        </td>
                        <td class="ow-text-light"><?= e(human_time((int)($thread['created_at'] ?? 0))) ?></td>
                        <td>
                            <div class="admin-table-actions">
                                <?php if ($canApprove && $isPending): ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/threads/' . $threadId . '/approve')) ?>"
                                          data-confirm="确认通过《<?= e((string)($thread['title'] ?? '')) ?>》的审核吗？">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="ow-button ow-small">
                                            <?= $view('partials/icon', ['name' => 'check', 'size' => 14]) ?>
                                            <span>通过</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <a class="ow-button ow-small ow-ghost" href="<?= e(url('/t/' . $threadId)) ?>"
                                   target="_blank" rel="noopener">
                                    <?= $view('partials/icon', ['name' => 'eye', 'size' => 14]) ?>
                                    <span>查看</span>
                                </a>

                                <?php if ((bool)($thread['can_delete'] ?? true)): ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/threads/' . $threadId . '/delete')) ?>"
                                          data-confirm="确定要删除帖子《<?= e((string)($thread['title'] ?? '')) ?>》吗？其下所有评论会一并删除。">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="ow-button ow-small ow-ghost" data-ow-variant="danger">
                                            <?= $view('partials/icon', ['name' => 'trash', 'size' => 14]) ?>
                                            <span>删除</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="ow-button ow-small ow-ghost" disabled
                                            title="管理员发布的内容只有超级管理员可以删除">
                                        <?= $view('partials/icon', ['name' => 'lock', 'size' => 14]) ?>
                                        <span>删除</span>
                                    </button>
                                <?php endif; ?>
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

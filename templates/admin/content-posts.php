<?php
/**
 * 后台：评论管理
 *
 * 变量：$result（items 已 decorate，并补了 thread_title）、$keyword、
 *       $status（-1 全部 / 0 待审核 / 1 已通过）、$canApprove、$pending、$pagination
 *
 * 列表**只含回帖**（PostModel::adminPaginate 过滤 is_first = 0）：
 * 帖子首帖是「帖子」页签里那条内容，混进来只会让同一条正文出现两次。
 * 首帖的审核与删除都随帖子走，所以这里的删除按钮对首帖永远不该出现；
 * 服务端仍保留首帖保护（删帖接口会拒绝），模板里的分支只是兜底。
 */

declare(strict_types=1);

$result     = is_array($result ?? null) ? $result : ['items' => []];
$items      = is_array($result['items'] ?? null) ? $result['items'] : [];
$keyword    = (string)($keyword ?? '');
$status     = (int)($status ?? -1);
$canApprove = (bool)($canApprove ?? false);
$pending    = (int)($pending ?? 0);

$statusOptions = [-1 => '全部状态', 0 => '待审核', 1 => '已通过'];
?>

<section class="panel">
    <?php /* 内容管理的三个页签（帖子 / 回帖 / 回收站），与前台共用 .tabbar */ ?>
    <?= $view('partials/admin-content-tabs', ['tabsActive' => 'posts']) ?>

    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'message', 'size' => 16]) ?>评论管理</h3>
        <span class="spacer"></span>
        <?php if ($pending > 0): ?>
            <span class="ow-badge" data-ow-variant="warning"><?= $pending ?> 条待审核</span>
        <?php endif; ?>
        <span class="ow-text-light" style="font-size:13px">
            共 <?= number_format((int)($result['total'] ?? 0)) ?> 条评论
        </span>
    </div>

    <form class="admin-filter" method="get" action="<?= e(url('/admin/posts')) ?>">
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

        <?php /* 搜索范围：回帖内容 / 作者 / 回帖Id / 帖子Id（见 PostModel::applyAdminSearch） */ ?>
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
                   placeholder="<?= e(in_array($scope, ['id', 'thread'], true) ? '输入数字 ID…' : '输入关键词…') ?>"
                   aria-label="搜索关键词">
            <button type="submit" aria-label="筛选">
                <?= $view('partials/icon', ['name' => 'search', 'size' => 16]) ?>
            </button>
        </div>

        <?php if ($keyword !== '' || $status !== -1 || $scope !== 'content'): ?>
            <a class="ow-button ow-small ow-ghost" href="<?= e(url('/admin/posts')) ?>">重置</a>
        <?php endif; ?>

        <?php if ($pending > 0 && $status !== 0): ?>
            <span class="spacer"></span>
            <a class="ow-button ow-small" href="<?= e(url('/admin/posts', ['status' => 0])) ?>">
                <?= $view('partials/icon', ['name' => 'filter', 'size' => 15]) ?>
                <span>只看待审核</span>
            </a>
        <?php endif; ?>
    </form>

    <?php
    /* 批量操作：只有「删除」一种（评论不涉及版块归属），软删后可到回收站恢复 */
    echo $view('partials/admin-bulk-bar', [
        'bulkEndpoint' => url('/admin/posts/bulk'),
        'bulkNoun'     => '条评论',
        'bulkOptions'  => [
            [
                'value'   => 'delete',
                'label'   => '批量删除',
                'confirm' => '确认删除选中的 {n} 条评论吗？（可在回收站恢复）',
                'danger'  => true,
            ],
        ],
    ]);
    ?>

    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'message', 'size' => 46]) ?>
            <p><?= $keyword !== '' || $status !== -1 ? '没有符合条件的评论。' : '还没有任何评论。' ?></p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <?php /* admin-list：固定列宽 + 长文本自己截断（见 theme.css「后台列表」一节） */ ?>
            <table class="admin-list admin-list--posts">
                <thead>
                <tr>
                    <th class="bulk-check"></th>
                    <th style="width:300px">内容摘要</th>
                    <th style="width:130px">作者</th>
                    <th style="width:200px">所属帖子</th>
                    <th style="width:70px">楼层</th>
                    <th style="width:90px">状态</th>
                    <th style="width:120px">发表时间</th>
                    <th style="width:200px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $post): ?>
                    <?php
                    $postId    = (int)($post['id'] ?? 0);
                    $threadId  = (int)($post['thread_id'] ?? 0);
                    $isFirst   = (int)($post['is_first'] ?? 0) === 1;
                    $isPending = (int)($post['status'] ?? 1) !== 1;
                    $excerpt   = plain_text((string)($post['content'] ?? ''), 80);
                    $hasFiles  = is_array($post['attachments'] ?? null) && $post['attachments'] !== [];
                    ?>
                    <tr>
                        <td class="bulk-check">
                            <?php /* 首帖不能单独删，批量里也会被跳过 —— 复选框禁用，避免勾了却删不掉 */ ?>
                            <input type="checkbox" data-bulk-item value="<?= (int)($post['id'] ?? 0) ?>"
                                   <?= $isFirst ? 'disabled title="首帖请到「帖子」页签删除整个帖子"' : '' ?>
                                   aria-label="选择评论">
                        </td>
                        <td>
                            <?php /* 摘要最多两行（超出省略）；标记类徽章排在右侧不参与换行 */ ?>
                            <div class="cell-row cell-row--top">
                                <span class="cell-title"><?= e($excerpt !== '' ? $excerpt : '（无正文）') ?></span>
                                <?php /* 窄屏会整列隐藏「状态」，所以待审核在主值旁再挂一个徽章（与帖子列表一致） */ ?>
                                <?php if ($isPending): ?>
                                    <span class="ow-badge" data-ow-variant="warning">待审核</span>
                                <?php endif; ?>
                                <?php if ((int)($post['parent_id'] ?? 0) > 0): ?>
                                    <span class="ow-badge ow-outline">楼中楼</span>
                                <?php endif; ?>
                                <?php if ($hasFiles): ?>
                                    <span class="ow-badge ow-outline"><?= count($post['attachments']) ?> 个附件</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <a href="<?= e(url('/u/' . (int)($post['user_id'] ?? 0))) ?>"
                               target="_blank" rel="noopener"
                               style="color:<?= e((string)($post['author_group_color'] ?? '#999999')) ?>">
                                <?= e((string)($post['author']['username'] ?? '用户已删除')) ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($threadId > 0): ?>
                                <a class="cell-title"
                                   href="<?= e(url('/t/' . $threadId, ['p' => $postId])) ?>"
                                   target="_blank" rel="noopener"
                                   title="<?= e((string)($post['thread_title'] ?? '')) ?>">
                                    <?= e((string)($post['thread_title'] ?? '帖子已删除')) ?>
                                </a>
                            <?php else: ?>
                                <span class="ow-text-light">帖子已删除</span>
                            <?php endif; ?>
                        </td>
                        <td class="ow-text-light mono">
                            <?= $isFirst ? '首帖' : '#' . (int)($post['floor'] ?? 0) ?>
                        </td>
                        <td>
                            <?php if ($isPending): ?>
                                <span class="ow-badge ow-outline"><span class="status-dot status-dot--off"></span>待审核</span>
                            <?php else: ?>
                                <span class="ow-badge ow-outline"><span class="status-dot"></span>已通过</span>
                            <?php endif; ?>
                        </td>
                        <td class="ow-text-light"><?= e(human_time((int)($post['created_at'] ?? 0))) ?></td>
                        <td>
                            <div class="admin-table-actions">
                                <?php if ($canApprove && $isPending): ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/posts/' . $postId . '/approve')) ?>"
                                          data-confirm="确认通过这条评论的审核吗？">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="ow-button ow-small">
                                            <?= $view('partials/icon', ['name' => 'check', 'size' => 14]) ?>
                                            <span>通过</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($threadId > 0): ?>
                                    <a class="ow-button ow-small ow-ghost"
                                       href="<?= e(url('/t/' . $threadId, ['p' => $postId])) ?>"
                                       target="_blank" rel="noopener">
                                        <?= $view('partials/icon', ['name' => 'eye', 'size' => 14]) ?>
                                        <span>查看</span>
                                    </a>
                                <?php endif; ?>

                                <?php if ($isFirst): ?>
                                    <button type="button" class="ow-button ow-small ow-ghost" disabled
                                            title="首帖需连同帖子一起删除">
                                        <?= $view('partials/icon', ['name' => 'lock', 'size' => 14]) ?>
                                        <span>删除</span>
                                    </button>
                                <?php elseif (!(bool)($post['can_delete'] ?? true)): ?>
                                    <button type="button" class="ow-button ow-small ow-ghost" disabled
                                            title="管理员发布的内容只有超级管理员可以删除">
                                        <?= $view('partials/icon', ['name' => 'lock', 'size' => 14]) ?>
                                        <span>删除</span>
                                    </button>
                                <?php else: ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/posts/' . $postId . '/delete')) ?>"
                                          data-confirm="确定要删除这条评论吗？">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="ow-button ow-small ow-ghost" data-ow-variant="danger">
                                            <?= $view('partials/icon', ['name' => 'trash', 'size' => 14]) ?>
                                            <span>删除</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="panel__foot ow-text-light" style="font-size:12.5px">
        所有删除均为软删除，评论数、帖子数等统计会同步回滚。
    </div>

    <?php if (($pagination ?? '') !== ''): ?>
        <div class="panel__foot"><?= (string)$pagination ?></div>
    <?php endif; ?>
</section>

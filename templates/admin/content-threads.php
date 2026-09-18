<?php
/**
 * 后台：主题管理
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
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'file', 'size' => 16]) ?>主题管理</h3>
        <span class="spacer"></span>
        <?php if ($pending > 0): ?>
            <span class="badge" data-variant="warning"><?= $pending ?> 条待审核</span>
        <?php endif; ?>
        <span class="text-light" style="font-size:13px">
            共 <?= number_format((int)($result['total'] ?? 0)) ?> 个主题
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

        <?php /* 与前台同一套胶囊搜索框（.search-box），放大镜按钮即提交 */ ?>
        <div class="search-box search-box--admin">
            <input type="search" name="q" value="<?= e($keyword) ?>" maxlength="60"
                   placeholder="标题中包含…" aria-label="标题关键词">
            <button type="submit" aria-label="筛选">
                <?= $view('partials/icon', ['name' => 'search', 'size' => 16]) ?>
            </button>
        </div>

        <?php if ($keyword !== '' || $status !== -1): ?>
            <a class="button small ghost" href="<?= e(url('/admin/threads')) ?>">重置</a>
        <?php endif; ?>

        <?php if ($pending > 0 && $status !== 0): ?>
            <span class="spacer"></span>
            <a class="button small" href="<?= e(url('/admin/threads', ['status' => 0])) ?>">
                <?= $view('partials/icon', ['name' => 'filter', 'size' => 15]) ?>
                <span>只看待审核</span>
            </a>
        <?php endif; ?>
    </form>

    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'file', 'size' => 46]) ?>
            <p><?= $keyword !== '' || $status !== -1 ? '没有符合条件的主题。' : '还没有任何主题。' ?></p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>标题</th>
                    <th style="width:130px">作者</th>
                    <th style="width:120px">版块</th>
                    <th style="width:65px">回复</th>
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
                        <td class="admin-thread-title">
                            <a href="<?= e(url('/t/' . $threadId)) ?>" target="_blank" rel="noopener"
                               title="<?= e((string)($thread['title'] ?? '')) ?>">
                                <?= e((string)($thread['title'] ?? '')) ?>
                            </a>
                            <?php if ($isPending): ?>
                                <span class="badge" data-variant="warning" style="margin-left:6px">待审核</span>
                            <?php endif; ?>
                            <?php if ((int)($thread['is_pinned'] ?? 0) === 1): ?>
                                <span class="badge outline" style="margin-left:4px">置顶</span>
                            <?php endif; ?>
                            <?php if ((int)($thread['is_essence'] ?? 0) === 1): ?>
                                <span class="badge outline" style="margin-left:4px">精华</span>
                            <?php endif; ?>
                            <?php if ((int)($thread['is_locked'] ?? 0) === 1): ?>
                                <span class="badge outline" style="margin-left:4px">已锁定</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= e(url('/u/' . (int)($thread['user_id'] ?? 0))) ?>"
                               target="_blank" rel="noopener"
                               style="color:<?= e((string)($thread['author_group_color'] ?? '#999999')) ?>">
                                <?= e((string)($thread['author']['username'] ?? '已注销用户')) ?>
                            </a>
                        </td>
                        <td class="text-light">
                            <a href="<?= e(url('/f/' . $forumId)) ?>" target="_blank" rel="noopener">
                                <?= e($forumName) ?>
                            </a>
                        </td>
                        <td><?= number_format((int)($thread['reply_count'] ?? 0)) ?></td>
                        <td><?= number_format((int)($thread['views'] ?? 0)) ?></td>
                        <td>
                            <?php if ($isPending): ?>
                                <span class="badge outline"><span class="status-dot status-dot--off"></span>待审核</span>
                            <?php else: ?>
                                <span class="badge outline"><span class="status-dot"></span>已通过</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-light"><?= e(human_time((int)($thread['created_at'] ?? 0))) ?></td>
                        <td>
                            <div class="admin-table-actions">
                                <?php if ($canApprove && $isPending): ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/threads/' . $threadId . '/approve')) ?>"
                                          data-confirm="确认通过《<?= e((string)($thread['title'] ?? '')) ?>》的审核吗？">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="button small">
                                            <?= $view('partials/icon', ['name' => 'check', 'size' => 14]) ?>
                                            <span>通过</span>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <a class="button small ghost" href="<?= e(url('/t/' . $threadId)) ?>"
                                   target="_blank" rel="noopener">
                                    <?= $view('partials/icon', ['name' => 'eye', 'size' => 14]) ?>
                                    <span>查看</span>
                                </a>

                                <?php if ((bool)($thread['can_delete'] ?? true)): ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/threads/' . $threadId . '/delete')) ?>"
                                          data-confirm="确定要删除主题《<?= e((string)($thread['title'] ?? '')) ?>》吗？其下所有回复会一并删除。">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="button small ghost" data-variant="danger">
                                            <?= $view('partials/icon', ['name' => 'trash', 'size' => 14]) ?>
                                            <span>删除</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="button small ghost" disabled
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

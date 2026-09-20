<?php
/**
 * 后台：版块管理（列表）
 *
 * 变量：$rows（每项 { forum: 版块原始行, depth: 0|1 }）、$total
 *
 * 说明：后台会展示隐藏版块（status != 1），因此这里用状态徽标区分，
 * 而不是像前台那样直接过滤掉。
 */

declare(strict_types=1);

$rows  = is_array($rows ?? null) ? $rows : [];
$total = (int)($total ?? count($rows));
?>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'grid', 'size' => 16]) ?>版块列表</h3>
        <span class="spacer"></span>
        <span class="text-light" style="font-size:13px">共 <?= $total ?> 个版块</span>
        <a class="button small" href="<?= e(url('/admin/forums/create')) ?>">
            <?= $view('partials/icon', ['name' => 'plus', 'size' => 15]) ?>
            <span>新增版块</span>
        </a>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'grid', 'size' => 46]) ?>
            <p>还没有任何版块。</p>
            <p class="text-light" style="font-size:13px">先创建几个一级版块，再往下挂子版块。</p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>版块</th>
                    <th style="width:120px">标识</th>
                    <th style="width:130px">权限限制</th>
                    <th style="width:80px">帖子</th>
                    <th style="width:80px">评论</th>
                    <th style="width:70px">排序</th>
                    <th style="width:90px">状态</th>
                    <th style="width:150px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $forum    = is_array($row['forum'] ?? null) ? $row['forum'] : [];
                    $depth    = (int)($row['depth'] ?? 0);
                    $forumId  = (int)($forum['id'] ?? 0);
                    $isHidden = (int)($forum['status'] ?? 1) !== 1;

                    // 权限字段非空表示「仅白名单内的用户组可用」
                    $limits = [];
                    if (group_ids_from_field((string)($forum['group_view'] ?? '')) !== []) {
                        $limits[] = '浏览受限';
                    }
                    if (group_ids_from_field((string)($forum['group_thread'] ?? '')) !== []) {
                        $limits[] = '发帖受限';
                    }
                    if (group_ids_from_field((string)($forum['group_reply'] ?? '')) !== []) {
                        $limits[] = '评论受限';
                    }
                    ?>
                    <tr>
                        <td>
                            <div class="hstack" style="gap:8px;align-items:flex-start">
                                <?php if ($depth > 0): ?>
                                    <span class="text-light mono" aria-hidden="true">└</span>
                                <?php endif; ?>
                                <span style="min-width:0">
                                    <a href="<?= e(url('/f/' . $forumId)) ?>" target="_blank" rel="noopener">
                                        <?= e((string)($forum['name'] ?? '')) ?>
                                    </a>
                                    <?php if ((int)($forum['allow_thread'] ?? 1) !== 1): ?>
                                        <span class="badge outline" style="margin-left:6px">禁止发帖</span>
                                    <?php endif; ?>
                                    <?php if ((int)($forum['allow_reply'] ?? 1) !== 1): ?>
                                        <span class="badge outline" style="margin-left:4px">禁止评论</span>
                                    <?php endif; ?>
                                    <?php if ((string)($forum['description'] ?? '') !== ''): ?>
                                        <span class="text-light" style="display:block;font-size:12.5px">
                                            <?= e((string)$forum['description']) ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </td>
                        <td class="mono text-light"><?= e((string)($forum['slug'] ?? '') !== '' ? (string)$forum['slug'] : '—') ?></td>
                        <td>
                            <?php if ($limits === []): ?>
                                <span class="text-light" style="font-size:12.5px">公开</span>
                            <?php else: ?>
                                <?php foreach ($limits as $limit): ?>
                                    <span class="badge outline" style="margin:1px 0"><?= e($limit) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= format_number((int)($forum['thread_count'] ?? 0)) ?></td>
                        <td><?= format_number((int)($forum['post_count'] ?? 0)) ?></td>
                        <td class="text-light"><?= (int)($forum['sort_order'] ?? 0) ?></td>
                        <td>
                            <?php if ($isHidden): ?>
                                <span class="badge outline"><span class="status-dot status-dot--off"></span>隐藏</span>
                            <?php else: ?>
                                <span class="badge outline"><span class="status-dot"></span>正常</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-table-actions">
                                <a class="button small ghost" href="<?= e(url('/admin/forums/' . $forumId . '/edit')) ?>">
                                    <?= $view('partials/icon', ['name' => 'edit', 'size' => 14]) ?>
                                    <span>编辑</span>
                                </a>

                                <form class="inline-form" method="post"
                                      action="<?= e(url('/admin/forums/' . $forumId . '/delete')) ?>"
                                      data-confirm="确定要删除版块「<?= e((string)($forum['name'] ?? '')) ?>」吗？该操作不可撤销。">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="button small ghost" data-variant="danger">
                                        <?= $view('partials/icon', ['name' => 'trash', 'size' => 14]) ?>
                                        <span>删除</span>
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
        版块下仍有帖子或子版块时无法删除；删除操作为软删除，数据可在数据库层面恢复。
    </div>
</section>

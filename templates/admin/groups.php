<?php
/**
 * 后台：用户组管理（列表）
 *
 * 变量：$rows（id/name/slug/color/description/is_system/sort_order/members/permissions）、$totalRights
 *
 * permissions 字段是「已开启的权限条数」而不是明细，明细在编辑页查看。
 */

declare(strict_types=1);

$rows        = is_array($rows ?? null) ? $rows : [];
$totalRights = (int)($totalRights ?? count(\Core\Permission::CATALOG));
?>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'shield', 'size' => 16]) ?>用户组列表</h3>
        <span class="spacer"></span>
        <span class="text-light" style="font-size:13px">共 <?= count($rows) ?> 个用户组</span>
        <a class="button small" href="<?= e(url('/admin/groups/create')) ?>">
            <?= $view('partials/icon', ['name' => 'plus', 'size' => 15]) ?>
            <span>新增用户组</span>
        </a>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'shield', 'size' => 46]) ?>
            <p>还没有任何用户组。</p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>用户组</th>
                    <th style="width:120px">标识</th>
                    <th style="width:90px">成员</th>
                    <th style="width:220px">权限开启情况</th>
                    <th style="width:70px">排序</th>
                    <th style="width:90px">类型</th>
                    <th style="width:150px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $groupId     = (int)($row['id'] ?? 0);
                    $groupName   = (string)($row['name'] ?? '');
                    $groupColor  = (string)($row['color'] ?? '') !== '' ? (string)$row['color'] : '#999999';
                    $isSystem    = (bool)($row['is_system'] ?? false);
                    $enabledRights = (int)($row['permissions'] ?? 0);
                    $percent     = $totalRights > 0 ? (int)round($enabledRights / $totalRights * 100) : 0;
                    ?>
                    <tr>
                        <td>
                            <strong style="color:<?= e($groupColor) ?>"><?= e($groupName) ?></strong>
                            <?php if ($isSystem): ?>
                                <span class="badge outline" style="margin-left:6px">内置</span>
                            <?php endif; ?>
                            <?php if ($groupId === \Core\Permission::SUPER_GROUP): ?>
                                <span class="badge" style="margin-left:4px">最高权限</span>
                            <?php endif; ?>
                            <?php if ((string)($row['description'] ?? '') !== ''): ?>
                                <span class="text-light" style="display:block;font-size:12.5px">
                                    <?= e((string)$row['description']) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="mono text-light"><?= e((string)($row['slug'] ?? '')) ?></td>
                        <td>
                            <a href="<?= e(url('/admin/users', ['group' => $groupId])) ?>">
                                <?= number_format((int)($row['members'] ?? 0)) ?>
                            </a>
                        </td>
                        <td>
                            <div class="hstack" style="gap:8px">
                                <span class="bar" style="flex:1 1 auto;min-width:70px"><span style="width:<?= $percent ?>%"></span></span>
                                <span class="text-light mono" style="font-size:12px">
                                    <?= $enabledRights ?>/<?= $totalRights ?>
                                </span>
                            </div>
                        </td>
                        <td class="text-light"><?= (int)($row['sort_order'] ?? 0) ?></td>
                        <td>
                            <?php if ($isSystem): ?>
                                <span class="badge outline">系统</span>
                            <?php else: ?>
                                <span class="badge outline">自定义</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-table-actions">
                                <a class="button small ghost" href="<?= e(url('/admin/groups/' . $groupId . '/edit')) ?>">
                                    <?= $view('partials/icon', ['name' => 'edit', 'size' => 14]) ?>
                                    <span>编辑</span>
                                </a>

                                <?php if ($isSystem): ?>
                                    <button type="button" class="button small ghost" disabled
                                            title="系统内置用户组不可删除">
                                        <?= $view('partials/icon', ['name' => 'lock', 'size' => 14]) ?>
                                        <span>删除</span>
                                    </button>
                                <?php else: ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/groups/' . $groupId . '/delete')) ?>"
                                          data-confirm="确定要删除用户组「<?= e($groupName) ?>」吗？">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="button small ghost" data-variant="danger">
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

    <div class="panel__foot text-light" style="font-size:12.5px">
        系统内置用户组不可删除；仍有成员的自定义用户组需先转移成员后才能删除。
        权限清单集中在代码中维护，新增权限后会自动出现在编辑页。
    </div>
</section>

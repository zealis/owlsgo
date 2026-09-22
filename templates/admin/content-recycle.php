<?php
/**
 * 后台：回收站（已软删除的帖子与回帖）
 *
 * 变量：$result（items 已 decorate）、$keyword、$scope、$scopes、$counts、$pagination
 *
 * 列表把帖子与回帖**混排**（按删除时间倒序），所以每条复选框的值必须带类型前缀
 * `thread:123` / `post:456` —— 两张表主键各自从 1 开始，光看 ID 分不清是什么。
 */

declare(strict_types=1);

$result  = is_array($result ?? null) ? $result : ['items' => [], 'total' => 0];
$items   = is_array($result['items'] ?? null) ? $result['items'] : [];
$keyword = (string)($keyword ?? '');
$scope   = (string)($scope ?? 'all');
$scopes  = is_array($scopes ?? null) ? $scopes : [];
$counts  = is_array($counts ?? null) ? $counts : ['thread' => 0, 'post' => 0, 'total' => 0];
?>

<section class="panel">
    <?php /* 内容管理的三个页签（帖子 / 回帖 / 回收站），与前台共用 .tabbar */ ?>
    <?= $view('partials/admin-content-tabs', ['tabsActive' => 'recycle']) ?>

    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'trash', 'size' => 16]) ?>回收站</h3>
        <span class="spacer"></span>
        <span class="text-light" style="font-size:13px">
            帖子 <?= number_format((int)$counts['thread']) ?> 个 ·
            回帖 <?= number_format((int)$counts['post']) ?> 条 ·
            用户 <?= number_format((int)$counts['user']) ?> 个 ·
            共 <?= number_format((int)$counts['total']) ?> 项
        </span>
    </div>

    <form class="admin-filter" method="get" action="<?= e(url('/admin/recycle')) ?>">
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

        <div class="search-box search-box--admin">
            <input type="search" name="q" value="<?= e($keyword) ?>" maxlength="60"
                   placeholder="输入关键词…" aria-label="搜索关键词">
            <button type="submit" aria-label="筛选">
                <?= $view('partials/icon', ['name' => 'search', 'size' => 16]) ?>
            </button>
        </div>

        <?php if ($keyword !== '' || $scope !== 'all'): ?>
            <a class="button small ghost" href="<?= e(url('/admin/recycle')) ?>">重置</a>
        <?php endif; ?>


        <span class="spacer"></span>
        <span class="text-light" style="font-size:12.5px">
            恢复会把当初扣掉的计数一起加回；彻底删除不可撤销
        </span>
    </form>

    <?php
    echo $view('partials/admin-bulk-bar', [
        'bulkEndpoint' => url('/admin/recycle/bulk'),
        'bulkNoun'     => '项内容',
        'bulkOptions'  => [
            [
                'value'   => 'restore',
                'label'   => '恢复',
                'confirm' => '确认恢复选中的 {n} 项吗？帖子 / 回帖恢复时相关计数会同步加回；用户恢复只找回账号本身。',
            ],
            [
                'value'   => 'purge',
                'label'   => '彻底删除',
                'confirm' => '确认彻底删除选中的 {n} 项吗？此操作不可恢复：帖子 / 回帖会连同附件文件一起删除，账号记录将永久消失。',
                'danger'  => true,
            ],
        ],
    ]);
    ?>

    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'trash', 'size' => 46]) ?>
            <p><?= $keyword !== '' || $scope !== 'all' ? '没有符合条件的已删除内容。' : '回收站是空的。' ?></p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <?php /* admin-list：固定列宽 + 长文本自己截断（见 theme.css「后台列表」一节） */ ?>
            <table class="admin-list admin-list--recycle">
                <thead>
                <tr>
                    <th class="bulk-check"></th>
                    <th style="width:70px">类型</th>
                    <th style="width:260px">内容</th>
                    <th style="width:130px">作者</th>
                    <th style="width:120px">版块</th>
                    <th style="width:120px">删除时间</th>
                    <?php /*
                      操作列要放得下「恢复 + 彻底删除」两个按钮并排（实测需 174px + 单元格留白）：
                      宽度不够时 .admin-table-actions 的 flex-wrap 会让它们上下堆叠，看着像没对齐。
                      与帖子 / 回帖管理页的 200px 保持同一量级。
                    */ ?>
                    <th style="width:210px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $type     = (string)($item['type'] ?? '');
                    $itemId   = (int)($item['id'] ?? 0);
                    $isThread = $type === 'thread';
                    $isUser   = $type === 'user';
                    $label    = (string)($item['raw_title'] ?? '');
                    $excerpt  = (string)($item['excerpt'] ?? '');
                    ?>
                    <tr>
                        <td class="bulk-check">
                            <input type="checkbox" data-bulk-item
                                   value="<?= e($type . ':' . $itemId) ?>"
                                   aria-label="选择该<?= e($isUser ? '用户' : ($isThread ? '帖子' : '回帖')) ?>">
                        </td>
                        <td>
                            <span class="badge outline"><?= e((string)($item['type_label'] ?? '')) ?></span>
                        </td>
                        <?php
                        /*
                         * 「内容」列统一成「一行主值 + 一行次要信息」，两行各自截断：
                         * 标题/摘要长的能有多长有多长，直接铺开会把这列撑爆，
                         * 其余列被挤成一条缝，移动端更是横向滚不到头。
                         */
                        if ($isUser) {
                            $cellMain = $label !== '' ? $label : '（无用户名）';
                            $cellSub  = (string)($item['email'] ?? '');
                        } elseif ($isThread) {
                            $cellMain = $excerpt !== '' ? $excerpt : '（无标题）';
                            $cellSub  = '';
                        } else {
                            $threadTitle = (string)($item['thread_title'] ?? '');
                            $cellMain    = $threadTitle !== '' ? $threadTitle : '（所属帖子已删除）';
                            $cellSub     = $excerpt !== '' ? $excerpt : '（无正文）';
                        }
                        ?>
                        <td>
                            <div class="cell-row">
                                <span class="cell-title" title="<?= e($label) ?>"><?= e($cellMain) ?></span>
                                <span class="text-light mono" style="font-size:12px">#<?= $itemId ?></span>
                            </div>
                            <?php if ($cellSub !== ''): ?>
                                <div class="cell-title text-light" style="font-size:12.5px;margin-top:3px"><?= e($cellSub) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-light"><?= e((string)($item['author_name'] ?? '')) ?></td>
                        <td class="text-light"><?= e((string)($item['forum_name'] ?? '')) ?></td>
                        <td class="text-light"><?= e(human_time((int)($item['deleted_at'] ?? 0))) ?></td>
                        <td>
                            <div class="admin-table-actions">
                                <?php /*
                                  用户行与内容行的确认文案不同：
                                    · 恢复用户 = 只把账号捞回来，**不含**他的内容（内容是单独删除的条目）
                                    · 彻底删除用户 = 账号行永久消失，且**不会**连带删内容
                                */ ?>
                                <?php if ($isUser): ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/recycle/bulk')) ?>"
                                          data-confirm="确认恢复该账号吗？只恢复账号本身；他的帖子与评论如在回收站中，需要单独勾选恢复。">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="items[]" value="<?= e($type . ':' . $itemId) ?>">
                                        <?php /* 与右侧「彻底删除」同为 ghost：同排按钮质感一致，只靠文字颜色区分 */ ?>
                                        <button type="submit" class="button small ghost">
                                            <?= $view('partials/icon', ['name' => 'refresh', 'size' => 14]) ?>
                                            <span>恢复</span>
                                        </button>
                                    </form>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/recycle/bulk')) ?>"
                                          data-confirm="确认彻底删除该账号吗？账号记录将永久消失，无法恢复；他还留存在站内的内容不会被删除，会继续显示为「用户已删除」。">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="purge">
                                        <input type="hidden" name="items[]" value="<?= e($type . ':' . $itemId) ?>">
                                        <button type="submit" class="button small ghost" data-variant="danger">
                                            <?= $view('partials/icon', ['name' => 'trash', 'size' => 14]) ?>
                                            <span>彻底删除</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/recycle/bulk')) ?>"
                                          data-confirm="确认恢复这一项内容吗？相关计数会同步加回。">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="items[]" value="<?= e($type . ':' . $itemId) ?>">
                                        <?php /* 与右侧「彻底删除」同为 ghost：同排按钮质感一致，只靠文字颜色区分 */ ?>
                                        <button type="submit" class="button small ghost">
                                            <?= $view('partials/icon', ['name' => 'refresh', 'size' => 14]) ?>
                                            <span>恢复</span>
                                        </button>
                                    </form>
                                    <form class="inline-form" method="post"
                                          action="<?= e(url('/admin/recycle/bulk')) ?>"
                                          data-confirm="确认彻底删除这一项吗？不可恢复，附件文件会一并删除。">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="purge">
                                        <input type="hidden" name="items[]" value="<?= e($type . ':' . $itemId) ?>">
                                        <button type="submit" class="button small ghost" data-variant="danger">
                                            <?= $view('partials/icon', ['name' => 'trash', 'size' => 14]) ?>
                                            <span>彻底删除</span>
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

    <?php if (($pagination ?? '') !== ''): ?>
        <div class="panel__foot"><?= (string)$pagination ?></div>
    <?php endif; ?>
</section>

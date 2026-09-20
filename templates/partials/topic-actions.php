<?php
/**
 * 首楼帖子级操作区（收藏 / 版主图标 / 「⋯」菜单 / 插件扩展）
 *
 * 位置：首楼作者行的最右侧，即 .post-ops 内、紧跟「楼层点赞」之后。
 * 原本它是标题下方一条独立操作条（.post-actions），2026-09-21 按用户要求改成
 * 「只显示图标 + 挪到作者行右侧」：「⋯」菜单里放锁定 / 编辑 / 删除（这三项带文字）。
 *
 * 传入：$thread、$canEdit、$canModerate、$liked、$favorited
 *
 * ⚠️ 收藏是 **data-ajax 表单**：类名与 data-* 属性都不能改 ——
 *    app.js 的 initAjaxForms() 依赖 `form[data-ajax]` + `data-state-field` + `data-active`
 *    做无刷新切换；激活态字色由 theme.css 第 20 节的 `form[data-active="1"] .button` 决定。
 *
 * ⚠️ 「⋯」菜单复用 OATUI 的 <ot-dropdown> + <menu popover>（与顶栏齿轮同一套实现，
 *    oat/js/dropdown.js 负责定位 / 键盘导航 / aria-expanded，app.js 的
 *    initDropdownAutoClose() 负责点击后收起）。popover 的 id 必须全站唯一：
 *    一页只会渲染一个首楼，所以这里固定用 post-more-menu。
 *
 * ⚠️ 插件扩展点 hook('thread_view_actions') 仍在本文件末尾输出，
 *    插件的按钮会排在「⋯」之后。
 */

declare(strict_types=1);

$thread      = is_array($thread ?? null) ? $thread : [];
$canEdit     = (bool)($canEdit ?? false);
$canModerate = (bool)($canModerate ?? false);
$liked       = (bool)($liked ?? false);
$favorited   = (bool)($favorited ?? false);

$threadId     = (int)($thread['id'] ?? 0);
$isLocked     = (int)($thread['is_locked'] ?? 0) === 1;
$isPinned     = (int)($thread['is_pinned'] ?? 0) === 1;
$isEssence    = (int)($thread['is_essence'] ?? 0) === 1;
$isRecommended = (int)($thread['is_recommended'] ?? 0) === 1;

/*
 * 版主图标（置顶 / 精华 / 推荐）：只显示图标，文案退到 title 与 aria-label。
 * `on` 决定是否给表单挂 data-active="1" —— 复用的正是点赞/收藏那套激活态配色。
 */
$modIcons = [
    ['action' => $isPinned ? 'unpin' : 'pin',
     'on'     => $isPinned,
     'icon'   => 'pin',
     'label'  => $isPinned ? '取消置顶' : '置顶'],
    ['action' => $isEssence ? 'unessence' : 'essence',
     'on'     => $isEssence,
     'icon'   => 'star',
     'label'  => $isEssence ? '取消精华' : '加精'],
    ['action' => $isRecommended ? 'unrecommend' : 'recommend',
     'on'     => $isRecommended,
     'icon'   => 'flag',
     'label'  => $isRecommended ? '取消推荐' : '推荐'],
];

/* 「⋯」菜单项：锁定（版主）/ 编辑帖子 / 删除帖子 —— 都带文字，按此顺序渲染 */
$canDeleteThread = $canEdit || $canModerate;
$hasMenu         = $canModerate || $canEdit || $canDeleteThread;
?>

<?php /* 收藏：帖子级 data-ajax 切换，只显示图标（数量退到 title） */ ?>
<form method="post" action="<?= e(url('/t/' . $threadId . '/favorite')) ?>" data-ajax
      data-state-field="favorited" data-active="<?= $favorited ? '1' : '0' ?>" class="inline-form">
    <?= csrf_field() ?>
    <button type="submit" class="button ghost small"
            title="收藏 <?= (int)($thread['favorite_count'] ?? 0) ?>" aria-label="收藏">
        <?= $view('partials/icon', ['name' => 'bookmark', 'size' => 15]) ?>
    </button>
</form>

<?php if ($canModerate): ?>
    <?php foreach ($modIcons as $mod): ?>
        <form method="post" action="<?= e(url('/t/' . $threadId . '/moderate')) ?>" class="inline-form"
              data-active="<?= $mod['on'] ? '1' : '0' ?>">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="<?= e($mod['action']) ?>"
                    class="button ghost small" title="<?= e($mod['label']) ?>" aria-label="<?= e($mod['label']) ?>">
                <?= $view('partials/icon', ['name' => $mod['icon'], 'size' => 15]) ?>
            </button>
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($hasMenu): ?>
    <ot-dropdown class="post-more">
        <button type="button" class="button ghost small post-more__btn" popovertarget="post-more-menu"
                aria-haspopup="menu" aria-expanded="false" aria-label="更多操作" title="更多操作">
            <?= $view('partials/icon', ['name' => 'more', 'size' => 15]) ?>
        </button>
        <menu popover id="post-more-menu" class="post-more__menu" aria-label="帖子操作">
            <?php if ($canModerate): ?>
                <li>
                    <form method="post" action="<?= e(url('/t/' . $threadId . '/moderate')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" role="menuitem" name="action" value="<?= $isLocked ? 'unlock' : 'lock' ?>">
                            <span class="post-more__icon" aria-hidden="true">
                                <?= $view('partials/icon', ['name' => $isLocked ? 'unlock' : 'lock', 'size' => 16]) ?>
                            </span>
                            <span><?= $isLocked ? '解锁帖子' : '锁定帖子' ?></span>
                        </button>
                    </form>
                </li>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <li>
                    <a role="menuitem" href="<?= e(url('/t/' . $threadId . '/edit')) ?>">
                        <span class="post-more__icon" aria-hidden="true">
                            <?= $view('partials/icon', ['name' => 'edit', 'size' => 16]) ?>
                        </span>
                        <span>编辑帖子</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($canDeleteThread): ?>
                <li>
                    <form method="post" action="<?= e(url('/t/' . $threadId . '/delete')) ?>"
                          data-confirm="确定要删除该帖子吗？帖子下的所有评论也会一并删除，且无法自行恢复。">
                        <?= csrf_field() ?>
                        <button type="submit" role="menuitem">
                            <span class="post-more__icon" aria-hidden="true">
                                <?= $view('partials/icon', ['name' => 'trash', 'size' => 16]) ?>
                            </span>
                            <span>删除帖子</span>
                        </button>
                    </form>
                </li>
            <?php endif; ?>
        </menu>
    </ot-dropdown>
<?php endif; ?>

<?php
/* 插件可往操作区追加按钮，返回 HTML 字符串（由插件自行保证转义）。
   上下文保持与原实现一致：thread / forum / user（见 core/Hook.php 的钩子声明）。 */
echo (string)hook('thread_view_actions', '', [
    'thread' => $thread,
    'forum'  => is_array($forum ?? null) ? $forum : [],
    'user'   => auth_user(),
]);
?>

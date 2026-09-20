<?php
/**
 * 个人主页头部数据卡 —— 用户名 / 用户 UID / 注册时间 / 帖子 / 评论 / 获赞
 *
 * 只在个人主页（/u/{id}）渲染，外观与后台概览顶部的 .admin-cards 是同一套
 * （直接复用 .admin-cards / .admin-card 类，所以两处的圆角、图标底色、字号完全一致）。
 *
 * 传入：$profile（用户行，需含 id / username / created_at / thread_count / post_count；
 *       获赞数由控制器算好放进 like_received，模板不查库）
 *
 * 插件可增删改这些卡片：
 *     Plugin::filter('user_profile_stats', [Service::class, 'profileStats']);
 * 结构：[['key' => 唯一键, 'label' => 小字标签, 'value' => 主数值,
 *        'icon' => 图标名, 'url' => 可选站内路径, 'title' => 可选悬浮提示], ...]
 *
 * ⚠️ 与 thread_view_actions 不同，本钩子的 label / value 由模板统一转义后输出，
 *    插件**不要**自行拼接 HTML，也不要传已转义的字串（会二次转义）。
 * ⚠️ 「个人主页可见性」不在此判断：这些字段本身就与名片同级别（公开信息），
 *    隐私开关管的是「发表的帖子 / 发表的评论」两个标签页，不涉及卡片本身。
 */

declare(strict_types=1);

$statProfile = is_array($profile ?? null) ? $profile : [];
$statId      = (int)($statProfile['id'] ?? 0);
$statJoined  = (int)($statProfile['created_at'] ?? 0);

$items = [
    [
        'key'   => 'username',
        'label' => '用户名',
        'value' => (string)($statProfile['username'] ?? '用户已删除'),
        'icon'  => 'user',
    ],
    [
        'key'   => 'uid',
        'label' => '用户 UID',
        'value' => (string)$statId,
        'icon'  => 'tag',
    ],
    [
        'key'   => 'joined',
        'label' => '注册时间',
        'value' => $statJoined > 0 ? date('Y-m-d', $statJoined) : '—',
        'icon'  => 'calendar',
        /* 卡片只显示到日，精确到秒的时间放悬浮提示里，避免主数值过长 */
        'title' => $statJoined > 0 ? date('Y-m-d H:i:s', $statJoined) : '',
    ],
    [
        'key'   => 'threads',
        'label' => '帖子',
        'value' => (string)(int)($statProfile['thread_count'] ?? 0),
        'icon'  => 'file',
        'title' => '发表的帖子数（不含评论）',
    ],
    [
        'key'   => 'comments',
        'label' => '评论',
        'value' => (string)user_comment_count($statProfile),
        'icon'  => 'message',
        /* ⚠️ 必须走 user_comment_count()：users.post_count 含本人首帖，
           直接显示会比他实际评论数多出「帖子数」（楼层那边也是这么算的）。 */
        'title' => '发表的评论数（不含自己帖子的正文）',
    ],
    [
        'key'   => 'likes',
        'label' => '获赞',
        'value' => (string)(int)($statProfile['like_received'] ?? 0),
        'icon'  => 'star',
        'title' => '他的帖子与评论收到的赞总数',
    ],
];

/*
 * 插件可增删改数据卡（Plugin::filter('user_profile_stats', ...)）。
 * 返回值必须仍是数组；下面逐个兜底，插件塞进残缺项不会把整页打挂。
 */
$items = (array)hook('user_profile_stats', $items, ['profile' => $statProfile]);

/*
 * ⚠️ 这里刻意用 if 包住而不是提前 `return;`：模板由 View::evaluate() 在 ob_start() 之后
 *    用 require 引入，模板里的 return 会直接跳出 evaluate()，ob_get_clean() 不执行，
 *    输出缓冲被泄漏到外层（表现为后续页面内容错位）。模板里一律不要提前 return。
 */
if ($items !== []):
?>
<div class="admin-cards profile-stats">
    <?php foreach ($items as $item): ?>
        <?php
        if (!is_array($item)) {
            continue;
        }

        $statKey   = (string)($item['key'] ?? '');
        $statLabel = (string)($item['label'] ?? '');
        $statValue = (string)($item['value'] ?? '');
        $statIcon  = (string)($item['icon'] ?? 'dot');
        $statUrl   = trim((string)($item['url'] ?? ''));
        $statTip   = trim((string)($item['title'] ?? ''));

        /* 没有标签或没有数值的卡片不渲染（空卡片比缺一张更难看） */
        if ($statLabel === '' || $statValue === '') {
            continue;
        }
        ?>
        <div class="admin-card"<?= $statKey !== '' ? ' data-stat="' . e($statKey) . '"' : '' ?>>
            <span class="admin-card__icon">
                <?= $view('partials/icon', ['name' => $statIcon, 'size' => 20]) ?>
            </span>
            <div style="min-width:0">
                <div class="admin-card__value"<?= $statTip !== '' ? ' title="' . e($statTip) . '"' : '' ?>>
                    <?php if ($statUrl !== ''): ?>
                        <a href="<?= e(url($statUrl)) ?>"><?= e($statValue) ?></a>
                    <?php else: ?>
                        <?= e($statValue) ?>
                    <?php endif; ?>
                </div>
                <div class="admin-card__label"><?= e($statLabel) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

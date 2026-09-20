<?php
/**
 * 搜索：帖子 / 评论 / 用户（Preference 范围选择，默认帖子）
 *
 * 变量：$keyword、$type（thread|post|user）、$result（items 已按范围组装）、
 *       $notices（帖子范围内命中的公告）、$pagination
 */

declare(strict_types=1);

$keyword = (string)($keyword ?? '');
$type    = (string)($type ?? 'thread');
$result  = is_array($result ?? null) ? $result : ['items' => [], 'total' => 0];
$items   = is_array($result['items'] ?? null) ? $result['items'] : [];
$notices = is_array($notices ?? null) ? $notices : [];
$total   = (int)($result['total'] ?? 0);

$typeLabels = [
    'thread' => '全文',
    'post'   => '评论',
    'user'   => '用户',
];

/** 正文摘要：掐掉换行压成一行，截 140 字 */
$excerpt = static function (string $text): string {
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    $text = trim($text);

    return mb_strlen($text) > 140 ? mb_substr($text, 0, 140) . '…' : $text;
};
?>
<?php /* 与首页同款右栏：前台（除个人管理页面外）统一用 partials/sidebar */ ?>
<div class="page-grid">
    <div>
    <section class="panel mb-4">
        <div class="panel__head">
            <h2><?= $view('partials/icon', ['name' => 'search', 'size' => 17]) ?>搜索</h2>
        </div>

        <div class="panel__body">
            <?php /* 居中版式：大搜索框 + 右侧贴合的搜索按钮，范围选择在下方 */ ?>
            <form class="search-hero" method="get" action="<?= e(url('/search')) ?>">
                <?php /* 与顶栏搜索框同一套胶囊结构（.search-box） */ ?>
                <div class="search-box">
                    <input type="search" id="search-q" name="q" value="<?= e($keyword) ?>"
                           maxlength="60" placeholder="搜索关键词" autocomplete="off" required>
                    <button type="submit" aria-label="搜索">
                        <?= $view('partials/icon', ['name' => 'search', 'size' => 17]) ?>
                    </button>
                </div>

                <?php /* Preference：OATUI 官方约定 = fieldset.hstack + legend + label 包裹 radio */ ?>
                <fieldset class="hstack search-hero__scope">
                    <legend>搜索范围</legend>
                    <?php foreach ($typeLabels as $value => $label): ?>
                        <label><input type="radio" name="type" value="<?= e($value) ?>"<?= checked($value === $type) ?>><?= e($label) ?></label>
                    <?php endforeach; ?>
                </fieldset>
            </form>
        </div>
    </section>

    <?php if ($keyword === ''): ?>
        <div class="panel">
            <div class="empty">
                <?= $view('partials/icon', ['name' => 'search', 'size' => 46]) ?>
                <p>输入关键词以搜索站内<?= $typeLabels[$type] ?? '帖子' ?>。</p>
            </div>
        </div>
    <?php else: ?>
        <?php /* 帖子范围内命中的公告，单独成块展示 */ ?>
        <?php if ($type === 'thread' && $notices !== []): ?>
            <section class="panel mb-4">
                <div class="panel__head">
                    <h3><?= $view('partials/icon', ['name' => 'megaphone', 'size' => 15]) ?>相关公告</h3>
                </div>
                <?php foreach ($notices as $notice): ?>
                    <?php $noticeId = (int)($notice['id'] ?? 0); ?>
                    <div class="search-hit">
                        <div class="search-hit__head">
                            <a href="<?= e(url('/notifications')) ?>#notice-<?= $noticeId ?>">
                                <?= e((string)($notice['title'] ?? '')) ?>
                            </a>
                            <span class="search-hit__meta"><?= e((string)($notice['name'] ?? '站点公告')) ?></span>
                        </div>
                        <?php $body = trim((string)($notice['body'] ?? '')); ?>
                        <?php if ($body !== ''): ?>
                            <p class="search-hit__excerpt"><?= e($excerpt($body)) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel__head">
                <h3>“<?= e($keyword) ?>” 的搜索结果</h3>
                <span class="spacer"></span>
                <span class="text-light" style="font-size:13px"><?= e($typeLabels[$type] ?? '帖子') ?> · 共 <?= $total ?> 条</span>
            </div>

            <?php if ($items === []): ?>
                <div class="empty">
                    <?= $view('partials/icon', ['name' => 'info', 'size' => 46]) ?>
                    <p>没有找到与“<?= e($keyword) ?>”相关的<?= e($typeLabels[$type] ?? '帖子') ?>，换个关键词试试。</p>
                </div>
            <?php elseif ($type === 'user'): ?>
                <?php foreach ($items as $user): ?>
                    <a class="search-user" href="<?= e(url('/u/' . (int)($user['id'] ?? 0))) ?>">
                        <img src="<?= e(\Core\Avatar::url(is_array($user) ? $user : [], 44)) ?>" width="44" height="44" alt="">
                        <span>
                            <span class="search-user__name"><?= e((string)($user['username'] ?? '')) ?></span><br>
                            <span class="search-user__meta">
                                <?php /* 这里原来写「帖子 post_count」——post_count 含评论，会虚高；两个数分开显示 */ ?>
                                帖子 <?= format_number((int)($user['thread_count'] ?? 0)) ?>
                                · 评论 <?= format_number(user_comment_count($user)) ?>
                                · 加入于 <?= date('Y-m-d', (int)($user['created_at'] ?? 0)) ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php elseif ($type === 'post'): ?>
                <?php foreach ($items as $post): ?>
                    <div class="search-hit">
                        <div class="search-hit__head">
                            <a href="<?= e(url('/t/' . (int)($post['thread_id'] ?? 0))) ?>?p=<?= (int)($post['id'] ?? 0) ?>">
                                <?= e((string)($post['thread_title'] ?? '')) ?>
                            </a>
                            <span class="search-hit__meta">
                                #<?= (int)($post['floor'] ?? 0) ?> · <?= e((string)($post['author'] ?? '')) ?>
                                · <?= e(human_time((int)($post['created_at'] ?? 0))) ?>
                            </span>
                        </div>
                        <p class="search-hit__excerpt"><?= e($excerpt((string)($post['content'] ?? ''))) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($items as $thread): ?>
                    <?= $view('partials/thread-item', ['thread' => $thread, 'showForum' => true, 'favorited' => false]) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <?php if (($pagination ?? '') !== ''): ?>
            <div class="pager"><?= (string)$pagination ?></div>
        <?php endif; ?>
    <?php endif; ?>
    </div>

    <?= $view('partials/sidebar') ?>
</div>

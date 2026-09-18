<?php
/**
 * 用户中心个人名片 —— 个人主页 / 发表的主题 / 发表的回复 / 我的收藏 / 账号设置 共用
 *
 * 传入：$profile（用户行，需含 group_name 与计数字段；其余信息从行内兜底）
 */

declare(strict_types=1);

$hero       = is_array($profile ?? null) ? $profile : [];
$heroGroup  = (string)($hero['group_name'] ?? '游客');
$heroBio    = trim((string)($hero['bio'] ?? ''));
$joined     = (int)($hero['created_at'] ?? 0);
$lastActive = (int)($hero['last_active_at'] ?? 0);

$heroStats = [
    'threads'   => (int)($hero['thread_count'] ?? 0),
    'posts'     => (int)($hero['post_count'] ?? 0),
    'favorites' => (int)($hero['favorite_count'] ?? 0),
    'points'    => (int)($hero['points'] ?? 0),
];
?>

<section class="profile-hero">
    <img class="profile-hero__avatar" src="<?= e(avatar_url($hero, 86)) ?>"
         width="86" height="86" alt="<?= e((string)($hero['username'] ?? '')) ?> 的头像">

    <div class="profile-hero__main">
        <?php /* 用户名与用户组标签同一行 */ ?>
        <div class="profile-hero__name-row">
            <h1 class="profile-hero__name"><?= e((string)($hero['username'] ?? '已注销用户')) ?></h1>
            <span class="badge" style="background:rgb(255 255 255 / 0.22);color:#fff"><?= e($heroGroup) ?></span>
        </div>

        <div class="hstack gap-2" style="margin-top:4px">
            <span style="font-size:13px;opacity:.92">注册于 <?= e($joined > 0 ? date('Y-m-d', $joined) : '—') ?></span>
            <span style="font-size:13px;opacity:.92">最后活跃 <?= e(human_time($lastActive)) ?></span>
        </div>

        <?php if ($heroBio !== ''): ?>
            <p class="profile-hero__bio"><?= nl2br(e($heroBio)) ?></p>
        <?php endif; ?>

        <div class="profile-stats">
            <div><strong><?= format_number($heroStats['threads']) ?></strong>主题</div>
            <div><strong><?= format_number($heroStats['posts']) ?></strong>回复</div>
            <div><strong><?= format_number($heroStats['favorites']) ?></strong>收藏</div>
            <div><strong><?= format_number($heroStats['points']) ?></strong>积分</div>
        </div>
    </div>
</section>

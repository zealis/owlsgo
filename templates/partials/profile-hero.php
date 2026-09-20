<?php
/**
 * 用户中心个人名片 —— 个人主页 / 发表的帖子 / 发表的评论 / 我的收藏 / 账号设置 共用
 *
 * 传入：$profile（用户行，需含 group_name；简介 bio 可选）
 */

declare(strict_types=1);

$hero      = is_array($profile ?? null) ? $profile : [];
$heroGroup = (string)($hero['group_name'] ?? '游客');
$heroBio   = trim((string)($hero['bio'] ?? ''));
?>

<section class="profile-hero">
    <img class="profile-hero__avatar" src="<?= e(avatar_url($hero, 86)) ?>"
         width="86" height="86" alt="<?= e((string)($hero['username'] ?? '')) ?> 的头像">

    <div class="profile-hero__main">
        <?php /* 用户名与用户组标签同一行 */ ?>
        <div class="profile-hero__name-row">
            <h1 class="profile-hero__name"><?= e((string)($hero['username'] ?? '用户已删除')) ?></h1>
            <span class="badge" style="background:rgb(255 255 255 / 0.22);color:#fff"><?= e($heroGroup) ?></span>
        </div>

        <?php if ($heroBio !== ''): ?>
            <p class="profile-hero__bio"><?= nl2br(e($heroBio)) ?></p>
        <?php endif; ?>
    </div>
</section>
